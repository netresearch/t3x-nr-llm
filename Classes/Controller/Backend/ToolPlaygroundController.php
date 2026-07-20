<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Closure;
use Netresearch\NrLlm\Controller\Backend\Response\PlaygroundRunResponse;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ReflectionClass;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NullResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Admin-only interactive tool playground backend module.
 *
 * The v1 consumer of the tool runtime (PR-A): an admin picks an LLM
 * configuration, types a prompt, and the agent loop runs each tool call and
 * the final answer. {@see listAction()} renders the Fluid shell (config
 * picker, prompt box, tools panel, output pane); the AJAX runAction (Task 2)
 * runs the loop and returns the trace as JSON.
 *
 * Mirrors {@see SkillSourceController}: a `#[AsController]` Extbase
 * ActionController whose list action is the module entry point, gated to
 * admins via the module's ``access => admin`` and (for the AJAX actions) the
 * {@see RequiresBackendAdminTrait} guard.
 */
#[AsController]
final class ToolPlaygroundController extends ActionController implements LoggerAwareInterface
{
    use RequiresBackendAdminTrait;
    use LoggerAwareTrait;
    use ErrorMessageSanitizerTrait;
    use DefensiveLocalizationTrait;

    /**
     * Server-side ceiling on the per-run round count, matching the UI's
     * max-rounds input. Guards against a crafted request driving an unbounded,
     * cost-accruing agent loop.
     */
    private const MAX_ROUNDS = 20;

    /**
     * Minimum byte length of each streamed NDJSON line. A TYPO3 backend AJAX
     * response is buffered by the reverse proxy until a chunk clears its flush
     * threshold; padding every event past ~4 KB makes it flush immediately, so
     * steps reach the browser as they happen instead of all at once at the end.
     */
    private const STREAM_MIN_LINE_BYTES = 4096;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly ToolLoopService $toolLoopService,
        private readonly ToolAvailabilityServiceInterface $toolAvailability,
        private readonly SkillRepository $skillRepository,
        private readonly PromptSnippetRepository $promptSnippetRepository,
        // Optional and last so the existing direct constructions in the
        // functional tests keep compiling (they run with persistence off — a
        // free characterization guard that the loop path is unchanged); the DI
        // container autowires the real persister in production.
        private readonly ?AgentRunPersister $agentRunPersister = null,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ToolPlayground.js');
        $this->pageRenderer->addCssFile('EXT:nr_llm/Resources/Public/Css/Backend/Playground.css');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $moduleTemplate->assignMultiple([
            'configurations' => $this->configurationRepository->findAll(),
            'toolStates' => $this->toolAvailability->states(),
            'skills' => $this->availableSkills(),
            'snippets' => $this->availableSnippets(),
            'ajaxRoute' => 'nrllm_tool_run',
        ]);
        return $moduleTemplate->renderResponse('Backend/Playground/List');
    }

    /**
     * Run the bounded tool loop for the picked configuration and return its
     * trace as JSON (design §4.6).
     *
     * Admin-gated via {@see denyNonAdmin()} FIRST: AJAX routes bypass the
     * module's ``access => admin`` check (ADR-037). The configuration's vault
     * key, model and pricing reach the call through
     * {@see ToolLoopService::runLoop()} (it runs on
     * `chatWithToolsForConfiguration`). The per-run allow-list comes from the
     * tool checkboxes the operator ticked (defaulting to the globally-enabled
     * set when none are sent); {@see ToolLoopService} still intersects it with
     * the global gate, so a disabled tool can never be offered. Any unexpected
     * provider/tool failure returns a JSON 500 carrying a SANITIZED diagnosis
     * (exception class + message with secret-bearing URL params stripped via
     * {@see ErrorMessageSanitizerTrait}, plus a truncated sanitized provider
     * response body) — never the raw message verbatim — while the full detail is
     * logged downstream.
     */
    public function runAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }

        $body      = $request->getParsedBody();
        $configUid = $this->intFromBody($body, 'configuration');
        $prompt    = trim($this->stringFromBody($body, 'prompt'));

        $config = $this->configurationRepository->findByUid($configUid);
        if ($config === null || $prompt === '') {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.invalidInput', 'Invalid configuration or prompt')], 400);
        }

        $captureRaw   = $this->boolFromBody($body, 'captureRaw');
        $dryRun       = $this->boolFromBody($body, 'dryRun');
        $systemPrompt = trim($this->stringFromBody($body, 'systemPrompt'));
        // Cap the per-run round count server-side (matching the UI ceiling) so
        // a crafted request cannot drive an unbounded, cost-accruing loop.
        $maxRounds    = min($this->intFromBody($body, 'maxRounds'), self::MAX_ROUNDS);
        $maxTokens    = $this->intFromBody($body, 'maxTokens');
        // Clamp to ChatOptions' valid range: an out-of-range value would throw
        // an InvalidArgumentException from the ToolOptions constructor below —
        // which is outside the run try/catch — and 500 the request.
        $temperature  = $this->floatFromBody($body, 'temperature');
        if ($temperature !== null) {
            $temperature = max(0.0, min(2.0, $temperature));
        }
        // Tri-state reasoning toggle (#312): '1' forces thinking on, '0'
        // off, anything else keeps the provider/model default.
        $think = match ($this->stringFromBody($body, 'think')) {
            '1' => true,
            '0' => false,
            default => null,
        };
        $options      = new ToolOptions(
            temperature: $temperature,
            maxTokens: $maxTokens > 0 ? $maxTokens : null,
            systemPrompt: $systemPrompt !== '' ? $systemPrompt : null,
            beUserUid: $this->currentBackendUserUid(),
            captureRaw: $captureRaw,
            think: $think,
        );

        // The checked tool boxes restrict this run; absent any selection, fall
        // back to the globally-enabled set. The runtime gate is authoritative
        // regardless (a disabled tool stays off).
        $selected = $this->toolNamesFromBody($body) ?? $this->toolAvailability->enabledNames();

        // The selection is a request, not a grant: the configuration's skill
        // allow-list and allowed_tool_groups are applied inside the loop since
        // ADR-093, so every consumer of ToolLoopServiceInterface gets the same
        // gate — not just this controller.
        $allowed = $selected;

        $augmentation = new RunAugmentation(
            forcedSkills: $this->resolveForcedSkills($this->uidListFromBody($body, 'forcedSkills')),
            forcedSnippets: $this->promptSnippetRepository->findByUids($this->uidListFromBody($body, 'forcedSnippets')),
            dryRun: $dryRun,
        );
        $messages      = [ChatMessage::user($prompt)];
        $maxIterations = $maxRounds > 0 ? $maxRounds : null;

        // Persist the run (ADR-081): open a RUNNING row and get a handle to
        // thread each recorded step through. Null when persistence is
        // unavailable — the run then proceeds unpersisted, exactly as before.
        $handle = $this->agentRunPersister?->begin($config, $this->currentBackendUserUid());

        // Live path: stream each recorded step to the browser as it happens.
        if ($this->boolFromBody($body, 'stream')) {
            return $this->runStreamed($messages, $config, $allowed, $options, $maxIterations, $augmentation, $captureRaw, $handle);
        }

        // Batch path: run the whole loop, then return the full trace as one JSON
        // document (the no-JS fallback and the shape the functional tests assert).
        // The onRecord hook persists each step as it is recorded (ADR-081) —
        // additive to the loop, which is unaware of it.
        $trace = new RunTrace(
            captureRaw: $captureRaw,
            onRecord: $handle !== null
                ? function (RunStep $step) use ($handle): void {
                    $this->agentRunPersister?->recordStep($handle, $step);
                }
            : null,
        );

        try {
            $result = $this->toolLoopService->runLoop($messages, $config, $allowed, $options, $maxIterations, $trace, $augmentation);
        } catch (ToolApprovalRequiredException $approval) {
            // ADR-084: a called tool requires human approval. Persist the
            // suspended state (transcript + pending calls) so a later resume can
            // continue, and return an awaiting-approval response — caught BEFORE
            // the generic Throwable below so a suspension is not a failed run.
            $persister = $this->agentRunPersister;
            if ($handle !== null && $persister !== null && !$persister->suspend($handle, $approval->state)) {
                // Fail-closed: an approval-gated tool is side-effecting. Without
                // stored state there is nothing to resume, so promising the
                // client an approval flow would strand the run (ADR-092).
                $persister->settleFailed($handle, $approval);
                $this->logger?->error('Agent run could not be suspended for approval; the run was failed instead', ['run' => $handle->uuid]);

                return $this->respondJson([
                    'success' => false,
                    'error'   => 'The run required approval but its state could not be stored, so it cannot be resumed.',
                ], 500);
            }

            return $this->respondJson($this->awaitingApprovalPayload($handle !== null ? $handle->uuid : '', $approval, $trace));
        } catch (GuardrailViolationException|GuardrailApprovalRequiredException $guardrail) {
            return $this->handleGuardrailBlock($guardrail, $handle, $trace);
        } catch (Throwable $e) {
            if ($handle !== null) {
                $this->agentRunPersister?->settleFailed($handle, $e);
            }
            $this->logger?->error('Tool playground run failed', ['exception' => $e]);

            return $this->respondJson(['success' => false, 'error' => $this->diagnoseRunFailure($e)], 500);
        }

        if ($handle !== null) {
            $this->agentRunPersister?->settleCompleted($handle, $result);
        }

        $response = new PlaygroundRunResponse(
            finalContent: $result->finalContent,
            iterations: $result->iterations,
            truncated: $result->truncated,
            dryRun: $dryRun,
            steps: $trace->getSteps(),
            promptTokens: $result->usage->promptTokens,
            completionTokens: $result->usage->completionTokens,
            totalTokens: $result->usage->totalTokens,
            estimatedCost: $result->usage->estimatedCost,
        );

        return $this->respondJson($response->toArray());
    }

    /**
     * Resume a run suspended for human approval (ADR-084).
     *
     * Admin-gated like {@see runAction()}. Loads the suspended run by uuid,
     * rehydrates its state, executes (or, when not approved, refuses) the pending
     * tool call(s) via {@see ToolLoopService::resume()}, and returns the continued
     * run's result — which may itself suspend again on another approval-required
     * tool.
     */
    public function resumeAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }

        $body     = $request->getParsedBody();
        $runUuid  = trim($this->stringFromBody($body, 'runUuid'));
        $approved = $this->boolFromBody($body, 'approve');

        $run = $this->agentRunPersister?->findRun($runUuid);
        if ($run === null || $run->statusEnum() !== AgentRunStatus::WAITING_FOR_APPROVAL || $run->suspendedState === null) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.notAwaitingApproval', 'No run is awaiting approval for that id.')], 400);
        }

        $config = $this->configurationRepository->findByUid($run->configurationUid);
        if ($config === null) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.configGone', 'The run configuration no longer exists.')], 400);
        }

        $decoded = json_decode($run->suspendedState, true);
        if (!is_array($decoded)) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.corruptState', 'The suspended run state could not be read.')], 500);
        }
        $state = SuspendedRunState::fromArray($decoded);

        // Atomically claim the run before executing its pending (approval-gated,
        // possibly destructive) tool calls, so two concurrent Approve requests
        // — an admin double-click, a client retry — cannot both run them (ADR-084).
        if ($this->agentRunPersister !== null && !$this->agentRunPersister->claimResume($run)) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.alreadyResuming', 'This run is already being processed.')], 409);
        }

        // Continue the SAME run: rebuild the handle so events keep ascending.
        // The allow-list and options are restored from the suspended state inside
        // resume() — the run keeps its original constraints (ADR-084).
        $handle = $this->agentRunPersister?->resumeHandle($run);
        $trace  = new RunTrace(
            onRecord: $handle !== null
                ? function (RunStep $step) use ($handle): void {
                    $this->agentRunPersister?->recordStep($handle, $step);
                }
            : null,
        );

        try {
            $result = $this->toolLoopService->resume($state, $approved, $config, null, $trace, $this->currentBackendUserUid());
        } catch (ToolApprovalRequiredException $approval) {
            if ($handle !== null) {
                $this->agentRunPersister?->suspend($handle, $approval->state);
            }

            return $this->respondJson($this->awaitingApprovalPayload($run->uuid, $approval, $trace));
        } catch (GuardrailViolationException|GuardrailApprovalRequiredException $guardrail) {
            return $this->handleGuardrailBlock($guardrail, $handle, $trace);
        } catch (Throwable $e) {
            if ($handle !== null) {
                $this->agentRunPersister?->settleFailed($handle, $e);
            }
            $this->logger?->error('Tool playground resume failed', ['exception' => $e]);

            return $this->respondJson(['success' => false, 'error' => $this->diagnoseRunFailure($e)], 500);
        }

        if ($handle !== null) {
            $this->agentRunPersister?->settleCompleted($handle, $result);
        }

        $response = new PlaygroundRunResponse(
            finalContent: $result->finalContent,
            iterations: $result->iterations,
            truncated: $result->truncated,
            dryRun: false,
            steps: $trace->getSteps(),
            promptTokens: $result->usage->promptTokens,
            completionTokens: $result->usage->completionTokens,
            totalTokens: $result->usage->totalTokens,
            estimatedCost: $result->usage->estimatedCost,
        );

        return $this->respondJson($response->toArray());
    }

    /**
     * The JSON body for a run that suspended for approval: the pending tool calls
     * the operator must approve, plus the steps recorded up to the pause.
     *
     * @return array<string, mixed>
     */
    private function awaitingApprovalPayload(string $runUuid, ToolApprovalRequiredException $approval, RunTrace $trace): array
    {
        return [
            'success'      => true,
            'status'       => 'awaiting_approval',
            'runUuid'      => $runUuid,
            'pendingTools' => array_map(
                static fn(ToolCall $call): array => ['name' => $call->name, 'arguments' => $call->arguments],
                $approval->state->toolCalls(),
            ),
            'steps'        => array_map(static fn(RunStep $step): array => $step->toArray(), $trace->getSteps()),
        ];
    }

    /**
     * Common handling for a guardrail verdict on a non-streamed run (ADR-086):
     * record the run as blocked so it is not left RUNNING, then return the
     * blocked payload. A guardrail DENY or REQUIRE_APPROVAL is a policy outcome,
     * not a server error — the HTTP status stays 200 with ``success:false``.
     */
    private function handleGuardrailBlock(
        GuardrailViolationException|GuardrailApprovalRequiredException $guardrail,
        ?AgentRunHandle $handle,
        RunTrace $trace,
    ): ResponseInterface {
        if ($handle !== null) {
            $this->agentRunPersister?->settlePolicyStopped($handle, $guardrail, $this->guardrailTerminationReason($guardrail));
        }
        $this->logger?->warning('Tool playground run blocked by guardrail', ['exception' => $guardrail]);

        return $this->respondJson($this->guardrailBlockedPayload($guardrail, $trace));
    }

    /**
     * The JSON body for a run a guardrail blocked (ADR-086): the status
     * (denied vs flagged for approval), the deciding guardrail FQCN and its
     * reason, plus the steps recorded up to the block.
     *
     * @return array<string, mixed>
     */
    private function guardrailBlockedPayload(
        GuardrailViolationException|GuardrailApprovalRequiredException $guardrail,
        RunTrace $trace,
    ): array {
        return [
            'success'   => false,
            'status'    => $this->guardrailStatus($guardrail),
            'guardrail' => $guardrail->guardrail,
            'error'     => $guardrail->getMessage(),
            'steps'     => array_map(static fn(RunStep $step): array => $step->toArray(), $trace->getSteps()),
        ];
    }

    /**
     * The terminal streamed event for a guardrail block (ADR-086): same status
     * and reason as {@see self::guardrailBlockedPayload()}, shaped as a stream
     * event rather than a full JSON response.
     *
     * @return array<string, mixed>
     */
    private function guardrailBlockedEvent(
        GuardrailViolationException|GuardrailApprovalRequiredException $guardrail,
    ): array {
        $status = $this->guardrailStatus($guardrail);

        return [
            'event'     => $status,
            'success'   => false,
            'status'    => $status,
            'guardrail' => $guardrail->guardrail,
            'error'     => $guardrail->getMessage(),
        ];
    }

    private function guardrailStatus(
        GuardrailViolationException|GuardrailApprovalRequiredException $guardrail,
    ): string {
        return $guardrail instanceof GuardrailApprovalRequiredException
            ? 'guardrail_approval_required'
            : 'guardrail_blocked';
    }

    /**
     * Why the run ended, for the persisted row (ADR-092). A guardrail stop is a
     * policy outcome, so it is never recorded as a provider failure; an
     * approval that was required and never obtained is recorded as such rather
     * than as an outright denial.
     */
    private function guardrailTerminationReason(
        GuardrailViolationException|GuardrailApprovalRequiredException $guardrail,
    ): AgentRunTerminationReason {
        return $guardrail instanceof GuardrailApprovalRequiredException
            ? AgentRunTerminationReason::APPROVAL_DENIED
            : AgentRunTerminationReason::POLICY_DENIED;
    }

    /**
     * Run the loop while streaming one NDJSON line per recorded step to the
     * browser as it happens, then a final ``done`` (or ``error``) line.
     *
     * Output buffering and compression are disabled and each line is padded
     * (see {@see self::STREAM_MIN_LINE_BYTES}) so the reverse proxy flushes it
     * immediately; a {@see NullResponse} is returned so TYPO3 emits nothing
     * further (it has already been sent). The step callback runs inside
     * {@see RunTrace}, fired the moment each step is recorded.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<string>|null                      $allowed
     */
    private function runStreamed(
        array $messages,
        LlmConfiguration $config,
        ?array $allowed,
        ToolOptions $options,
        ?int $maxIterations,
        RunAugmentation $augmentation,
        bool $captureRaw,
        ?AgentRunHandle $handle = null,
    ): ResponseInterface {
        ini_set('zlib.output_compression', '0');
        ini_set('output_buffering', '0');
        ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        if (!headers_sent()) {
            header('Content-Type: application/x-ndjson; charset=utf-8');
            header('Cache-Control: no-cache, no-transform');
            header('X-Accel-Buffering: no');
        }

        $this->streamRun(
            function (array $event): void {
                $this->streamLine($event);
            },
            $messages,
            $config,
            $allowed,
            $options,
            $maxIterations,
            $augmentation,
            $captureRaw,
            $handle,
        );

        return new NullResponse();
    }

    /**
     * Run the loop and emit the streamed protocol through {@see $emit}: one
     * ``step`` event per recorded step (live, via the {@see RunTrace} callback),
     * then a terminal ``done`` — or ``error`` on failure. Transport-agnostic (no
     * output buffering, headers or flushing), so the event sequence can be
     * asserted in isolation from the echo/flush plumbing in {@see runStreamed()}.
     *
     * @param Closure(array<string, mixed>): void    $emit
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<string>|null                      $allowed
     */
    private function streamRun(
        Closure $emit,
        array $messages,
        LlmConfiguration $config,
        ?array $allowed,
        ToolOptions $options,
        ?int $maxIterations,
        RunAugmentation $augmentation,
        bool $captureRaw,
        ?AgentRunHandle $handle = null,
    ): void {
        $trace = new RunTrace(
            captureRaw: $captureRaw,
            onRecord: function (RunStep $step) use ($emit, $handle): void {
                $emit(['event' => 'step', 'step' => $step->toArray()]);
                if ($handle !== null) {
                    $this->agentRunPersister?->recordStep($handle, $step);
                }
            },
        );

        $settled = false;
        try {
            $result = $this->toolLoopService->runLoop($messages, $config, $allowed, $options, $maxIterations, $trace, $augmentation);
            if ($handle !== null) {
                $this->agentRunPersister?->settleCompleted($handle, $result);
                $settled = true;
            }
            $emit([
                'event'        => 'done',
                'success'      => true,
                'finalContent' => $result->finalContent,
                'iterations'   => $result->iterations,
                'truncated'    => $result->truncated,
                'dryRun'       => $augmentation->dryRun,
                'usage'        => [
                    'promptTokens'     => $result->usage->promptTokens,
                    'completionTokens' => $result->usage->completionTokens,
                    'totalTokens'      => $result->usage->totalTokens,
                    'estimatedCost'    => $result->usage->estimatedCost,
                ],
            ]);
        } catch (ToolApprovalRequiredException $approval) {
            // ADR-084: a called tool requires approval — suspend the run and emit
            // an awaiting-approval event instead of failing.
            $persister = $this->agentRunPersister;
            if ($handle !== null && $persister !== null) {
                $suspended = $persister->suspend($handle, $approval->state);
                $settled   = true;

                if (!$suspended) {
                    // Fail-closed, as in the batch path: no stored state means no
                    // resume, so do not announce an approval flow (ADR-092).
                    $persister->settleFailed($handle, $approval);
                    $this->logger?->error('Agent run could not be suspended for approval; the run was failed instead', ['run' => $handle->uuid]);
                    $emit([
                        'event'   => 'error',
                        'success' => false,
                        'error'   => 'The run required approval but its state could not be stored, so it cannot be resumed.',
                    ]);

                    return;
                }
            }
            $emit([
                'event'        => 'awaiting_approval',
                'success'      => true,
                'runUuid'      => $handle !== null ? $handle->uuid : '',
                'pendingTools' => array_map(
                    static fn(ToolCall $call): array => ['name' => $call->name, 'arguments' => $call->arguments],
                    $approval->state->toolCalls(),
                ),
            ]);
        } catch (GuardrailViolationException|GuardrailApprovalRequiredException $guardrail) {
            // ADR-085/086: a guardrail verdict is a policy outcome, not a failure
            // — settle the run as blocked and emit a distinct terminal event
            // instead of a generic error.
            if ($handle !== null) {
                $this->agentRunPersister?->settlePolicyStopped($handle, $guardrail, $this->guardrailTerminationReason($guardrail));
                $settled = true;
            }
            $this->logger?->warning('Tool playground stream blocked by guardrail', ['exception' => $guardrail]);
            $emit($this->guardrailBlockedEvent($guardrail));
        } catch (Throwable $e) {
            if ($handle !== null) {
                $this->agentRunPersister?->settleFailed($handle, $e);
                $settled = true;
            }
            $this->logger?->error('Tool playground run failed', ['exception' => $e]);
            $emit(['event' => 'error', 'success' => false, 'error' => $this->diagnoseRunFailure($e)]);
        } finally {
            // A client disconnect (or a fatal mid-stream) can abandon the run
            // before either branch settles it; mark it failed so no run is left
            // stuck RUNNING. Mirrors StreamingDispatcher's finally-block settle.
            if ($handle !== null && !$settled) {
                $this->agentRunPersister?->settleFailed($handle, new RuntimeException('Agent run did not complete'));
            }
        }
    }

    /**
     * Echo one NDJSON event and flush it. Encoded with the same UTF-8-substitute
     * guard as {@see self::respondJson()} (a malformed byte must not break the
     * stream) and padded past the proxy flush threshold.
     *
     * @param array<string, mixed> $data
     */
    private function streamLine(array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        // Trailing whitespace is ignored by JSON.parse on the client, so pad the
        // line to the flush threshold with spaces before the newline.
        $padding = self::STREAM_MIN_LINE_BYTES - strlen($json);
        echo $json . ($padding > 0 ? str_repeat(' ', $padding) : '') . "\n";
        flush();
    }

    /**
     * Encode a payload as JSON, substituting invalid UTF-8 instead of throwing.
     *
     * The inspector trace echoes untrusted bytes back to the browser — tool
     * output, injected skill/snippet text, and raw provider responses — any of
     * which may not be valid UTF-8. TYPO3's {@see JsonResponse} encodes with
     * JSON_THROW_ON_ERROR, so a single malformed byte would 500 an otherwise
     * successful run and the UI could only show a bare "Unknown error". The
     * substitute flag turns those bytes into U+FFFD so the inspector always
     * renders.
     *
     * @param array<string, mixed> $data
     */
    private function respondJson(array $data, int $status = 200): ResponseInterface
    {
        $response = new Response('php://temp', $status, ['Content-Type' => 'application/json; charset=utf-8']);
        $response->getBody()->write(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        );
        // Rewind so a consumer reading via getContents() (emitters/middleware)
        // sees the payload rather than an empty stream positioned at EOF.
        $response->getBody()->rewind();

        return $response;
    }

    /**
     * Resolve the current backend user's uid for the budget pre-flight (0 when
     * no real BE user is present — the budget check then treats it as anonymous).
     */
    private function currentBackendUserUid(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 0;
        }
        // BackendUserAuthentication::$user is untyped and may be null before a
        // session is fully loaded (CLI/testing), so guard with is_array().
        $uid = is_array($backendUser->user) ? ($backendUser->user['uid'] ?? 0) : 0;
        return is_numeric($uid) ? (int)$uid : 0;
    }

    private function intFromBody(mixed $body, string $key): int
    {
        if (!is_array($body)) {
            return 0;
        }
        $value = $body[$key] ?? 0;
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * Read an optional float (e.g. temperature, where 0.0 is a valid value so a
     * numeric zero must not be treated as "unset"). Null when absent/non-numeric.
     */
    private function floatFromBody(mixed $body, string $key): ?float
    {
        if (!is_array($body) || !isset($body[$key]) || !is_numeric($body[$key])) {
            return null;
        }

        return (float)$body[$key];
    }

    private function stringFromBody(mixed $body, string $key): string
    {
        if (!is_array($body)) {
            return '';
        }
        $value = $body[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Read the ticked tool checkboxes (`tools[]`) from the run form. Returns the
     * selected names, or null when the form sent no `tools` key at all — the
     * caller then defaults to the globally-enabled set.
     *
     * @return list<string>|null
     */
    private function toolNamesFromBody(mixed $body): ?array
    {
        if (!is_array($body) || !isset($body['tools']) || !is_array($body['tools'])) {
            return null;
        }

        $names = [];
        foreach ($body['tools'] as $value) {
            if (is_string($value) && $value !== '') {
                $names[] = $value;
            }
        }

        return $names;
    }

    private function boolFromBody(mixed $body, string $key): bool
    {
        if (!is_array($body)) {
            return false;
        }
        $value = $body[$key] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * Read a list of integer uids from a repeated body field (e.g.
     * ``forcedSkills[]``/``forcedSnippets[]``). Non-numeric and non-positive
     * entries are dropped.
     *
     * @return list<int>
     */
    private function uidListFromBody(mixed $body, string $key): array
    {
        if (!is_array($body) || !isset($body[$key]) || !is_array($body[$key])) {
            return [];
        }

        $uids = [];
        foreach ($body[$key] as $value) {
            if (is_numeric($value) && (int)$value > 0) {
                $uids[] = (int)$value;
            }
        }

        return $uids;
    }

    /**
     * Resolve forced-skill uids to their {@see Skill} models, preserving
     * request order. A forced skill is applied even when globally disabled —
     * forcing it is the whole point of the debugging control.
     *
     * @param list<int> $uids
     *
     * @return list<Skill>
     */
    private function resolveForcedSkills(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $byUid = [];
        foreach ($this->skillRepository->findAll() as $skill) {
            if ($skill instanceof Skill && $skill->getUid() !== null) {
                $byUid[$skill->getUid()] = $skill;
            }
        }

        $skills = [];
        foreach ($uids as $uid) {
            if (isset($byUid[$uid])) {
                $skills[] = $byUid[$uid];
            }
        }

        return $skills;
    }

    /**
     * Enabled skills offered in the force-inject picker.
     *
     * @return list<Skill>
     */
    private function availableSkills(): array
    {
        $skills = [];
        foreach ($this->skillRepository->findAll() as $skill) {
            if ($skill instanceof Skill && $skill->isEnabled()) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /**
     * Active prompt snippets offered in the force-add picker.
     *
     * @return list<PromptSnippet>
     */
    private function availableSnippets(): array
    {
        $snippets = [];
        foreach ($this->promptSnippetRepository->findAll() as $snippet) {
            if ($snippet instanceof PromptSnippet && $snippet->isActive()) {
                $snippets[] = $snippet;
            }
        }

        return $snippets;
    }

    /**
     * Build an actionable error string for a failed run.
     *
     * The playground is admin-only ({@see RequiresBackendAdminTrait}), so it
     * surfaces the concrete failure — exception class + (secret-sanitised)
     * message, and for an upstream {@see ProviderResponseException} the HTTP
     * status and a truncated, sanitised response-body snippet — instead of a
     * generic "Tool run failed". The full exception is still logged separately.
     */
    private function diagnoseRunFailure(Throwable $e): string
    {
        $class   = (new ReflectionClass($e))->getShortName();
        $message = $this->sanitizeErrorMessage($e->getMessage());
        $detail  = sprintf('%s: %s', $class, $message);

        if ($e instanceof ProviderResponseException) {
            if ($e->httpStatus > 0) {
                $detail .= sprintf(' (HTTP %d)', $e->httpStatus);
            }
            $body = trim($e->responseBody);
            if ($body !== '') {
                $snippet = $this->sanitizeErrorMessage(mb_substr($body, 0, 500));
                $detail .= "\nProvider response: " . $snippet;
            }
        }

        return $detail;
    }
}
