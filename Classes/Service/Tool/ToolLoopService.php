<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
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
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Bounded function-calling agent loop over a DB-backed {@see LlmConfiguration}.
 *
 * Each round calls {@see LlmServiceManagerInterface::chatWithToolsForConfiguration()}
 * (so the run uses the configuration's vault key, model, and pricing — the
 * provider-key path of `chatWithTools()` cannot reach those). When the model
 * answers with tool calls, they are executed in PHP, appended back as typed
 * {@see ChatMessage} assistant + tool turns, and the conversation is re-sent — bounded by a
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
final readonly class ToolLoopService implements ToolLoopServiceInterface
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
        // The per-configuration skill and tool-group gate. Optional so the lean
        // test wiring keeps working; absent it the run is gated by the global
        // enablement and admin filters alone, exactly as before.
        private ?AllowedToolsResolver $allowedTools = null,
        // The composite gate (ADR-094). When wired it is the single authority;
        // absent it the loop falls back to the gates it applied before, which
        // is what the lean unit-test wiring exercises.
        private ?ToolCallPolicyInterface $toolPolicy = null,
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
        bool $skipAssembly = false,
        // Counters carried over from a suspended run (ADR-084) so a run that
        // suspends more than once accumulates its totals instead of each segment
        // starting from zero; a re-suspend then persists the running total.
        int $seedIterations = 0,
        int $seedPromptTokens = 0,
        int $seedCompletionTokens = 0,
    ): ToolLoopResult {
        $max = $maxIterations ?? $this->defaultMaxIterations;

        // Assemble the outgoing prompt once, before the loop: configuration
        // skills inject into the tool path here (the loop is the sole caller of
        // chatWithToolsForConfiguration, so this closes the injection gap
        // without double-injecting — augmentMessages returns a new list and the
        // loop re-sends its own accumulating array). A RunAugmentation adds the
        // playground extras (forced skills/snippets, baked system prompt) and
        // the dry-run flag.
        //
        // On resume (ADR-084, skipAssembly) the transcript is already fully
        // assembled and carries the conversation, so re-assembling would double
        // the system prompt and skills.
        if ($skipAssembly) {
            $dryRun = false;
        } else {
            [$messages, $dryRun] = $this->assemble($messages, $configuration, $options, $augmentation);
        }

        if ($dryRun) {
            $runTrace?->recordAssembledMessages($messages);

            return new ToolLoopResult('', [], 0, false, UsageStatistics::fromTokens(0, 0));
        }

        $effective = $this->resolveOfferedNames($allowedToolNames, $configuration);
        $specs     = $this->registry->specs($effective);

        // No tools offered (an empty allow-list, or nothing registered): a tools
        // request with an empty `tools` array makes some providers (OpenAI) 400.
        // The design (§4.3) maps "no tools" to a single plain completion.
        if ($specs === []) {
            $runTrace?->recordRequest(1, $messages, []);
            $t0   = hrtime(true);
            $resp = $this->mgr->chatWithConfiguration($messages, $configuration, $this->budgetMetadata($options));
            $runTrace?->recordLlmCall(1, self::elapsedMs($t0), $resp);

            // Fold in any carried-over counters (a resume whose continuation has
            // no offered tools still ran this synthesis round on top of the
            // pre-suspend total) — otherwise the whole run is under-reported.
            return new ToolLoopResult(
                $resp->content,
                [],
                $seedIterations + 1,
                false,
                UsageStatistics::fromTokens(
                    $seedPromptTokens + $resp->usage->promptTokens,
                    $seedCompletionTokens + $resp->usage->completionTokens,
                ),
            );
        }

        // Enforce the offered set at execution time too: a model steered by
        // injected skill prose must not be able to call a registered-but-not-
        // offered tool.
        $allowedNames = array_map(static fn(ToolSpec $s): string => $s->name, $specs);

        $trace            = [];
        $promptTokens     = $seedPromptTokens;
        $completionTokens = $seedCompletionTokens;
        $iterations       = $seedIterations;

        try {
            for ($i = 0; $i < $max; $i++) {
                $iterations++;
                // Streamed BEFORE the provider call so the inspector shows the
                // outgoing request (and a waiting state) from second zero.
                $runTrace?->recordRequest($iterations, $messages, $allowedNames);
                $t0   = hrtime(true);
                $resp = $this->mgr->chatWithToolsForConfiguration($messages, $specs, $configuration, $options);
                $runTrace?->recordLlmCall($iterations, self::elapsedMs($t0), $resp);
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

                $messages[] = ChatMessage::assistantToolCalls($resp->toolCalls ?? [], $resp->content);

                // Human-in-the-loop (ADR-084): if any call in this turn opts into
                // approval, suspend BEFORE executing any of the turn's calls so a
                // multi-call turn stays consistent. Existing read-only tools never
                // implement the marker, so this loop is inert for them and the
                // synchronous path below is unchanged.
                foreach ($resp->toolCalls ?? [] as $call) {
                    // Fail-closed like invoke()/resolveOfferedNames(): only an
                    // OFFERED approval tool suspends. A registered-but-not-offered
                    // approval tool (a model steered by injected prose naming it)
                    // falls through to invoke(), which refuses it — no spurious
                    // pending-approval prompt for a tool the run never allowed.
                    if (in_array($call->name, $allowedNames, true)
                        && $this->registry->get($call->name) instanceof RequiresApprovalInterface) {
                        throw ToolApprovalRequiredException::fromState(new SuspendedRunState(
                            array_map(static fn(ChatMessage|array $m): array => $m instanceof ChatMessage ? $m->toArray() : $m, $messages),
                            array_map(static fn(ToolCall $c): array => $c->toArray(), $resp->toolCalls ?? []),
                            $iterations,
                            $promptTokens,
                            $completionTokens,
                            // Persist the run's constraints so resume re-applies the
                            // SAME allow-list and options instead of falling back to
                            // defaults (ADR-084).
                            $allowedToolNames,
                            $options?->toArray() ?? [],
                        ));
                    }
                }

                foreach ($resp->toolCalls ?? [] as $call) {
                    $tt0                = hrtime(true);
                    [$result, $isError] = $this->invoke($call, $allowedNames);
                    $messages[]         = ChatMessage::toolResult($call->id, $result);
                    $trace[] = new ToolInvocation($call->name, $call->arguments, $result, $isError);
                    $runTrace?->recordToolExecution($iterations, self::elapsedMs($tt0), $call->name, $call->arguments, $result, $isError);
                }
            }

            // Cap hit with tools still pending: synthesise a closing answer with
            // NO tools. A plain completion yields a real finalContent uniformly
            // across OpenAI, Claude and Ollama — unlike toolChoice='none' or an
            // empty tools array (see design §4.3).
            // Record the synthesis as its own round (after the last tool round)
            // so the inspector's step list does not show two steps sharing a
            // round number.
            $runTrace?->recordRequest($iterations + 1, $messages, []);
            $t0    = hrtime(true);
            $final = $this->mgr->chatWithConfiguration(
                $messages,
                $configuration,
                $this->budgetMetadata($options),
            );
            $runTrace?->recordLlmCall($iterations + 1, self::elapsedMs($t0), $final);
            $promptTokens     += $final->usage->promptTokens;
            $completionTokens += $final->usage->completionTokens;

            return new ToolLoopResult(
                $final->content,
                $trace,
                $iterations,
                true,
                UsageStatistics::fromTokens($promptTokens, $completionTokens),
                AgentRunTerminationReason::MAX_ITERATIONS,
            );
        } catch (BudgetExceededException $e) {
            // Budget fires pre-flight and tools are read-only, so the partial
            // trace is consistent. Surface what ran rather than aborting, and
            // carry the reason on the result so a budget stop is distinguishable
            // from an iteration cap — both truncate (ADR-092).
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
                AgentRunTerminationReason::BUDGET_EXHAUSTED,
            );
        }
    }

    /**
     * Resume a run suspended for human approval (ADR-084).
     *
     * Restores the run's original allow-list and options from the suspended state
     * (so the continuation keeps the same constraints, not defaults), then
     * executes the pending turn's calls when $approved — appending each tool
     * result to the restored transcript — and re-enters {@see self::runLoop()}
     * with assembly skipped (the transcript already carries the system prompt and
     * skills). When not approved, a denial result is appended for each pending
     * call; the model then continues from the refusal.
     *
     * The gate is re-applied at resume time: a pending call whose tool has since
     * been disabled or become admin-only is NOT executed even when approved
     * (fail-closed). The pre-suspend iteration and token counters are folded into
     * the returned result so the totals span the whole run.
     */
    public function resume(
        SuspendedRunState $state,
        bool $approved,
        LlmConfiguration $configuration,
        ?int $maxIterations = null,
        ?RunTrace $runTrace = null,
        ?int $beUserUid = null,
    ): ToolLoopResult {
        $messages     = $state->messages;
        $pendingCalls = $state->toolCalls();
        // Restore the run's options and re-inject the acting user's uid so the
        // resumed continuation is budget-checked — the uid is intentionally not
        // part of the persisted options (ADR-084).
        $options = ToolOptions::fromArray($state->options, $beUserUid);
        // Re-apply the gate NOW (a tool may have been disabled or restricted while
        // the run was suspended) rather than trusting the names captured at
        // suspend time.
        $offered = $this->resolveOfferedNames($state->allowedToolNames, $configuration);

        foreach ($pendingCalls as $call) {
            if (!$approved) {
                $result = sprintf('Error: tool "%s" was denied by the operator.', $call->name);
                $runTrace?->recordToolExecution($state->iterations, 0.0, $call->name, $call->arguments, $result, true);
            } elseif (!in_array($call->name, $offered, true)) {
                $result = sprintf('Error: tool "%s" is no longer permitted and was not executed.', $call->name);
                $runTrace?->recordToolExecution($state->iterations, 0.0, $call->name, $call->arguments, $result, true);
            } else {
                $tt0                = hrtime(true);
                [$result, $isError] = $this->invoke($call, $offered);
                $runTrace?->recordToolExecution($state->iterations, self::elapsedMs($tt0), $call->name, $call->arguments, $result, $isError);
            }
            $messages[] = ChatMessage::toolResult($call->id, $result);
        }

        // Seed the loop with the pre-suspend counters so the returned totals span
        // the whole run — and a further suspend inside the continuation persists
        // the running total, not just its own segment (ADR-084).
        return $this->runLoop(
            $messages,
            $configuration,
            $state->allowedToolNames,
            $options,
            $maxIterations,
            $runTrace,
            null,
            true,
            $state->iterations,
            $state->promptTokens,
            $state->completionTokens,
        );
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
     *
     * @return array{0: string, 1: bool} [result, isError]
     */
    /**
     * Resolve the tool names offered for a run: the caller's per-run allow-list
     * intersected with the globally-enabled set, then admin-only tools filtered
     * out unless the acting backend user is an admin. Both gates are fail-closed.
     * Reused by {@see self::resume()} so a resume re-applies the gate at approval
     * time — a tool disabled or restricted while suspended is not executed.
     *
     * @param list<string>|null $allowedToolNames null ⇒ the globally-enabled set;
     *                                            a list ⇒ that set ∩ enabled;
     *                                            `[]` ⇒ no tools
     *
     * @return list<string>
     */
    private function resolveOfferedNames(?array $allowedToolNames, LlmConfiguration $configuration): array
    {
        if ($this->toolPolicy !== null) {
            $user = $this->actingBackendUser();

            foreach ($this->toolPolicy->explain($allowedToolNames, $configuration, $user) as $decision) {
                if (!$decision->allowed || $decision->observedOnly) {
                    $this->logger?->info('Tool gate: ' . $decision->message(), [
                        'tool'   => $decision->toolName,
                        'reason' => $decision->reason->value,
                        'zone'   => $decision->zone->value,
                    ]);
                }
            }

            return $this->toolPolicy->filterOfferable($allowedToolNames, $configuration, $user);
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

        // Fail-closed per-configuration gate: the skills' declared allow-list
        // intersected with the configuration's allowed_tool_groups. This lived
        // in the tool playground until ADR-093, which meant every other consumer
        // of the now-public ToolLoopServiceInterface — a scheduler task, a
        // downstream extension — bypassed the configuration's own restriction
        // entirely. The caller's list is a request; this is the grant.
        $configurationAllowed = $this->allowedTools?->resolve($configuration);
        if ($configurationAllowed !== null) {
            $effective = array_values(array_intersect($effective, $configurationAllowed));
        }

        return $effective;
    }

    /**
     * @param list<string> $allowedNames
     *
     * @return array{string, bool} the tool result and whether it is an error
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
        // Tool output is untrusted bytes (logs, phpinfo, env, DB rows) and may
        // not be valid UTF-8. It is appended to the message list and re-encoded
        // as JSON on the next provider request — and serialised into the
        // inspector trace — where a single invalid byte makes json_encode()
        // throw a JsonException ("Malformed UTF-8"). Coerce to valid UTF-8 first
        // so neither path can crash on a misbehaving tool.
        $result = $this->toValidUtf8($result);

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
     * Coerce a byte string to valid UTF-8, replacing invalid sequences (the
     * substitution is visible in the inspector rather than silently dropped).
     * A no-op for already-valid input.
     */
    private function toValidUtf8(string $result): string
    {
        if (mb_check_encoding($result, 'UTF-8')) {
            return $result;
        }

        // Cast defends the string return type: mb_convert_encoding is typed
        // string|false, and false (unreachable for the literal 'UTF-8' names)
        // would otherwise be a TypeError.
        return (string)mb_convert_encoding($result, 'UTF-8', 'UTF-8');
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
