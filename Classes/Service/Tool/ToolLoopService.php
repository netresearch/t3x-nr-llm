<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use LogicException;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocation;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\ContextTruncatedException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Service\Context\ContextWindowManagerInterface;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
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
    public function __construct(
        private LlmServiceManagerInterface $mgr,
        private ToolRegistry $registry,
        // The composite gate (ADR-094): the single authority on which tools a
        // run may be offered. Required — an absent gate used to fall through to
        // a narrower legacy chain with no trust-zone axis, which is a weaker
        // gate that nothing made visible (ADR-120).
        private ToolCallPolicyInterface $toolPolicy,
        private ?LoggerInterface $logger = null,
        private int $defaultMaxIterations = 5,
        // Optional collaborators (autowired in production), mirroring the
        // optional SkillInjectionService on LlmServiceManager. Absent them the
        // loop simply skips prompt augmentation — the production tool path and
        // the existing lean test wiring keep working unchanged.
        private ?SkillInjectionService $skillInjection = null,
        private ?PromptSnippetComposer $snippetComposer = null,
        // Validates a user's typed input against a tool's declared schema
        // (ADR-105). Defaulted rather than optional: the validator is stateless
        // and has no constructor, so there is no wiring under which the
        // defence-in-depth re-validation can be absent.
        private JsonSchemaValidator $schemaValidator = new JsonSchemaValidator(),
        // Bounds the growing transcript against the model's context window
        // (ADR-107). Optional so the lean test wiring is unchanged; absent it the
        // loop sends the full transcript exactly as before, and every
        // enforcement site below is a no-op.
        private ?ContextWindowManagerInterface $contextWindow = null,
        // Records tool-gate denials so "tool denials by reason / by tool" become
        // queryable. Optional because it is an observability sink, not a gate:
        // absent it the denial is still decided and still logged, it just does
        // not become a queryable row.
        private ?GovernanceEventRepositoryInterface $governanceEvents = null,
        // Bounds every tool result before it leaves the loop. Defaulted rather
        // than optional, like the schema validator above: the bounder is
        // stateless and has no constructor, so there is no wiring under which
        // the byte caps can be absent (ADR-120's pattern).
        private ToolResultBounder $bounder = new ToolResultBounder(),
        // Composes the configuration's tag-selected snippets (ADR-031) into the
        // system prompt this service bakes for an augmented run, and into the
        // prompt size the context-window estimate budgets for. Must be the same
        // value the manager's planner composes, or the addition would be missing
        // in exactly the place that inspects it — the playground — and the
        // budget would be short by the snippet block on every production run.
        private ?ConfigurationSnippetResolver $snippetResolver = null,
        // Re-load the run's forced sources on resume so the ADR-164 ceiling
        // still sees them (ADR-165). Optional like the collaborators above: a
        // construction without them keeps the pre-ADR-165 behaviour, where a
        // resumed send was not re-gated against the forced set.
        private ?PromptSnippetRepository $promptSnippetRepository = null,
        private ?SkillRepository $skillRepository = null,
    ) {}

    /**
     * The uids of a forced source list, for the suspend state (ADR-165).
     *
     * A record with no uid is not persistable and is dropped: it cannot be
     * re-loaded on resume, so writing a placeholder would only produce a
     * lookup that answers nothing.
     *
     * @param list<PromptSnippet>|list<Skill> $records
     *
     * @return list<int>
     */
    private function uidsOf(array $records): array
    {
        $uids = [];
        foreach ($records as $record) {
            $uid = $record->getUid();
            if ($uid !== null && $uid > 0) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    /**
     * The run's forced set, re-loaded from the uids the suspend persisted
     * (ADR-165), or null when the run forced nothing.
     *
     * Null rather than an empty augmentation, so the send path keeps passing
     * null exactly as an ordinary run does — the gate then takes its
     * configuration-only path rather than folding an empty list.
     *
     * A uid that no longer resolves — the snippet was deleted while the run was
     * suspended — contributes nothing. The transcript still carries its text,
     * so this degrades to the pre-ADR-165 answer for that one source rather
     * than refusing a resume over a record that is gone.
     *
     * A source merely DEACTIVATED while the run was suspended does not degrade
     * that way (ADR-166), which is why the snippet lookup is
     * {@see PromptSnippetRepository::findExistingByUids()} and not the
     * active-only {@see PromptSnippetRepository::findByUids()}. Its text is
     * already in the transcript and
     * still goes on the wire, so dropping it here would lower the ADR-164
     * ceiling for content that is still being sent. "Inactive" bars a snippet
     * from new composition; it does not un-classify text already injected.
     *
     * The skill half is the same shape and uses
     * {@see SkillRepository::findExistingByUids()} for the same reason. It also
     * fixes an ordering divergence: this path used to iterate
     * {@see SkillRepository::findAll()} and so returned name order, while the
     * two composition paths returned the order the run was started with. On an
     * equal data class the later source in the fold wins, so the same run
     * blamed one skill before it suspended and another after it resumed
     * (ADR-175).
     */
    private function augmentationFrom(SuspendedRunState $state): ?RunAugmentation
    {
        if ($state->forcedSnippetUids === [] && $state->forcedSkillUids === []) {
            return null;
        }

        $snippets = $state->forcedSnippetUids !== [] && $this->promptSnippetRepository instanceof PromptSnippetRepository
            ? $this->promptSnippetRepository->findExistingByUids($state->forcedSnippetUids)
            : [];

        $skills = $state->forcedSkillUids !== [] && $this->skillRepository instanceof SkillRepository
            ? $this->skillRepository->findExistingByUids($state->forcedSkillUids)
            : [];

        if ($snippets === [] && $skills === []) {
            return null;
        }

        return new RunAugmentation(forcedSkills: $skills, forcedSnippets: $snippets);
    }

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
        ToolExecutionContext $context,
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
        // Created HERE, per run, and passed down: this service is a container
        // singleton and the queue worker outlives many runs, so a counter held
        // anywhere but a local would bound the process instead (ADR-116).
        $remoteCalls = new RemoteCallBudget();

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
            // Before anything is assembled: what the run asked for and did not
            // get (ADR-179). Recorded on the resolve side rather than inferred
            // from the assembled messages, because a source that never arrived
            // leaves no trace in them — that absence is exactly what an
            // operator cannot see today.
            //
            // Not on the resume branch above: ADR-166 and ADR-175 keep a
            // deactivated source resolving there on purpose, so nothing is
            // dropped and a step would imply otherwise.
            if ($augmentation instanceof RunAugmentation) {
                $runTrace?->recordDroppedSources($augmentation->droppedSources);
            }

            [$messages, $dryRun] = $this->assemble($messages, $configuration, $options, $augmentation);
        }

        if ($dryRun) {
            $runTrace?->recordAssembledMessages($messages);

            return new ToolLoopResult('', [], 0, false, UsageStatistics::fromTokens(0, 0));
        }

        $effective = $this->resolveOfferedNames($allowedToolNames, $configuration, $context);
        $specs     = $this->registry->specs($effective);

        // No tools offered (an empty allow-list, or nothing registered): a tools
        // request with an empty `tools` array makes some providers (OpenAI) 400.
        // The design (§4.3) maps "no tools" to a single plain completion.
        if ($specs === []) {
            // Bound the transcript against the context window (ADR-107). A resume
            // continuation with no offered tools can still be over-long. No tools
            // go on this wire, so pass toolSpecs = [].
            try {
                $messages = $this->enforceContextWindow($messages, $configuration, $options, null, 1, [], $runTrace);
            } catch (ContextTruncatedException $e) {
                $this->logger?->warning('Agent loop stopped: transcript exceeds the context window even at its floor.', ['exception' => $e]);

                return $this->contextTruncatedResult([], $seedIterations + 1, $seedPromptTokens, $seedCompletionTokens);
            }

            $runTrace?->recordRequest(1, $messages, []);
            $t0   = hrtime(true);
            $resp = $this->mgr->chatWithConfiguration($messages, $configuration, $this->budgetMetadata($options), run: $context->run, injectedContext: $augmentation?->injectedContext());
            $runTrace?->recordLlmCall(1, $this->elapsedMs($t0), $resp);

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
        // The previous call's usage, fed back to calibrate the token estimator
        // (ADR-107); null before the first call.
        $lastUsage = null;

        try {
            for ($i = 0; $i < $max; $i++) {
                $iterations++;
                // Bound the growing transcript against the context window BEFORE
                // the send (ADR-107); tools are on this wire, so pass $specs.
                $messages = $this->enforceContextWindow(
                    $messages,
                    $configuration,
                    $options,
                    $lastUsage,
                    $iterations,
                    array_map(static fn(ToolSpec $s): array => $s->toArray(), $specs),
                    $runTrace,
                );
                // Streamed BEFORE the provider call so the inspector shows the
                // outgoing request (and a waiting state) from second zero.
                $runTrace?->recordRequest($iterations, $messages, $allowedNames);
                $t0   = hrtime(true);
                // The run travels with the call (ADR-153): every round of one run
                // lands on the run's correlation id instead of minting its own,
                // so its telemetry rows are attributable to the run afterwards.
                $resp = $this->mgr->chatWithToolsForConfiguration($messages, $specs, $configuration, $options, $context->run, $augmentation?->injectedContext());
                $runTrace?->recordLlmCall($iterations, $this->elapsedMs($t0), $resp);
                $lastUsage         = $resp->usage;
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

                // Human-in-the-loop (ADR-084/134): if any call in this turn needs
                // approval, suspend BEFORE executing any of the turn's calls so a
                // multi-call turn stays consistent. Existing read-only tools
                // neither carry the marker nor declare a write, so this loop is
                // inert for them and the synchronous path below is unchanged.
                foreach ($resp->toolCalls ?? [] as $call) {
                    // Fail-closed like invoke()/resolveOfferedNames(): only an
                    // OFFERED approval tool suspends. A registered-but-not-offered
                    // approval tool (a model steered by injected prose naming it)
                    // falls through to invoke(), which refuses it — no spurious
                    // pending-approval prompt for a tool the run never allowed.
                    // The predicate is ToolApprovalRule (ADR-084/134/157) —
                    // shared with ToolRegistry's boot validation, which rejects
                    // a tool that is approval-bound AND RequiresInputInterface
                    // because this scan runs before the input scan, and with
                    // the Governance simulation, which reports the requirement
                    // as its own axis. Narrowing the remote exemption is one
                    // edit rather than three kept in step by a comment.
                    if (in_array($call->name, $allowedNames, true)
                        && ToolApprovalRule::requiresApproval($this->registry->get($call->name))) {
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
                            $this->persistedRunOptions($options),
                            // What the turn WOULD do, captured here and not at
                            // approval time: this is the run's actor context, the
                            // only identity allowed to read the targets (ADR-136).
                            callPreviews: $this->previewsForTurn($resp->toolCalls ?? [], $allowedNames, $context),
                            // The forced set travels with the suspend so resume
                            // re-applies the ADR-164 ceiling to it (ADR-165).
                            forcedSnippetUids: $this->uidsOf($augmentation->forcedSnippets ?? []),
                            forcedSkillUids: $this->uidsOf($augmentation->forcedSkills ?? []),
                        ));
                    }
                }

                // Typed-input-in-the-loop (ADR-105): the input sibling of the
                // approval scan above. Approval keeps strict precedence (its
                // scan runs first); both suspend BEFORE any of the turn's calls
                // execute, so a multi-call turn stays consistent. Fail-closed
                // like the approval scan: only an OFFERED input tool pauses.
                foreach ($resp->toolCalls ?? [] as $call) {
                    $inputTool = $this->registry->get($call->name);
                    if (in_array($call->name, $allowedNames, true)
                        && $inputTool instanceof RequiresInputInterface) {
                        $schema = $inputTool->getInputSchema();
                        // Capture-time gate (ADR-105 M2): a RequiresInputInterface
                        // tool with a degenerate schema is a programming error;
                        // never persist a suspend that would rehydrate fail-open.
                        if (!InputSchema::isUsable($schema)) {
                            throw new LogicException(
                                sprintf('Tool "%s" implements RequiresInputInterface but returned a degenerate input schema.', $call->name),
                                1784600105,
                            );
                        }

                        throw ToolInputRequiredException::fromState(new SuspendedRunState(
                            array_map(static fn(ChatMessage|array $m): array => $m instanceof ChatMessage ? $m->toArray() : $m, $messages),
                            array_map(static fn(ToolCall $c): array => $c->toArray(), $resp->toolCalls ?? []),
                            $iterations,
                            $promptTokens,
                            $completionTokens,
                            $allowedToolNames,
                            $this->persistedRunOptions($options),
                            inputToolName: $call->name,
                            inputSchema: $schema,
                            forcedSnippetUids: $this->uidsOf($augmentation->forcedSnippets ?? []),
                            forcedSkillUids: $this->uidsOf($augmentation->forcedSkills ?? []),
                        ));
                    }
                }

                foreach ($resp->toolCalls ?? [] as $call) {
                    $tt0 = hrtime(true);
                    // Fence the operation before any side effect (ADR-111): the
                    // runtime records the tool's effect and renews the lease so a
                    // reap mid non-idempotent-write can refuse to retry it.
                    $runTrace?->beforeToolExecution($call->name);
                    $tr = $this->invoke($call, $allowedNames, $context, $remoteCalls);
                    // WIRE: content ONLY — artifacts are run-scoped and never egress to the provider.
                    $messages[] = ChatMessage::toolResult($call->id, $tr->content);
                    $trace[]    = new ToolInvocation($call->name, $call->arguments, $tr->content, $tr->isError, $tr->artifacts);
                    $runTrace?->recordToolResult($iterations, $this->elapsedMs($tt0), $call->name, $call->arguments, $tr);
                }
            }

            // Cap hit with tools still pending: synthesise a closing answer with
            // NO tools. A plain completion yields a real finalContent uniformly
            // across OpenAI, Claude and Ollama — unlike toolChoice='none' or an
            // empty tools array (see design §4.3).
            // Record the synthesis as its own round (after the last tool round)
            // so the inspector's step list does not show two steps sharing a
            // round number.
            // The synthesis is a plain completion (no tools on the wire), so
            // bound it with toolSpecs = [] (ADR-107) — counting phantom schema
            // bytes here, on the run's largest transcript, could otherwise
            // discard a real final answer as a spurious overflow.
            $messages = $this->enforceContextWindow($messages, $configuration, $options, $lastUsage, $iterations + 1, [], $runTrace);
            $runTrace?->recordRequest($iterations + 1, $messages, []);
            $t0    = hrtime(true);
            $final = $this->mgr->chatWithConfiguration(
                $messages,
                $configuration,
                $this->budgetMetadata($options),
                run: $context->run,
                injectedContext: $augmentation?->injectedContext(),
            );
            $runTrace?->recordLlmCall($iterations + 1, $this->elapsedMs($t0), $final);
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
        } catch (ContextTruncatedException $e) {
            // ADR-107: even the pruned floor exceeds the context window, so no
            // provider call was made. Stop legibly with the partial trace rather
            // than sending an oversized request and eating a raw provider 4xx.
            $this->logger?->warning(
                'Tool loop stopped: transcript exceeds the context window even at its floor.',
                ['exception' => $e],
            );

            return $this->contextTruncatedResult($trace, $iterations, $promptTokens, $completionTokens);
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
     * Enforce the model context window on the outgoing transcript (ADR-107). A
     * no-op when the manager is absent (unchanged from before this feature).
     * Returns the possibly-pruned messages; throws when even the floor overflows.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<array<string, mixed>>             $toolSpecs the tool schemas on THIS wire; [] for a plain completion
     * @param RunTrace|null                          $runTrace  records the round's context accounting (ADR-151). Non-null for every run the AgentRuntime drives, so the step is persisted as a ``context`` event too, not only streamed to the playground inspector; null only where the caller passes no trace at all
     *
     * @throws ContextTruncatedException when the pruned floor still exceeds the window
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function enforceContextWindow(
        array $messages,
        LlmConfiguration $configuration,
        ?ToolOptions $options,
        ?UsageStatistics $lastUsage,
        int $iteration,
        array $toolSpecs,
        ?RunTrace $runTrace = null,
    ): array {
        if (!$this->contextWindow instanceof ContextWindowManagerInterface) {
            return $messages;
        }

        $fit = $this->contextWindow->fit(
            $messages,
            $configuration,
            $options,
            $lastUsage,
            $toolSpecs,
            // Named, because the argument between them is the injected skill
            // block, which this path does not carry: the agent loop composes
            // its prompt itself rather than having one prepended after the fit.
            effectiveSystemPrompt: $this->effectiveSystemPrompt($configuration, $options),
        );

        // Before the overflow throw: a run that stops here is exactly the run
        // whose operator most needs to see which component filled the window.
        $runTrace?->recordContextBudget($iteration, $fit->breakdown);

        if ($fit->overflowAtFloor) {
            throw ContextTruncatedException::fromFit($fit);
        }

        if ($fit->pruned) {
            // Observability: distinguishes "trimmed history, run fine" from a
            // failure. The dedicated inspector RunStep ADR-107 wanted is the
            // context step recorded above (ADR-151); this line stays for the
            // runs that carry no trace.
            $this->logger?->info('Agent loop transcript pruned to fit the context window', [
                'iteration'       => $iteration,
                'droppedTurns'    => $fit->droppedTurns,
                'keptTurns'       => $fit->keptTurns,
                'estimatedTokens' => $fit->estimatedTokens,
                'budget'          => $fit->budget,
                'calibration'     => $fit->calibration,
            ]);

            return $fit->messages;
        }

        return $messages;
    }

    /**
     * @param list<ToolInvocation> $trace
     */
    private function contextTruncatedResult(array $trace, int $iterations, int $promptTokens, int $completionTokens): ToolLoopResult
    {
        return new ToolLoopResult(
            '',
            $trace,
            $iterations,
            true,
            UsageStatistics::fromTokens($promptTokens, $completionTokens),
            AgentRunTerminationReason::CONTEXT_TRUNCATED,
        );
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
        ToolExecutionContext $context,
        ?int $maxIterations = null,
        ?RunTrace $runTrace = null,
        ?int $beUserUid = null,
    ): ToolLoopResult {
        $messages     = $state->messages;
        $pendingCalls = $state->toolCalls();
        // Restore the run's options and re-inject the acting user's uid so the
        // resumed continuation is budget-checked — the uid is intentionally not
        // part of the persisted options (ADR-084).
        $options = $this->restoreCallerSource(ToolOptions::fromArray($state->options, $beUserUid), $state->options);
        // Re-apply the gate NOW (a tool may have been disabled or restricted while
        // the run was suspended) rather than trusting the names captured at
        // suspend time.
        $offered     = $this->resolveOfferedNames($state->allowedToolNames, $configuration, $context);
        $remoteCalls = new RemoteCallBudget();

        // ADR-184: an approval is a decision about the state the preview showed.
        // Checked for the WHOLE turn before any call runs — a turn is approved as
        // one (ADR-132), and checking inside the loop below would let call one
        // mutate before call two is found stale, which is a partial write against
        // a state nobody approved.
        if ($approved) {
            $restale = $this->previewComparator()->compare($state, $pendingCalls, $offered, $context);
            if ($restale instanceof SuspendedRunState) {
                throw ToolApprovalRequiredException::fromState($restale);
            }
        }

        foreach ($pendingCalls as $call) {
            if (!$approved) {
                $result = sprintf('Error: tool "%s" was denied by the operator.', $call->name);
                $runTrace?->recordToolExecution($state->iterations, 0.0, $call->name, $call->arguments, $result, true);
            } elseif (!in_array($call->name, $offered, true)) {
                $result = sprintf('Error: tool "%s" is no longer permitted and was not executed.', $call->name);
                $runTrace?->recordToolExecution($state->iterations, 0.0, $call->name, $call->arguments, $result, true);
            } elseif ($this->registry->get($call->name) instanceof RequiresInputInterface) {
                // ADR-105 M1 defence in depth: the approval-resume path carries no
                // user input. An input-requiring pending call must NOT fail-open
                // execute here without its data — refuse it, forcing the model to
                // re-request via a fresh turn, which then hits the input scan and
                // suspends for a proper submitInput(). (The dual approval+input
                // marker is already banned at registration; this guards the case
                // regardless.)
                $result = sprintf('Error: tool "%s" requires user input that was not provided.', $call->name);
                $runTrace?->recordToolExecution($state->iterations, 0.0, $call->name, $call->arguments, $result, true);
            } else {
                $tt0 = hrtime(true);
                $runTrace?->beforeToolExecution($call->name);
                $tr     = $this->invoke($call, $offered, $context, $remoteCalls);
                $result = $tr->content;
                $runTrace?->recordToolResult($state->iterations, $this->elapsedMs($tt0), $call->name, $call->arguments, $tr);
            }

            $messages[] = ChatMessage::toolResult($call->id, $result);
        }

        // Seed the loop with the pre-suspend counters so the returned totals span
        // the whole run — and a further suspend inside the continuation persists
        // the running total, not just its own segment (ADR-084).
        return $this->runLoop(
            $messages,
            $configuration,
            $context,
            $state->allowedToolNames,
            $options,
            $maxIterations,
            $runTrace,
            // Rebuilt from the persisted uids, not carried in memory: a resume
            // runs in a different process from the suspend (ADR-165).
            $this->augmentationFrom($state),
            true,
            $state->iterations,
            $state->promptTokens,
            $state->completionTokens,
        );
    }

    /**
     * Resume a run suspended for typed user input (ADR-105) — the input sibling
     * of {@see self::resume()}.
     *
     * Restores the run's allow-list and options, then executes the pending
     * turn's calls: the input-requiring target ($state->inputToolName) runs with
     * the human's validated data overlaid onto its arguments; a sibling call
     * that has since been disabled is refused; a SECOND input-requiring call in
     * the same turn is fail-closed-refused (one submission cannot satisfy two);
     * any other (read-only) call runs normally. Then re-enters
     * {@see self::runLoop()} with assembly skipped and the pre-suspend counters
     * seeded, exactly as approval resume does — so multi-suspend cycles
     * accumulate their totals.
     *
     * $inputData is validated by the caller (AgentRuntime, before the claim);
     * it is re-validated here as defence in depth when a validator is wired.
     *
     * @param array<string, mixed> $inputData
     */
    public function resumeWithInput(
        SuspendedRunState $state,
        array $inputData,
        LlmConfiguration $configuration,
        ToolExecutionContext $context,
        ?int $maxIterations = null,
        ?RunTrace $runTrace = null,
        ?int $beUserUid = null,
    ): ToolLoopResult {
        // Defence in depth: do not trust the caller's "already validated" claim.
        if (!$this->schemaValidator->validate($inputData, $state->inputSchema)) {
            throw new LogicException('resumeWithInput received input that does not match the declared schema.', 1784600106);
        }

        $messages     = $state->messages;
        $pendingCalls = $state->toolCalls();
        $options      = $this->restoreCallerSource(ToolOptions::fromArray($state->options, $beUserUid), $state->options);
        $offered      = $this->resolveOfferedNames($state->allowedToolNames, $configuration, $context);
        $remoteCalls  = new RemoteCallBudget();

        foreach ($pendingCalls as $call) {
            if (!in_array($call->name, $offered, true)) {
                $result = sprintf('Error: tool "%s" is no longer permitted and was not executed.', $call->name);
                $runTrace?->recordToolExecution($state->iterations, 0.0, $call->name, $call->arguments, $result, true);
            } elseif ($call->name === $state->inputToolName) {
                $tt0 = hrtime(true);
                $runTrace?->beforeToolExecution($call->name);
                $tr = $this->invoke($this->withInput($call, $state->inputSchema, $inputData), $offered, $context, $remoteCalls);
                $result = $tr->content;
                $runTrace?->recordToolResult($state->iterations, $this->elapsedMs($tt0), $call->name, $call->arguments, $tr);
            } elseif ($this->registry->get($call->name) instanceof RequiresInputInterface) {
                // A second input-requiring call in the same turn got no data —
                // one submission satisfies one tool. Fail-closed refusal.
                $result = sprintf('Error: tool "%s" requires input that was not provided.', $call->name);
                $runTrace?->recordToolExecution($state->iterations, 0.0, $call->name, $call->arguments, $result, true);
            } elseif (ToolApprovalRule::requiresApproval($this->registry->get($call->name))) {
                // Nothing on THIS path ever approved anything — an input
                // submission is not an approval. For a turn that suspended
                // normally the branch is unreachable, because the approval scan
                // has strict precedence and would have suspended first.
                //
                // It becomes reachable when the tool was not OFFERED at suspend
                // time: the approval scan skips such a call by design (invoke()
                // refuses it a moment later, so demanding approval for a tool
                // the run never allowed would be a spurious prompt). The offered
                // set is then recomputed from the live configuration at resume,
                // so a write enabled while the run waited for the human becomes
                // offered — and without this branch it would execute here having
                // passed no approval at any point.
                //
                // Refusing rather than suspending keeps one approval per
                // submission: the model re-requests in a fresh turn, which hits
                // the approval scan and suspends for a real one.
                $result = sprintf('Error: tool "%s" requires approval that was not given.', $call->name);
                $runTrace?->recordToolExecution($state->iterations, 0.0, $call->name, $call->arguments, $result, true);
                // Recorded, not only traced (#757). The run timeline shows this
                // refusal for one run; the governance table is what answers
                // "did this ever fire, and how often" — which is the question
                // that says whether the gap this branch closes is theoretical.
                $this->governanceEvents?->record(new GovernanceEvent(
                    correlationId: $context->run?->correlationId() ?? '',
                    decision: GovernanceDecision::WRITE_UNAPPROVED->value,
                    reason: 'inputResumeWithoutApproval',
                    provider: $configuration->getProviderType(),
                    model: $configuration->getModelId(),
                    configurationIdentifier: $configuration->getIdentifier(),
                    beUser: $context->actor->backendUserUid,
                    toolName: $call->name,
                    agentrunUid: $context->run->uid ?? 0,
                    guardrail: '',
                    // The tool that DID receive the human's input, so an
                    // operator can see which suspend the refused call rode in
                    // on. Never the arguments: this row is metadata.
                    detail: 'inputTool=' . $state->inputToolName,
                ));
            } else {
                $tt0 = hrtime(true);
                $runTrace?->beforeToolExecution($call->name);
                $tr     = $this->invoke($call, $offered, $context, $remoteCalls);
                $result = $tr->content;
                $runTrace?->recordToolResult($state->iterations, $this->elapsedMs($tt0), $call->name, $call->arguments, $tr);
            }

            $messages[] = ChatMessage::toolResult($call->id, $result);
        }

        return $this->runLoop(
            $messages,
            $configuration,
            $context,
            $state->allowedToolNames,
            $options,
            $maxIterations,
            $runTrace,
            // Rebuilt from the persisted uids, not carried in memory: a resume
            // runs in a different process from the suspend (ADR-165).
            $this->augmentationFrom($state),
            true,
            $state->iterations,
            $state->promptTokens,
            $state->completionTokens,
        );
    }

    /**
     * Overlay the user's validated input onto the target tool call's arguments,
     * bounded to the schema-declared keys (ADR-105 security): the model's own
     * values for human-controlled keys are stripped, then ONLY schema-declared
     * keys from the human are merged in. The model cannot smuggle a value into a
     * human-controlled field, and the human cannot smuggle an undeclared
     * argument into the call.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $inputData
     */
    private function withInput(ToolCall $call, array $schema, array $inputData): ToolCall
    {
        $properties = $schema['properties'] ?? [];
        $declared   = is_array($properties) ? array_keys($properties) : [];
        $keyMap     = array_flip(array_map(static fn(int|string $k): string => (string)$k, $declared));

        $base  = array_diff_key($call->arguments, $keyMap);
        $human = array_intersect_key($inputData, $keyMap);

        return new ToolCall($call->id, $call->name, [...$base, ...$human], $call->type);
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
        $forcedSkills = $augmentation instanceof RunAugmentation ? $augmentation->forcedSkills : [];
        $messages     = $this->skillInjection?->augmentMessages($messages, $configSkills, $forcedSkills) ?? $messages;

        if (!$augmentation instanceof RunAugmentation) {
            return [$messages, false];
        }

        $lead = [];

        // Bake the effective system prompt as the first message. Without this,
        // the snippet system messages below would satisfy the manager's "a
        // system message already exists" guard and suppress the configuration
        // system prompt for the run.
        // The same composition the manager's planner applies, so the playground
        // shows the prompt a live run sends rather than one without the
        // configuration's snippets.
        $system = $this->effectiveSystemPrompt($configuration, $options);
        if ($system !== '') {
            $lead[] = ChatMessage::system($system);
        }

        foreach ($augmentation->forcedSnippets as $snippet) {
            $text = $this->snippetComposer?->composeSections([$snippet->getName() => $snippet]) ?? '';
            if ($text === '') {
                continue;
            }

            // A forced snippet the configuration already selects by tag is
            // composed into $system above, by the same stateless composer and
            // therefore byte-identically. Emitting it again would put the same
            // instruction in the prompt twice — the resolver's identifier dedup
            // never sees the forced list, so the containment check is what
            // spans both sources.
            if ($system !== '' && str_contains($system, $text)) {
                continue;
            }

            $lead[] = ChatMessage::system($text);
        }

        if ($lead !== []) {
            $messages = array_values(array_merge($lead, $messages));
        }

        return [$messages, $augmentation->dryRun];
    }

    /**
     * The system prompt this run actually puts on the wire: the per-call
     * override wins over the configuration's own text, and the configuration's
     * tag-selected snippets (ADR-031) follow it — the same composition
     * {@see \Netresearch\NrLlm\Service\ConfigurationCallPlanner::callOptions()}
     * applies on the manager side.
     *
     * Two readers: the playground bake site in {@see self::assemble()} and the
     * context-window estimate in {@see self::enforceContextWindow()}, which
     * would otherwise budget for a prompt smaller than the one sent.
     */
    private function effectiveSystemPrompt(LlmConfiguration $configuration, ?ToolOptions $options): string
    {
        $override = $options?->getSystemPrompt() ?? '';
        $system   = $override !== '' ? $override : $configuration->getSystemPrompt();

        return $this->snippetResolver?->appendTo($system, $configuration) ?? $system;
    }

    private function elapsedMs(int $startNs): float
    {
        return (hrtime(true) - $startNs) / 1_000_000;
    }

    /**
     * What the pending turn WOULD do, one entry per offered call whose tool
     * implements {@see ToolPreviewInterface} (ADR-136).
     *
     * Produced HERE, at the suspend, and not when the card is rendered: this is
     * the run's actor context, the identity the tool is allowed to read the
     * target with (ADR-083). The reviewing administrator's request is not.
     *
     * Only OFFERED calls are previewed, mirroring the approval scan above: a
     * registered-but-not-offered tool the model named will be refused at
     * execution, so running its preview would describe a call that cannot
     * happen — and would read data the run was never allowed to reach.
     *
     * A preview that throws or returns nothing becomes a marked failure line
     * rather than an exception: the pause exists so a human can decide, and a
     * card that silently loses its preview would let them decide blind without
     * knowing it. The exception TEXT is deliberately not shown — as in
     * {@see self::invoke()}, an exception body may carry DBAL credentials — but
     * the class name is, and the full exception goes to the log.
     *
     * @param list<ToolCall> $calls
     * @param list<string>   $allowedNames
     *
     * @return list<array{index: int, tool: string, lines: list<string>, failed: bool}>
     */
    private function previewsForTurn(array $calls, array $allowedNames, ToolExecutionContext $context): array
    {
        $previews = [];
        foreach ($calls as $index => $call) {
            $tool = $this->registry->get($call->name);
            if (!in_array($call->name, $allowedNames, true)) {
                continue;
            }

            if (!$tool instanceof ToolPreviewInterface) {
                continue;
            }

            $failed = false;
            try {
                $lines = array_values(array_filter($tool->previewCall($call->arguments, $context), is_string(...)));
            } catch (Throwable $e) {
                $this->logger?->warning('Tool preview failed; the approval card will say so.', ['tool' => $call->name, 'exception' => $e]);
                $lines  = [sprintf('The preview for this call failed (%s), so it shows nothing about what the call would do.', $e::class)];
                $failed = true;
            }

            if ($lines === []) {
                $lines  = ['The tool produced no preview for this call.'];
                $failed = true;
            }

            $previews[] = [
                'index'  => $index,
                'tool'   => $call->name,
                // Bounded before it is persisted: the state is encrypted, stored
                // and re-read on every resume, and a preview is model-triggered
                // output like any other.
                'lines'  => $this->previewComparator()->bound($lines),
                'failed' => $failed,
            ];
        }

        return $previews;
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
     * @return ToolResult
     */
    /**
     * Resolve the tool names offered for a run by asking the composite gate
     * (ADR-094): global enablement, the admin filter, the configuration's own
     * group grant and the trust-zone ceiling, all fail-closed. Reused by
     * {@see self::resume()} so a resume re-applies the gate at approval time — a
     * tool disabled or restricted while suspended is not executed.
     *
     * @param list<string>|null $allowedToolNames null ⇒ the globally-enabled set;
     *                                            a list ⇒ that set ∩ enabled;
     *                                            `[]` ⇒ no tools
     *
     * @return list<string>
     */
    private function resolveOfferedNames(?array $allowedToolNames, LlmConfiguration $configuration, ToolExecutionContext $context): array
    {
        $user = $context->actingBackendUser();

        foreach ($this->toolPolicy->explain($allowedToolNames, $configuration, $user) as $decision) {
            if (!$decision->allowed || $decision->observedOnly) {
                $this->logger?->info('Tool gate: ' . $decision->message(), [
                    'tool'   => $decision->toolName,
                    'reason' => $decision->reason->value,
                    'zone'   => $decision->zone->value,
                ]);
                // Persist the denial (or observe-mode flag) so it is queryable
                // by tool name and reason (the log line is not). This gate is
                // the one place tool_name is structurally available. Since
                // ADR-153 the run's identity travels on the execution context,
                // so the row also joins to the run that was denied the tool —
                // both stay empty/0 for a bare loop consumer that has no
                // persisted run. observed-mode rows are recorded too (flagged
                // in detail) so the trust-zone rollout is measurable before it
                // is enforced.
                $this->governanceEvents?->record(new GovernanceEvent(
                    correlationId: $context->run?->correlationId() ?? '',
                    decision: GovernanceDecision::TOOL_DENIED->value,
                    reason: $decision->reason->value,
                    provider: $configuration->getProviderType(),
                    model: $configuration->getModelId(),
                    configurationIdentifier: $configuration->getIdentifier(),
                    beUser: $context->actor->backendUserUid,
                    toolName: $decision->toolName,
                    agentrunUid: $context->run->uid ?? 0,
                    guardrail: '',
                    detail: sprintf(
                        'zone=%s;ceiling=%s;observedOnly=%d',
                        $decision->zone->value,
                        $decision->ceiling->value,
                        $decision->observedOnly ? 1 : 0,
                    ),
                ));
            }
        }

        return $this->toolPolicy->filterOfferable($allowedToolNames, $configuration, $user);
    }

    /**
     * Resolve and execute a single tool call, returning a typed {@see ToolResult}.
     * A missing tool, a tool not in the offered allow-list, or a thrown exception
     * becomes a fail-closed error result (`isError = true`, NO artifacts) so the
     * loop can continue instead of aborting or leaking internals.
     *
     * Both channels are bounded here — the single seam every executed call passes
     * through — before any ToolResult leaves the process: `content` via
     * {@see ToolResultBounder::content()}, `artifacts` via {@see ToolResultBounder::artifacts()}.
     *
     * @param list<string> $allowedNames
     */
    private function invoke(ToolCall $call, array $allowedNames, ToolExecutionContext $context, RemoteCallBudget $remoteCalls): ToolResult
    {
        $tool = $this->registry->get($call->name);
        if (!$tool instanceof ToolInterface) {
            return ToolResult::error(sprintf('Error: unknown tool "%s"', $call->name));
        }

        if (!in_array($call->name, $allowedNames, true)) {
            return ToolResult::error(sprintf('Error: tool "%s" not permitted', $call->name));
        }

        // Charged after the permission checks and before execution, so a
        // refused call costs nothing and a granted one is counted exactly once.
        // The message says the budget is spent rather than that the tool is
        // broken, so the model stops calling it instead of retrying.
        if ($tool instanceof RemoteToolInterface && !$remoteCalls->tryConsume()) {
            return ToolResult::error(sprintf(
                'Error: this run has used its budget of %d calls to external tools; "%s" was not called.',
                $remoteCalls->limit(),
                $call->name,
            ));
        }

        try {
            $result = $tool->execute($call->arguments, $context);
        } catch (Throwable $e) {
            // Keep the logged summary generic — the exception body may embed
            // DBAL/PDO credentials that URL-sanitising would not strip. The full
            // Throwable (message + trace) is preserved in the log context for
            // server-side forensics.
            $this->logger?->error(
                sprintf('Tool "%s" failed.', $call->name),
                ['exception' => $e],
            );

            return ToolResult::error(sprintf('Error: tool "%s" failed.', $call->name));
        }

        // Transform, never rebuild (ADR-182). A ToolResult reconstructed from a
        // subset of its properties drops everything the subset omits — the shape
        // that already cost #844, #845 and #846 — and no test asserting on the
        // tool's own return value would notice. The fail-closed emptiness of an
        // error result is the value object's rule, not a condition repeated at
        // every call site.
        return $result->withBoundedChannels(
            $this->bounder->content($result->content),
            $this->bounder->artifacts($result->artifacts),
        );
    }

    /**
     * The ADR-184 comparator, built where it is used.
     *
     * Stateless and cheap, so it needs no constructor slot: this class already
     * carries eleven collaborators, and a twelfth optional one would be a
     * positional argument every lean test wiring has to skip past.
     */
    private function previewComparator(): ApprovalPreviewComparator
    {
        return new ApprovalPreviewComparator($this->registry, $this->logger);
    }

    /**
     * The run's options as they are persisted at suspend: the serialised
     * `ToolOptions`, plus the caller identity carried alongside them.
     *
     * @return array<string, mixed>
     */
    private function persistedRunOptions(?ToolOptions $options): array
    {
        $persisted = $options?->toArray() ?? [];

        $extension = $options?->getCallerSourceExtension();
        if ($extension === null || $extension === '') {
            return $persisted;
        }

        // Carried BESIDE the serialised options, not inside them: `toArray()`
        // builds the provider payload and deliberately omits the caller identity,
        // exactly as it omits the idempotency key and the budget fields. Widening
        // it here would send the calling extension's key upstream and would take
        // the reason those other two are excluded down with it (#847).
        //
        // `ToolOptions::fromArray()` ignores keys it does not know, so these two
        // survive the round trip without reaching a provider; resume reads them
        // back explicitly in {@see self::restoreCallerSource()}.
        return $persisted + [
            'callerSourceExtension' => $extension,
            'callerSourceOperation' => $options?->getCallerSourceOperation() ?? '',
        ];
    }

    /**
     * Put the caller identity back on options rehydrated from a suspended state.
     *
     * The same shape as the backend-user uid the resume paths already re-inject
     * by hand (ADR-084): persisted out-of-band, restored explicitly. Without it
     * every provider round-trip after an approval or an input submission is
     * unattributed, so one logical run splits between its own extension and
     * *Unattributed* and neither figure is the run's cost (#847).
     *
     * @param array<string, mixed> $persisted
     */
    private function restoreCallerSource(ToolOptions $options, array $persisted): ToolOptions
    {
        $extension = $persisted['callerSourceExtension'] ?? null;
        if (!is_string($extension) || $extension === '') {
            return $options;
        }

        $operation = $persisted['callerSourceOperation'] ?? null;

        return $options->withCallerSource($extension, is_string($operation) ? $operation : '');
    }

    /**
     * Forward the budget pre-flight context (BE-user uid, planned cost) and the
     * caller identity onto a completion this service places itself, so the
     * tool-free turn and the cap-hit synthesis stay budget-gated and attributed
     * exactly like the per-iteration tool calls.
     *
     * The caller source needs carrying by hand here because these two calls go
     * through `chatWithConfiguration()`, whose channel is the metadata array —
     * the options object the run was started with does not reach them. Without
     * it an annotated run splits across two rows in the per-extension
     * breakdown: the tool-capable turns attributed, these two not (ADR-178).
     *
     * @return array<string, mixed>
     */
    private function budgetMetadata(?ToolOptions $options): array
    {
        if (!$options instanceof ToolOptions) {
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

        // Null and '' both mean "named nobody" — the getter is nullable while
        // the wither writes '' for an omitted operation, so both have to be
        // treated the same or an unannotated run writes empty metadata keys.
        $sourceExtension = $options->getCallerSourceExtension() ?? '';
        if ($sourceExtension !== '') {
            $metadata[TelemetryMiddleware::METADATA_SOURCE_EXTENSION] = $sourceExtension;

            $sourceOperation = $options->getCallerSourceOperation() ?? '';
            if ($sourceOperation !== '') {
                $metadata[TelemetryMiddleware::METADATA_SOURCE_OPERATION] = $sourceOperation;
            }
        }

        return $metadata;
    }
}
