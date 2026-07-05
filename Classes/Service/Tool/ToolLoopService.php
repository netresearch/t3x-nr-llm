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
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
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

    /**
     * Hard cap on a single tool result appended to the message list. A buggy or
     * malicious tool returning multi-megabyte output would otherwise blow the
     * provider payload limit, bypass the token budget and pressure memory.
     */
    private const MAX_TOOL_RESULT_BYTES = 50000;

    public function __construct(
        private LlmServiceManagerInterface $mgr,
        private ToolRegistry $registry,
        private ToolAvailabilityServiceInterface $availability,
        private ?LoggerInterface $logger = null,
        private int $defaultMaxIterations = 5,
        // Optional collaborators (autowired in production), mirroring the
        // optional SkillInjectionService on LlmServiceManager. Absent them the
        // loop simply skips prompt augmentation — the production tool path and
        // the existing lean test wiring keep working unchanged.
        private ?SkillInjectionService $skillInjection = null,
        private ?PromptSnippetComposer $snippetComposer = null,
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
        ?RunTrace $runTrace = null,
        ?RunAugmentation $augmentation = null,
    ): ToolLoopResult {
        $max = $maxIterations ?? $this->defaultMaxIterations;

        // Assemble the outgoing prompt once, before the loop: configuration
        // skills inject into the tool path here (the loop is the sole caller of
        // chatWithToolsForConfiguration, so this closes the injection gap
        // without double-injecting — augmentMessages returns a new list and the
        // loop re-sends its own accumulating array). A RunAugmentation adds the
        // playground extras (forced skills/snippets, baked system prompt) and
        // the dry-run flag.
        [$messages, $dryRun] = $this->assemble($messages, $configuration, $options, $augmentation);

        if ($dryRun) {
            $runTrace?->recordAssembledMessages($messages);

            return new ToolLoopResult('', [], 0, false, UsageStatistics::fromTokens(0, 0));
        }

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
            $t0   = hrtime(true);
            $resp = $this->mgr->chatWithConfiguration($messages, $configuration, $this->budgetMetadata($options));
            $runTrace?->recordLlmCall(1, self::elapsedMs($t0), $messages, [], $resp);

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
                $t0   = hrtime(true);
                $resp = $this->mgr->chatWithToolsForConfiguration($messages, $specs, $configuration, $options);
                $runTrace?->recordLlmCall($iterations, self::elapsedMs($t0), $messages, $allowedNames, $resp);
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
                    $tt0                = hrtime(true);
                    [$result, $isError] = $this->invoke($call, $allowedNames);
                    $messages[]         = [
                        'role'         => 'tool',
                        'tool_call_id' => $call->id,
                        'content'      => $result,
                    ];
                    $trace[] = new ToolInvocation($call->name, $call->arguments, $result, $isError);
                    $runTrace?->recordToolExecution($iterations, self::elapsedMs($tt0), $call->name, $call->arguments, $result, $isError);
                }
            }

            // Cap hit with tools still pending: synthesise a closing answer with
            // NO tools. A plain completion yields a real finalContent uniformly
            // across OpenAI, Claude and Ollama — unlike toolChoice='none' or an
            // empty tools array (see design §4.3).
            $t0    = hrtime(true);
            $final = $this->mgr->chatWithConfiguration(
                $messages,
                $configuration,
                $this->budgetMetadata($options),
            );
            $runTrace?->recordLlmCall($iterations, self::elapsedMs($t0), $messages, [], $final);
            $promptTokens     += $final->usage->promptTokens;
            $completionTokens += $final->usage->completionTokens;

            return new ToolLoopResult(
                $final->content,
                $trace,
                $iterations,
                true,
                UsageStatistics::fromTokens($promptTokens, $completionTokens),
            );
        } catch (BudgetExceededException $e) {
            // Budget fires pre-flight and tools are read-only, so the partial
            // trace is consistent. Surface what ran rather than aborting — but
            // log the denial (with the tripped bucket) so operators can tell a
            // budget stop from an iteration cap, since both surface as
            // truncated=true with an empty final answer.
            $this->logger?->warning(
                'Tool loop stopped: budget pre-flight denied the call.',
                ['exception' => $e],
            );

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
     * Assemble the outgoing messages once before the loop.
     *
     * Configuration skills are injected on every run — this is the tool-path
     * injection fix (previously the loop never applied skill prose). A
     * {@see RunAugmentation} additionally bakes the effective system prompt
     * (a per-run override wins over the configuration's), the forced snippet
     * system messages and the forced skills, and carries the dry-run flag.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return array{0: list<ChatMessage|array<string, mixed>>, 1: bool} [assembled messages, dryRun]
     */
    private function assemble(
        array $messages,
        LlmConfiguration $configuration,
        ?ToolOptions $options,
        ?RunAugmentation $augmentation,
    ): array {
        $configSkills = SkillInjectionService::toList($configuration->getSkills());
        $forcedSkills = $augmentation !== null ? $augmentation->forcedSkills : [];
        $messages     = $this->skillInjection?->augmentMessages($messages, $configSkills, $forcedSkills) ?? $messages;

        if ($augmentation === null) {
            return [$messages, false];
        }

        $lead = [];

        // Bake the effective system prompt as the first message. Without this,
        // the snippet system messages below would satisfy the manager's "a
        // system message already exists" guard and suppress the configuration
        // system prompt for the run.
        $override = $options?->getSystemPrompt() ?? '';
        $system   = $override !== '' ? $override : $configuration->getSystemPrompt();
        if ($system !== '') {
            $lead[] = ChatMessage::system($system);
        }

        foreach ($augmentation->forcedSnippets as $snippet) {
            $text = $this->snippetComposer?->composeSections([$snippet->getName() => $snippet]) ?? '';
            if ($text !== '') {
                $lead[] = ChatMessage::system($text);
            }
        }

        if ($lead !== []) {
            $messages = array_values(array_merge($lead, $messages));
        }

        return [$messages, $augmentation->dryRun];
    }

    private static function elapsedMs(int $startNs): float
    {
        return (hrtime(true) - $startNs) / 1_000_000;
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
            return [$this->capResult($tool->execute($call->arguments)), false];
        } catch (Throwable $e) {
            // Keep the logged summary generic — the exception body may embed
            // DBAL/PDO credentials that URL-sanitising would not strip. The full
            // Throwable (message + trace) is preserved in the log context for
            // server-side forensics.
            $this->logger?->error(
                sprintf('Tool "%s" failed.', $call->name),
                ['exception' => $e],
            );

            return [sprintf('Error: tool "%s" failed.', $call->name), true];
        }
    }

    /**
     * Bound a tool result to {@see self::MAX_TOOL_RESULT_BYTES}. Uses mb_strcut
     * so the byte cap never splits a multibyte character (which would corrupt
     * the later JSON encoding), and appends a visible truncation marker.
     */
    private function capResult(string $result): string
    {
        if (strlen($result) <= self::MAX_TOOL_RESULT_BYTES) {
            return $result;
        }

        // Reserve the marker's bytes from the budget so the returned string
        // (content + marker) never exceeds the cap. mb_strcut with an explicit
        // UTF-8 encoding cuts on a byte boundary without splitting a character.
        $marker = "\n…[tool result truncated at " . self::MAX_TOOL_RESULT_BYTES . ' bytes]';
        $budget = self::MAX_TOOL_RESULT_BYTES - strlen($marker);

        return mb_strcut($result, 0, max(0, $budget), 'UTF-8') . $marker;
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
