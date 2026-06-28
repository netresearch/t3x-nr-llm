<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocation;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use stdClass;
use Throwable;

/**
 * Bounded function-calling agent loop over a DB-backed {@see LlmConfiguration}.
 *
 * Each round calls {@see LlmServiceManagerInterface::chatWithToolsForConfiguration()}
 * (so the run uses the configuration's vault key, model, and pricing — the
 * provider-key path of `chatWithTools()` cannot reach those). When the model
 * answers with tool calls, they are executed in PHP, appended back as raw
 * assistant + tool turns, and the conversation is re-sent — bounded by a
 * configurable max-iteration cap.
 *
 * Failure handling is fail-soft so the admin always sees what ran:
 * - a tool that throws, or an unknown/disallowed tool name, becomes a sanitised
 *   error tool-result and the loop continues;
 * - hitting the iteration cap with tools still pending triggers one final plain
 *   completion via {@see LlmServiceManagerInterface::chatWithConfiguration()}
 *   (no tools at all) to synthesise a closing answer, marking the result
 *   truncated;
 * - a mid-loop {@see BudgetExceededException} returns the partial result
 *   gathered so far (tools are read-only, so the state stays consistent).
 *
 * Token usage is summed across every round-trip (including the synthesis call);
 * per-iteration monetary cost is recorded downstream by the middleware pipeline.
 */
final readonly class ToolLoopService
{
    use ErrorMessageSanitizerTrait;

    public function __construct(
        private LlmServiceManagerInterface $mgr,
        private ToolRegistry $registry,
        private int $defaultMaxIterations = 5,
    ) {}

    /**
     * Run the bounded agent loop and return its outcome.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<string>|null                      $allowedToolNames null ⇒ all
     *                                                                 registered
     *                                                                 tools; a
     *                                                                 list ⇒ that
     *                                                                 set (∩
     *                                                                 registry);
     *                                                                 `[]` ⇒ no
     *                                                                 tools
     */
    public function runLoop(
        array $messages,
        LlmConfiguration $configuration,
        ?array $allowedToolNames,
        ?ToolOptions $options = null,
        ?int $maxIterations = null,
    ): ToolLoopResult {
        $max   = $maxIterations ?? $this->defaultMaxIterations;
        $specs = $this->registry->specs($allowedToolNames);

        $trace            = [];
        $promptTokens     = 0;
        $completionTokens = 0;
        $iterations       = 0;

        try {
            for ($i = 0; $i < $max; $i++) {
                $iterations++;
                $resp = $this->mgr->chatWithToolsForConfiguration($messages, $specs, $configuration, $options);
                $promptTokens     += $resp->usage->promptTokens;
                $completionTokens += $resp->usage->completionTokens;

                if (!$resp->hasToolCalls()) {
                    return new ToolLoopResult(
                        $resp->content,
                        $trace,
                        $iterations,
                        false,
                        UsageStatistics::fromTokens($promptTokens, $completionTokens),
                    );
                }

                $messages[] = $this->assistantTurn($resp);
                foreach ($resp->toolCalls ?? [] as $call) {
                    [$result, $isError] = $this->invoke($call);
                    $messages[]         = [
                        'role'         => 'tool',
                        'tool_call_id' => $call->id,
                        'content'      => $result,
                    ];
                    $trace[] = new ToolInvocation($call->name, $call->arguments, $result, $isError);
                }
            }

            // Cap hit with tools still pending: synthesise a closing answer with
            // NO tools. A plain completion yields a real finalContent uniformly
            // across OpenAI, Claude and Ollama — unlike toolChoice='none' or an
            // empty tools array (see design §4.3).
            $final = $this->mgr->chatWithConfiguration(
                $messages,
                $configuration,
                $this->budgetMetadata($options),
            );
            $promptTokens     += $final->usage->promptTokens;
            $completionTokens += $final->usage->completionTokens;

            return new ToolLoopResult(
                $final->content,
                $trace,
                $iterations,
                true,
                UsageStatistics::fromTokens($promptTokens, $completionTokens),
            );
        } catch (BudgetExceededException) {
            // Budget fires pre-flight and tools are read-only, so the partial
            // trace is consistent. Surface what ran rather than aborting.
            return new ToolLoopResult(
                '',
                $trace,
                $iterations,
                true,
                UsageStatistics::fromTokens($promptTokens, $completionTokens),
            );
        }
    }

    /**
     * Build the raw OpenAI-compatible assistant turn carrying the model's tool
     * calls. Empty arguments serialise to `{}` (an object), never `[]`.
     *
     * @return array{
     *     role: string,
     *     content: string,
     *     tool_calls: list<array{id: string, type: string, function: array{name: string, arguments: string}}>,
     * }
     */
    private function assistantTurn(CompletionResponse $resp): array
    {
        $calls = array_map(
            static fn(ToolCall $c): array => [
                'id'       => $c->id,
                'type'     => 'function',
                'function' => [
                    'name'      => $c->name,
                    'arguments' => json_encode($c->arguments !== [] ? $c->arguments : new stdClass(), JSON_THROW_ON_ERROR),
                ],
            ],
            $resp->toolCalls ?? [],
        );

        return [
            'role'       => 'assistant',
            'content'    => $resp->content,
            'tool_calls' => $calls,
        ];
    }

    /**
     * Resolve and execute a single tool call. A missing tool or a thrown
     * exception becomes a sanitised error result (`isError = true`) so the loop
     * can continue instead of aborting or leaking internals.
     *
     * @return array{0: string, 1: bool} [result, isError]
     */
    private function invoke(ToolCall $call): array
    {
        $tool = $this->registry->get($call->name);
        if ($tool === null) {
            return [sprintf('Error: unknown tool "%s"', $call->name), true];
        }

        try {
            return [$tool->execute($call->arguments), false];
        } catch (Throwable $e) {
            return ['Error: ' . $this->sanitizeErrorMessage($e->getMessage()), true];
        }
    }

    /**
     * Forward the budget pre-flight context (BE-user uid, planned cost) onto the
     * synthesis completion so the cap-hit final call stays budget-gated and
     * cost-attributed, matching the per-iteration tool calls.
     *
     * @return array<string, mixed>
     */
    private function budgetMetadata(?ToolOptions $options): array
    {
        if ($options === null) {
            return [];
        }

        $metadata  = [];
        $beUserUid = $options->getBeUserUid();
        if ($beUserUid !== null) {
            $metadata[BudgetMiddleware::METADATA_BE_USER_UID] = $beUserUid;
        }

        $plannedCost = $options->getPlannedCost();
        if ($plannedCost !== null) {
            $metadata[BudgetMiddleware::METADATA_PLANNED_COST] = $plannedCost;
        }

        return $metadata;
    }
}
