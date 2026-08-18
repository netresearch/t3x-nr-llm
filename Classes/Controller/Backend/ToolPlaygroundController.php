<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Closure;
use Netresearch\NrLlm\Controller\Backend\Response\PlaygroundRunResponse;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\ApprovalNotAuditableException;
use Netresearch\NrLlm\Service\Agent\Exception\ApproverNotPermittedException;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingInputException;
use Netresearch\NrLlm\Service\Agent\Exception\RunStateUnavailableException;
use Netresearch\NrLlm\Service\Agent\Exception\SelfApprovalDeniedException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleInputTurnException;
use Netresearch\NrLlm\Service\Agent\Exception\SubmitterNotPermittedException;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Context\InputContextClassification;
use Netresearch\NrLlm\Service\Context\InputContextClassifier;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ReflectionClass;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\NullResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Admin-only interactive tool playground backend module.
 *
 * Since ADR-101 a pure UI adapter over {@see AgentRuntimeInterface}: it parses
 * the request into an {@see AgentRunRequest} / {@see ApprovalDecision}, lets
 * the runtime own the whole run lifecycle (begin, trace, persist, suspend,
 * settle, resume-claim), and maps the returned {@see AgentRunResult} onto the
 * module's JSON / NDJSON response shapes. {@see listAction()} renders the
 * Fluid shell (config picker, prompt box, tools panel, output pane).
 *
 * Mirrors {@see SkillSourceController}: a `#[AsController]` Extbase
 * ActionController whose list action is the module entry point, gated to
 * admins via the module's ``access => admin`` and (for the AJAX actions) the
 * {@see RequiresBackendAdminTrait} guard.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final class ToolPlaygroundController extends ActionController implements LoggerAwareInterface
{
    use RequiresBackendAdminTrait;
    use LoggerAwareTrait;
    use ErrorMessageSanitizerTrait;
    use DefensiveLocalizationTrait;
    use BackendUserUidTrait;

    /**
     * Minimum byte length of each streamed NDJSON line. A TYPO3 backend AJAX
     * response is buffered by the reverse proxy until a chunk clears its flush
     * threshold; padding every event past ~4 KB makes it flush immediately, so
     * steps reach the browser as they happen instead of all at once at the end.
     */
    private const STREAM_MIN_LINE_BYTES = 4096;

    /**
     * The 500 body when a run required approval but its state could not be
     * stored (fail-closed, ADR-092): no resume must be promised.
     */
    private const SUSPEND_FAILED_MESSAGE = 'The run required approval but its state could not be stored, so it cannot be resumed.';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly AgentRuntimeInterface $agentRuntime,
        private readonly ToolAvailabilityServiceInterface $toolAvailability,
        private readonly SkillRepository $skillRepository,
        private readonly PromptSnippetRepository $promptSnippetRepository,
        // The ONE digest definition (ADR-132): the pause payload shows a turn,
        // so it must also hand out the value that proves which turn was shown.
        private readonly PendingTurnDigest $turnDigest,
        // The same classifier the input-context gate consults (ADR-144). The
        // inspector reads it rather than re-deriving "what does this
        // configuration inject", so the readout and the gate cannot disagree.
        private readonly InputContextClassifier $inputContextClassifier,
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
     * module's ``access => admin`` check (ADR-037). The run itself — loop
     * execution, persistence, suspension, settling — is entirely the
     * {@see AgentRuntimeInterface}'s; this action only builds the
     * {@see AgentRunRequest} from the form and shapes the result. Any
     * unexpected provider/tool failure returns a JSON 500 carrying a SANITIZED
     * diagnosis (exception class + message with secret-bearing URL params
     * stripped via {@see ErrorMessageSanitizerTrait}, plus a truncated
     * sanitized provider response body) — never the raw message verbatim —
     * while the full detail is logged downstream.
     */
    public function runAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
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
        // Cap the per-run round count at the UI ceiling; the runtime clamps to
        // the same constant again server-side (single source of truth).
        $maxRounds    = min($this->intFromBody($body, 'maxRounds'), AgentRuntime::MAX_ITERATIONS);
        $maxTokens    = $this->intFromBody($body, 'maxTokens');
        // Clamp to ChatOptions' valid range: an out-of-range value would throw
        // an InvalidArgumentException from the ToolOptions constructor below —
        // before the runtime's ladder — and 500 the request.
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
            think: $think,
            captureRaw: $captureRaw,
        );

        // The checked tool boxes restrict this run; absent any selection, fall
        // back to the globally-enabled set. The runtime gate is authoritative
        // regardless (a disabled tool stays off, ADR-093).
        $selected = $this->toolNamesFromBody($body) ?? $this->toolAvailability->enabledNames();

        $forcedSkills   = $this->resolveForcedSkills($this->uidListFromBody($body, 'forcedSkills'));
        $forcedSnippets = $this->promptSnippetRepository->findByUids($this->uidListFromBody($body, 'forcedSnippets'));

        $agentRequest = new AgentRunRequest(
            configuration: $config,
            messages: [ChatMessage::user($prompt)],
            actor: $this->currentActor(),
            allowedToolNames: $selected,
            options: $options,
            maxIterations: $maxRounds > 0 ? $maxRounds : null,
            augmentation: new RunAugmentation(
                forcedSkills: $forcedSkills,
                forcedSnippets: $forcedSnippets,
                dryRun: $dryRun,
            ),
            captureRaw: $captureRaw,
        );

        // How sensitive what this run injects is (ADR-151). Fixed for the whole
        // run — the configuration's sources plus this run's forced ones — so it
        // is computed once here rather than per round.
        $classification = $this->contextClassification($config, $forcedSnippets, $forcedSkills);

        // Live path: stream each recorded step to the browser as it happens.
        if ($this->boolFromBody($body, 'stream')) {
            return $this->runStreamed($agentRequest, $dryRun, $classification);
        }

        // Batch path: run the whole loop, then return the full trace as one JSON
        // document (the no-JS fallback and the shape the functional tests assert).
        return $this->respondToResult($this->agentRuntime->run($agentRequest), $dryRun, $classification);
    }

    /**
     * What this RUN injects, source by source, with the class each source
     * declared and the strictest one across them (ADR-151).
     *
     * The run, not the configuration: a playground run also injects the forced
     * snippets and skills the operator ticked, and those reach the wire exactly
     * like the configuration's own. A readout derived from the configuration
     * alone reported "injects nothing" for a run carrying a SECRET_ADJACENT
     * snippet — under-reporting, in a panel whose whole job is data
     * classification.
     *
     * Everything comes from {@see InputContextClassifier} — the service the
     * ADR-144 gate itself consults — so the panel cannot show a configuration
     * as unclassified that the gate would refuse. The reverse is possible and
     * intended: the gate asks {@see InputContextClassifier::classify()}, which
     * answers for the configuration alone, so a forced source shows up here and
     * is NOT gated. Source NAMES only: the classification exists because the
     * text is sensitive, and echoing it into an admin panel to explain why it
     * must not leave the installation would be the same leak by another route.
     *
     * One pass over the sources, folded here — {@see InputContextClassifier::classify()}
     * would fetch the same list a second time to answer the same question.
     *
     * @param list<PromptSnippet> $forcedSnippets
     * @param list<Skill>         $forcedSkills
     *
     * @return array{sources: list<array{source: string, dataClass: string|null}>, effective: string|null, effectiveSource: string}
     */
    private function contextClassification(LlmConfiguration $configuration, array $forcedSnippets = [], array $forcedSkills = []): array
    {
        $sources   = $this->inputContextClassifier->sources($configuration, $forcedSnippets, $forcedSkills);
        $effective = InputContextClassifier::strictest($sources);

        return [
            'sources' => array_map(
                static fn(InputContextClassification $source): array => [
                    'source'    => $source->source,
                    'dataClass' => $source->effective?->value,
                ],
                $sources,
            ),
            'effective'       => $effective->effective?->value,
            'effectiveSource' => $effective->source,
        ];
    }

    /**
     * Resume a run suspended for human approval (ADR-084).
     *
     * Admin-gated like {@see runAction()}. The load/validate/claim/resume
     * lifecycle is {@see AgentRuntimeInterface::approve()}'s; this action maps
     * its typed request-validation exceptions onto the module's HTTP statuses
     * and the continued run's result — which may itself suspend again — onto
     * the same payload shapes as a fresh run.
     */
    public function resumeAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $body     = $request->getParsedBody();
        $runUuid  = trim($this->stringFromBody($body, 'runUuid'));
        $approved = $this->boolFromBody($body, 'approve');
        // The digest of the turn the caller was shown (ADR-132). Passed through
        // verbatim; the runtime compares it against the claimed state. An empty
        // body value becomes null, which the runtime refuses like a mismatch —
        // a client that does not send it cannot approve.
        $digest = trim($this->stringFromBody($body, 'turnDigest'));

        try {
            $result = $this->agentRuntime->approve(
                $this->currentActor(),
                $runUuid,
                new ApprovalDecision($approved, $this->currentBackendUserUid(), $digest !== '' ? $digest : null),
            );
        } catch (RunNotAwaitingApprovalException) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.notAwaitingApproval', 'No run is awaiting approval for that id.')], 400);
        } catch (RunConfigurationGoneException) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.configGone', 'The run configuration no longer exists.')], 400);
        } catch (StaleApprovalTurnException) {
            // Retryable, like an invalid input submission: the run was released
            // back to its approval pause, so re-signal awaiting_approval and let
            // the client re-fetch and re-review the CURRENT turn.
            return $this->respondJson([
                'success' => false,
                'status'  => 'awaiting_approval',
                'runUuid' => $runUuid,
                'error'   => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.staleApproval', 'The pending action changed since you viewed it — please re-review.'),
            ], 409);
        } catch (ApproverNotPermittedException) {
            // ADR-133: the decider may not run the pending write themselves.
            // Nothing was executed and the run is decidable again, so
            // awaiting_approval is re-signalled — but 403, because the obstacle
            // is who is asking, not the request or the store.
            return $this->respondJson([
                'success' => false,
                'status'  => 'awaiting_approval',
                'runUuid' => $runUuid,
                'error'   => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.approverNotPermitted', 'You may not approve an action that runs a tool you are not permitted to use yourself.'),
            ], 403);
        } catch (SelfApprovalDeniedException) {
            // ADR-172: the caller started this run and the configuration wants a
            // second approver. In the Playground the caller is ALWAYS the
            // initiator, so a write-declaring turn on such a configuration can
            // only be released from the Agent Runs inbox by somebody else. Same
            // shape as above: nothing ran, the run is decidable again, 403
            // because the obstacle is who is asking.
            return $this->respondJson([
                'success' => false,
                'status'  => 'awaiting_approval',
                'runUuid' => $runUuid,
                'error'   => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.selfApproval', 'You started this run and its configuration requires a second approver. Someone else has to release it.'),
            ], 403);
        } catch (ApprovalNotAuditableException) {
            // Nothing was executed and the run is decidable again; 503, because
            // the obstacle is the audit store, not the request.
            return $this->respondJson([
                'success' => false,
                'status'  => 'awaiting_approval',
                'runUuid' => $runUuid,
                'error'   => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.approvalNotAuditable', 'The decision could not be recorded, so this write was not executed. Please try again.'),
            ], 503);
        } catch (CorruptSuspendedStateException|RunStateUnavailableException) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.corruptState', 'The suspended run state could not be read.')], 500);
        } catch (RunAlreadyResumingException) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.alreadyResuming', 'This run is already being processed.')], 409);
        }

        return $this->respondToResult($result, false);
    }

    /**
     * Submit typed input for a run suspended WAITING_FOR_INPUT (ADR-105) and
     * continue it. The input sibling of {@see resumeAction()}: admin-gated (the
     * injection mitigation for the untrusted submitted values), it maps
     * {@see AgentRuntimeInterface::submitInput()}'s typed request-validation
     * exceptions onto the module's HTTP statuses. An invalid submission returns
     * 422 while re-signalling ``awaiting_input`` so the client keeps the form
     * open for a resubmission (the run is untouched, nothing was claimed).
     */
    public function submitInputAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $body    = $request->getParsedBody();
        $runUuid = trim($this->stringFromBody($body, 'runUuid'));
        // The digest of the turn the caller was shown (ADR-150), the input
        // sibling of resumeAction()'s. Passed through verbatim; the runtime
        // compares it against the claimed state. An empty body value becomes
        // null, which the runtime refuses like a mismatch — a client that does
        // not send it cannot submit.
        $digest = trim($this->stringFromBody($body, 'turnDigest'));

        try {
            $result = $this->agentRuntime->submitInput(
                $this->currentActor(),
                $runUuid,
                new InputSubmission($this->inputDataFromBody($body), $this->currentBackendUserUid(), $digest !== '' ? $digest : null),
            );
        } catch (RunNotAwaitingInputException) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.notAwaitingInput', 'No run is awaiting input for that id.')], 400);
        } catch (RunConfigurationGoneException) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.configGone', 'The run configuration no longer exists.')], 400);
        } catch (InvalidInputSubmissionException) {
            // Retryable: the run stays WAITING_FOR_INPUT, nothing was claimed.
            // Re-signal awaiting_input so the client keeps the form open.
            return $this->respondJson([
                'success' => false,
                'status'  => 'awaiting_input',
                'runUuid' => $runUuid,
                'error'   => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.inputSchemaMismatch', 'The submitted input did not match the required schema.'),
            ], 422);
        } catch (StaleInputTurnException) {
            // Retryable like an invalid submission: the run was released back to
            // its input pause, so re-signal awaiting_input and let the client
            // re-fetch the CURRENT turn and its digest.
            return $this->respondJson([
                'success' => false,
                'status'  => 'awaiting_input',
                'runUuid' => $runUuid,
                'error'   => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.staleInput', 'The pending action changed since you viewed it — please re-open the form.'),
            ], 409);
        } catch (SubmitterNotPermittedException) {
            // ADR-150: the submitter may not run the tool they are feeding.
            // Nothing was executed and the run is submittable again, so
            // awaiting_input is re-signalled — but 403, because the obstacle is
            // who is asking.
            return $this->respondJson([
                'success' => false,
                'status'  => 'awaiting_input',
                'runUuid' => $runUuid,
                'error'   => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.submitterNotPermitted', 'You may not supply input for a tool you are not permitted to use yourself.'),
            ], 403);
        } catch (CorruptSuspendedStateException|RunStateUnavailableException) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.corruptState', 'The suspended run state could not be read.')], 500);
        } catch (RunAlreadyResumingException) {
            return $this->respondJson(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.alreadyResuming', 'This run is already being processed.')], 409);
        }

        return $this->respondToResult($result, false);
    }

    /**
     * Map a settled {@see AgentRunResult} onto the module's batch JSON shapes
     * — the ADR-041 contract the functional tests assert.
     *
     * @param array{sources: list<array{source: string, dataClass: string|null}>, effective: string|null, effectiveSource: string}|null $contextClassification null on the resume paths, which continue a run whose classification the initiating response already carried
     */
    private function respondToResult(AgentRunResult $result, bool $dryRun, ?array $contextClassification = null): ResponseInterface
    {
        switch ($result->outcome) {
            case AgentRunOutcome::AWAITING_APPROVAL:
                return $this->respondJson($this->awaitingApprovalPayload($result));

            case AgentRunOutcome::AWAITING_INPUT:
                return $this->respondJson($this->awaitingInputPayload($result));

            case AgentRunOutcome::SUSPEND_FAILED:
                return $this->respondJson(['success' => false, 'error' => self::SUSPEND_FAILED_MESSAGE], 500);

            case AgentRunOutcome::GUARDRAIL_BLOCKED:
            case AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED:
                // A guardrail DENY or REQUIRE_APPROVAL is a policy outcome, not
                // a server error — the HTTP status stays 200 with success:false
                // (ADR-086). The runtime already logged it with the run uuid.
                return $this->respondJson($this->guardrailBlockedPayload($result));

            case AgentRunOutcome::FAILED:
                // Logged by the runtime; the controller only shapes the (admin-
                // only, sanitized) diagnosis.
                return $this->respondJson(['success' => false, 'error' => $this->diagnoseRunFailure($result->error)], 500);

            case AgentRunOutcome::CANCELLED:
                // ADR-103: an operator cancelled the run mid-flight and the
                // loop stopped cooperatively — a decision, not a server error.
                return $this->respondJson([
                    'success' => false,
                    'status'  => 'cancelled',
                    'error'   => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.cancelled', 'The run was cancelled.'),
                    'steps'   => array_map(static fn(RunStep $step): array => $step->toArray(), $result->steps),
                ]);

            case AgentRunOutcome::COMPLETED:
                break;

            default:
                // A future outcome from a newer runtime (the enum may grow in
                // minor releases) must not fall through to the completed
                // payload.
                $this->logger?->error('Unhandled agent run outcome', ['outcome' => $result->outcome->value]);

                return $this->respondJson(['success' => false, 'error' => 'The run ended with an unhandled outcome.'], 500);
        }

        $loop = $result->loopResult;
        if (!$loop instanceof ToolLoopResult) {
            // Contract violation: a COMPLETED result always carries the loop
            // result. Surface it as a failure instead of fabricating a payload.
            $this->logger?->error('Completed agent run carried no loop result', ['run' => $result->runUuid]);

            return $this->respondJson(['success' => false, 'error' => 'The run completed but produced no result.'], 500);
        }

        $response = new PlaygroundRunResponse(
            finalContent: $loop->finalContent,
            iterations: $loop->iterations,
            truncated: $loop->truncated,
            dryRun: $dryRun,
            steps: $result->steps,
            promptTokens: $loop->usage->promptTokens,
            completionTokens: $loop->usage->completionTokens,
            totalTokens: $loop->usage->totalTokens,
            estimatedCost: $loop->usage->estimatedCost,
            contextClassification: $contextClassification,
        );

        return $this->respondJson($response->toArray());
    }

    /**
     * The JSON body for a run that suspended for approval: the pending tool calls
     * the operator must approve, the digest binding a later decision to exactly
     * those calls (ADR-132), plus the steps recorded up to the pause.
     *
     * @return array<string, mixed>
     */
    private function awaitingApprovalPayload(AgentRunResult $result): array
    {
        return [
            'success'      => true,
            'status'       => 'awaiting_approval',
            'runUuid'      => $result->runUuid,
            'pendingTools' => $this->pendingTools($result),
            'turnDigest'   => $this->turnDigestFor($result),
            'steps'        => array_map(static fn(RunStep $step): array => $step->toArray(), $result->steps),
        ];
    }

    /**
     * The digest of the turn this pause is showing, or '' when the result
     * carries no state (a suspension that failed to store never gets here, but
     * the property is nullable). A client that echoes '' back is refused by the
     * runtime exactly like a stale digest.
     */
    private function turnDigestFor(AgentRunResult $result): string
    {
        $state = $result->suspendedState;
        if (!$state instanceof SuspendedRunState) {
            return '';
        }

        // The two pauses are bound by different digests (ADR-150), and the state
        // says which pause this is. Emitting the approval digest for an input
        // pause would hand the client a value the runtime never accepts.
        return $state->isInputPause()
            ? $this->turnDigest->forInputState($state)
            : $this->turnDigest->forState($state);
    }

    /**
     * The pending calls, each with the preview the loop captured when the run
     * suspended (ADR-136). Used by BOTH approval surfaces — the batch payload
     * and the streamed event — so a client cannot get the calls on one and the
     * preview only on the other.
     *
     * `preview.failed` marks lines that state WHY there is no preview rather
     * than what the call would do; a client that ignores the flag still shows a
     * line and never a blank.
     *
     * @return list<array{name: string, arguments: array<string, mixed>, preview: array{lines: list<string>, failed: bool}}>
     */
    private function pendingTools(AgentRunResult $result): array
    {
        $state    = $result->suspendedState;
        $previews = [];
        foreach ($state instanceof SuspendedRunState ? $state->callPreviews : [] as $preview) {
            $previews[$preview['index']] = $preview;
        }

        $tools = [];
        foreach ($state?->toolCalls() ?? [] as $index => $call) {
            $preview = $previews[$index] ?? null;
            $match   = $preview !== null && $preview['tool'] === $call->name;
            $tools[] = [
                'name'      => $call->name,
                'arguments' => $call->arguments,
                'preview'   => [
                    'lines'  => $match ? $preview['lines'] : [],
                    'failed' => $match && $preview['failed'],
                ],
            ];
        }

        return $tools;
    }

    /**
     * The JSON body for a run that suspended for typed input (ADR-105): the
     * target tool and its declared input schema the client renders a form from,
     * the digest binding a later submission to exactly that form (ADR-150), plus
     * the steps recorded up to the pause.
     *
     * @return array<string, mixed>
     */
    private function awaitingInputPayload(AgentRunResult $result): array
    {
        $state = $result->suspendedState;

        return [
            'success'      => true,
            'status'       => 'awaiting_input',
            'runUuid'      => $result->runUuid,
            'inputRequest' => [
                'tool'   => $state instanceof SuspendedRunState ? ($state->inputToolName ?? '') : '',
                'schema' => $state instanceof SuspendedRunState ? $state->inputSchema : [],
            ],
            'turnDigest'   => $this->turnDigestFor($result),
            'steps'        => array_map(static fn(RunStep $step): array => $step->toArray(), $result->steps),
        ];
    }

    /**
     * Read the submitted input object (the `input` body field) as a
     * string-keyed map. Non-object payloads degrade to an empty map, which the
     * schema validation then rejects — never trusted through.
     *
     * @return array<string, mixed>
     */
    private function inputDataFromBody(mixed $body): array
    {
        if (!is_array($body) || !isset($body['input']) || !is_array($body['input'])) {
            return [];
        }

        $data = [];
        foreach ($body['input'] as $key => $value) {
            $data[(string)$key] = $value;
        }

        return $data;
    }

    /**
     * The JSON body for a run a guardrail blocked (ADR-086): the status
     * (denied vs flagged for approval), the deciding guardrail FQCN and its
     * reason, plus the steps recorded up to the block.
     *
     * @return array<string, mixed>
     */
    private function guardrailBlockedPayload(AgentRunResult $result): array
    {
        return [
            'success'   => false,
            'status'    => $result->outcome->value,
            'guardrail' => $result->guardrailClass ?? '',
            'error'     => $result->error?->getMessage() ?? '',
            'steps'     => array_map(static fn(RunStep $step): array => $step->toArray(), $result->steps),
        ];
    }

    /**
     * Run the loop while streaming one NDJSON line per recorded step to the
     * browser as it happens, then a final ``done`` (or ``error``) line.
     *
     * Output buffering and compression are disabled and each line is padded
     * (see {@see self::STREAM_MIN_LINE_BYTES}) so the reverse proxy flushes it
     * immediately; a {@see NullResponse} is returned so TYPO3 emits nothing
     * further (it has already been sent). The step callback runs inside the
     * runtime's trace, fired the moment each step is recorded.
     *
     * @param array{sources: list<array{source: string, dataClass: string|null}>, effective: string|null, effectiveSource: string} $contextClassification
     */
    private function runStreamed(AgentRunRequest $request, bool $dryRun, array $contextClassification): ResponseInterface
    {
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
            $request,
            $dryRun,
            $contextClassification,
        );

        return new NullResponse();
    }

    /**
     * Run the loop and emit the streamed protocol through {@see $emit}: one
     * ``step`` event per recorded step (live, via the runtime's step observer),
     * then a terminal ``done`` — or ``error`` / ``awaiting_approval`` /
     * guardrail event — mapped from the settled {@see AgentRunResult}.
     * Transport-agnostic (no output buffering, headers or flushing), so the
     * event sequence can be asserted in isolation from the echo/flush plumbing
     * in {@see runStreamed()}.
     *
     * @param Closure(array<string, mixed>): void                                                                                  $emit
     * @param array{sources: list<array{source: string, dataClass: string|null}>, effective: string|null, effectiveSource: string} $contextClassification
     */
    private function streamRun(Closure $emit, AgentRunRequest $request, bool $dryRun, array $contextClassification): void
    {
        // Emitted BEFORE the run: the classification is a property of the
        // configuration, not of the outcome, so a run that fails or suspends
        // must still have told the operator what it was about to send.
        $emit(['event' => 'context', 'contextClassification' => $contextClassification]);

        $result = $this->agentRuntime->run(
            $request,
            static function (RunStep $step) use ($emit): void {
                $emit(['event' => 'step', 'step' => $step->toArray()]);
            },
        );

        switch ($result->outcome) {
            case AgentRunOutcome::AWAITING_APPROVAL:
                // ADR-084: a called tool requires approval — the run suspended
                // instead of failing.
                $emit([
                    'event'        => 'awaiting_approval',
                    'success'      => true,
                    'runUuid'      => $result->runUuid,
                    'pendingTools' => $this->pendingTools($result),
                    // The streamed pause carries the same binding as the batch
                    // payload (ADR-132) — both surfaces show a turn, so both
                    // must be able to prove which turn was shown.
                    'turnDigest'   => $this->turnDigestFor($result),
                ]);

                return;

            case AgentRunOutcome::AWAITING_INPUT:
                // ADR-105: a called tool requires typed input — the run
                // suspended for a submitInput() instead of failing.
                $inputState = $result->suspendedState;
                $emit([
                    'event'        => 'awaiting_input',
                    'success'      => true,
                    'runUuid'      => $result->runUuid,
                    'inputRequest' => [
                        'tool'   => $inputState instanceof SuspendedRunState ? ($inputState->inputToolName ?? '') : '',
                        'schema' => $inputState instanceof SuspendedRunState ? $inputState->inputSchema : [],
                    ],
                    // The streamed pause carries the same binding as the batch
                    // payload (ADR-150) — both surfaces show a form, so both
                    // must let the client prove which form was shown.
                    'turnDigest'   => $this->turnDigestFor($result),
                ]);

                return;

            case AgentRunOutcome::SUSPEND_FAILED:
                // Fail-closed, as in the batch path: no stored state means no
                // resume, so do not announce an approval flow (ADR-092).
                $emit(['event' => 'error', 'success' => false, 'error' => self::SUSPEND_FAILED_MESSAGE]);

                return;

            case AgentRunOutcome::GUARDRAIL_BLOCKED:
            case AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED:
                // ADR-085/086: a guardrail verdict is a policy outcome, not a
                // failure — a distinct terminal event instead of a generic
                // error. The runtime already logged it with the run uuid.
                $emit([
                    'event'     => $result->outcome->value,
                    'success'   => false,
                    'status'    => $result->outcome->value,
                    'guardrail' => $result->guardrailClass ?? '',
                    'error'     => $result->error?->getMessage() ?? '',
                ]);

                return;

            case AgentRunOutcome::FAILED:
                // Logged by the runtime; only the sanitized diagnosis is shaped
                // here.
                $emit(['event' => 'error', 'success' => false, 'error' => $this->diagnoseRunFailure($result->error)]);

                return;

            case AgentRunOutcome::CANCELLED:
                // ADR-103: a distinct terminal event — the operator stopped the
                // run; not a failure.
                $emit([
                    'event'   => 'cancelled',
                    'success' => false,
                    'status'  => 'cancelled',
                    'error'   => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.cancelled', 'The run was cancelled.'),
                ]);

                return;

            case AgentRunOutcome::COMPLETED:
                break;

            default:
                // A future outcome from a newer runtime must not fall through
                // to the done event.
                $this->logger?->error('Unhandled agent run outcome', ['outcome' => $result->outcome->value]);
                $emit(['event' => 'error', 'success' => false, 'error' => 'The run ended with an unhandled outcome.']);

                return;
        }

        $loop = $result->loopResult;
        if (!$loop instanceof ToolLoopResult) {
            // Contract violation: a COMPLETED result always carries the loop
            // result. Emit an error line instead of fabricating a done event.
            $this->logger?->error('Completed agent run carried no loop result', ['run' => $result->runUuid]);
            $emit(['event' => 'error', 'success' => false, 'error' => 'The run completed but produced no result.']);

            return;
        }

        $emit([
            'event'        => 'done',
            'success'      => true,
            'finalContent' => $loop->finalContent,
            'iterations'   => $loop->iterations,
            'truncated'    => $loop->truncated,
            'dryRun'       => $dryRun,
            'usage'        => [
                'promptTokens'     => $loop->usage->promptTokens,
                'completionTokens' => $loop->usage->completionTokens,
                'totalTokens'      => $loop->usage->totalTokens,
                'estimatedCost'    => $loop->usage->estimatedCost,
            ],
        ]);
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

        return in_array($value, [true, 1, '1', 'true'], true);
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
        return $this->skillRepository->findByUids($uids);
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
    private function diagnoseRunFailure(?Throwable $e): string
    {
        if (!$e instanceof Throwable) {
            return 'Unknown error';
        }

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
