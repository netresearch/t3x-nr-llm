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
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\Builtin\ResolvesActingBackendUserTrait;
use Psr\Log\LoggerInterface;
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
 * - a tool that throws, or an unknown/disallowed tool name, becomes a generic
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
    use ResolvesActingBackendUserTrait;

    public function __construct(
        private LlmServiceManagerInterface $mgr,
        private ToolRegistry $registry,
        private ToolAvailabilityServiceInterface $availability,
        private ?LoggerInterface $logger = null,
        private int $defaultMaxIterations = 5,
    ) {}

    /**
     * Run the bounded agent loop and return its outcome.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<string>|null                      $allowedToolNames null ⇒ the
     *                                                                 globally-
     *                                                                 enabled set;
     *                                                                 a list ⇒ that
     *                                                                 set ∩ enabled;
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
        $max = $maxIterations ?? $this->defaultMaxIterations;

        // Fail-closed global gate: the effective allow-set is always intersected
        // with the globally-enabled tools. A null caller list means "no per-run
        // restriction" and collapses to the enabled set (NOT every registered
        // tool); a disabled tool can therefore never be offered, even when a
        // caller — or a model steered by injected skill prose — names it.
        $enabled   = $this->availability->enabledNames();
        $effective = $allowedToolNames === null
            ? $enabled
            : array_values(array_intersect($allowedToolNames, $enabled));

        // Fail-closed RBAC gate: admin-only tools (logs, environment, phpinfo,
        // backend user/group listings) are never offered unless the ACTING
        // backend user is an admin — enforced here in the runtime, not just the
        // (admin-only) playground, so the public service cannot be invoked on a
        // non-admin's behalf to reach them. An unknown tool name is treated as
        // admin-only (fail-closed).
        if (!$this->actingUserIsAdmin()) {
            $effective = array_values(array_filter(
                $effective,
                fn(string $name): bool => $this->registry->get($name)?->requiresAdmin() === false,
            ));
        }

        $specs = $this->registry->specs($effective);

        // No tools offered (an empty allow-list, or nothing registered): a tools
        // request with an empty `tools` array makes some providers (OpenAI) 400.
        // The design (§4.3) maps "no tools" to a single plain completion.
        if ($specs === []) {
            $resp = $this->mgr->chatWithConfiguration($messages, $configuration, $this->budgetMetadata($options));

            return new ToolLoopResult(
                $resp->content,
                [],
                1,
                false,
                UsageStatistics::fromTokens($resp->usage->promptTokens, $resp->usage->completionTokens),
            );
        }

        // Enforce the offered set at execution time too: a model steered by
        // injected skill prose must not be able to call a registered-but-not-
        // offered tool.
        $allowedNames = array_map(static fn(ToolSpec $s): string => $s->name, $specs);

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
                    [$result, $isError] = $this->invoke($call, $allowedNames);
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
     * Resolve and execute a single tool call. A missing tool, a tool not in the
     * offered allow-list, or a thrown exception becomes a generic error result
     * (`isError = true`) so the loop can continue instead of aborting or leaking
     * internals.
     *
     * The thrown-exception branch returns a generic message rather than the
     * exception text: the tool name is a known registered name (safe), but the
     * exception body may carry DBAL/PDO credentials ('Access denied for user
     * X@host') that URL-sanitising would not strip, so it must never reach the
     * provider.
     *
     * @param list<string> $allowedNames Names of the tools actually offered this
     *                                   run; a call to any other registered tool
     *                                   is rejected unexecuted.
     *
     * @return array{0: string, 1: bool} [result, isError]
     */
    private function invoke(ToolCall $call, array $allowedNames): array
    {
        $tool = $this->registry->get($call->name);
        if ($tool === null) {
            return [sprintf('Error: unknown tool "%s"', $call->name), true];
        }
        if (!in_array($call->name, $allowedNames, true)) {
            return [sprintf('Error: tool "%s" not permitted', $call->name), true];
        }

        try {
            return [$tool->execute($call->arguments), false];
        } catch (Throwable $e) {
            $this->logger?->error(
                sprintf('Tool "%s" failed: %s', $call->name, $e->getMessage()),
                ['exception' => $e],
            );

            return [sprintf('Error: tool "%s" failed.', $call->name), true];
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
