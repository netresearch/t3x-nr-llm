<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
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
use Netresearch\NrLlm\Service\Agent\Exception\StaleApprovalTurnException;
use Netresearch\NrLlm\Service\Agent\Exception\StaleInputTurnException;
use Netresearch\NrLlm\Service\Agent\Exception\SubmitterNotPermittedException;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
use Netresearch\NrLlm\Service\Agent\Timeline\RunTimelineFactory;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\SchemaInputCoercer;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The "Agent Runs" approvals inbox (ADR-109): the human-facing surface for runs
 * suspended WAITING_FOR_APPROVAL (ADR-084) or WAITING_FOR_INPUT (ADR-105).
 *
 * The four actions are module-route controllerActions, reachable through TWO
 * modules since ADR-131: the admin inbox (`nrllm_runs`, `access => admin`) and
 * the editor module (`nrllm_aitasks`, `access => user`). Unlike the AJAX
 * endpoints on {@see ToolPlaygroundController}, a module-route action cannot
 * be reached without module access, so RequiresBackendAdminTrait is not
 * needed here (and its JSON 403 body would be wrong for an HTML page).
 * Visibility is actor-scoped: an admin or an `agent_approve` grant holder
 * sees every run, everyone else only their own — and the WRITE side is
 * independently authorised per run by
 * {@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext::mayActOnRun()},
 * so the list filter is a viewport, never the security boundary. The recorded
 * decidedBy/submittedBy uid is audit-only.
 *
 * showAction is read-only and authorised per run by the runtime with
 * `ServiceAccountScope::AGENT_READ`, not by the module access string — and READ
 * has no grant equivalent, so the approval grant widens the list but not the
 * detail page (ADR-153).
 *
 * The page works fully with JavaScript OFF: native `<f:form>` POST, a
 * POST-redirect-GET flush with session flash messages, and a 422 in-place
 * re-render that preserves the operator's raw input. The CSRF defence is the
 * backend module route token the `<f:form action=...>` URL carries (validated by
 * the RouteDispatcher), NOT `__trustedProperties`.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final class AgentRunController extends ActionController
{
    use DefensiveLocalizationTrait;
    use BackendUserUidTrait;

    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly AgentRunPersister $persister,
        private readonly WaitingRunViewFactory $viewFactory,
        private readonly SchemaInputCoercer $coercer,
        private readonly AgentRuntimeInterface $agentRuntime,
        private readonly PageRenderer $pageRenderer,
        private readonly RunTimelineFactory $timelineFactory,
    ) {}

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        $this->moduleTemplate->makeDocHeaderModuleMenu();
        // Progressive enhancement only: the page is fully operable without it.
        // The module moves focus to a 422 error summary reliably and confirms a
        // Deny before it submits.
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/AgentRunInbox.js');
    }

    /**
     * The inbox: waiting runs (actionable) + recent terminal runs (read-only).
     *
     * @param array<string, mixed> $rawInput preserved raw POST on a 422 re-render
     */
    public function listAction(string $errorRunUuid = '', array $rawInput = [], string $errorSummary = ''): ResponseInterface
    {
        return $this->renderList($errorRunUuid, $rawInput, $errorSummary);
    }

    /**
     * One run, end to end and READ-ONLY (ADR-153): its persisted step stream,
     * the provider calls it caused and the governance decisions taken during it,
     * in one ordered timeline.
     *
     * Authorisation is the runtime's: {@see AgentRuntimeInterface::status()} and
     * {@see AgentRuntimeInterface::events()} both go through
     * {@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext::mayActOnRun()},
     * so a run this user may not read is indistinguishable from an unknown one —
     * both redirect to the list. No repository is read from here (the tail of
     * the timeline is assembled by {@see RunTimelineFactory} from the run the
     * runtime already released).
     */
    public function showAction(string $runUuid = ''): ResponseInterface
    {
        $actor = $this->currentActor();
        $run   = $runUuid === '' ? null : $this->agentRuntime->status($actor, $runUuid);

        if (!$run instanceof AgentRun) {
            return $this->flashRedirect('runs.detail.notFound', ContextualFeedbackSeverity::WARNING);
        }

        $this->moduleTemplate->assignMultiple([
            'run'      => $run,
            'timeline' => $this->timelineFactory->build($run, $this->agentRuntime->events($actor, $runUuid)),
        ]);

        return $this->moduleTemplate->renderResponse('Backend/AgentRun/Show');
    }

    /**
     * Approve or deny the pending tool call of a run suspended WAITING_FOR_APPROVAL.
     */
    public function approveAction(string $runUuid = '', bool $approve = false, string $turnDigest = ''): ResponseInterface
    {
        if ($runUuid === '') {
            $this->flash('runs.flash.error', ContextualFeedbackSeverity::WARNING);

            return $this->redirect('list');
        }

        try {
            // The stale-review guard is NOT re-implemented here (ADR-132): the
            // digest the card carried travels with the decision and is verified
            // against the freshly CLAIMED state inside the runtime. A check here
            // would read the row before the claim and could pass on a turn the
            // winner of a concurrent approval has already replaced.
            $result = $this->agentRuntime->approve(
                $this->currentActor(),
                $runUuid,
                new ApprovalDecision($approve, $this->currentBackendUserUid(), $turnDigest),
            );
        } catch (RunNotAwaitingApprovalException) {
            return $this->flashRedirect('runs.flash.error', ContextualFeedbackSeverity::WARNING);
        } catch (RunConfigurationGoneException) {
            return $this->flashRedirect('runs.flash.configGone', ContextualFeedbackSeverity::ERROR);
        } catch (RunAlreadyResumingException) {
            return $this->flashRedirect('runs.flash.alreadyResuming', ContextualFeedbackSeverity::WARNING);
        } catch (StaleApprovalTurnException) {
            // The run was released, not consumed — re-review and decide again.
            return $this->flashRedirect('runs.error.staleReview', ContextualFeedbackSeverity::WARNING);
        } catch (ApproverNotPermittedException) {
            // ADR-133: the run was released, not consumed — someone who may run
            // the pending write can still decide it.
            return $this->flashRedirect('runs.error.approverNotPermitted', ContextualFeedbackSeverity::ERROR);
        } catch (ApprovalNotAuditableException) {
            return $this->flashRedirect('runs.error.notAuditable', ContextualFeedbackSeverity::ERROR);
        } catch (CorruptSuspendedStateException|RunStateUnavailableException) {
            return $this->flashRedirect('runs.unreadable', ContextualFeedbackSeverity::ERROR);
        }

        $this->flashOutcome($result, $approve);

        return $this->redirect('list');
    }

    /**
     * Submit typed input for a run suspended WAITING_FOR_INPUT and continue it.
     *
     * @param array<string, mixed> $input      the form's raw `input[...]` values (all strings)
     * @param string               $turnDigest the digest of the turn the form was rendered from
     *                                         (ADR-150). Passed through verbatim, like the approval
     *                                         form's; the runtime compares it against the CLAIMED
     *                                         state, which a check here could not do
     */
    public function submitInputAction(string $runUuid = '', array $input = [], string $turnDigest = ''): ResponseInterface
    {
        if ($runUuid === '') {
            $this->flash('runs.flash.error', ContextualFeedbackSeverity::WARNING);

            return $this->redirect('list');
        }

        // Reload the CURRENT schema and coerce the all-strings POST against it,
        // so the no-JS path validates. Never coerce against a broken schema.
        $run = $this->persister->findRun($runUuid);
        $schema = $run instanceof AgentRun ? $this->viewFactory->inputSchemaForRun($run) : null;
        if ($schema === null) {
            $this->flash('runs.unreadable', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }

        $data = $this->coercer->coerce($input, $schema);

        try {
            $result = $this->agentRuntime->submitInput(
                $this->currentActor(),
                $runUuid,
                new InputSubmission($data, $this->currentBackendUserUid(), $turnDigest !== '' ? $turnDigest : null),
            );
        } catch (InvalidInputSubmissionException) {
            // The ONE render-not-redirect branch: the run is untouched and still
            // WAITING_FOR_INPUT. Re-render in place with a focusable error
            // summary and the operator's raw entries preserved, HTTP 422.
            return $this->renderList($runUuid, $input, $this->localize(self::LL . 'runs.error.schemaMismatch', 'The submitted input did not match the required schema.'))
                ->withStatus(422);
        } catch (RunNotAwaitingInputException) {
            return $this->flashRedirect('runs.flash.error', ContextualFeedbackSeverity::WARNING);
        } catch (RunConfigurationGoneException) {
            return $this->flashRedirect('runs.flash.configGone', ContextualFeedbackSeverity::ERROR);
        } catch (RunAlreadyResumingException) {
            return $this->flashRedirect('runs.flash.alreadyResuming', ContextualFeedbackSeverity::WARNING);
        } catch (StaleInputTurnException) {
            // ADR-150: the run was released, not consumed — re-open the CURRENT
            // form and submit again. Same wording as the approval path's stale
            // turn, because it is the same fact.
            return $this->flashRedirect('runs.error.staleReview', ContextualFeedbackSeverity::WARNING);
        } catch (SubmitterNotPermittedException) {
            // ADR-150: the run was released, not consumed — someone who may run
            // the pending tool can still supply its input.
            return $this->flashRedirect('runs.error.submitterNotPermitted', ContextualFeedbackSeverity::ERROR);
        } catch (CorruptSuspendedStateException|RunStateUnavailableException) {
            return $this->flashRedirect('runs.unreadable', ContextualFeedbackSeverity::ERROR);
        }

        $this->flashOutcome($result, true);

        return $this->redirect('list');
    }

    /**
     * @param array<string, mixed> $rawInput
     */
    private function renderList(string $errorRunUuid, array $rawInput, string $errorSummary): ResponseInterface
    {
        // Actor-scoped viewport (ADR-131): admins and approval-grant holders
        // see every run, everyone else only their own. The write side stays
        // independently authorised per run by mayActOnRun(), so this filter
        // shapes the list, never the security boundary. An absent BE user
        // degrades to uid 0 = empty lists (fail-closed).
        $actor      = $this->currentActor();
        $restrictTo = ($actor->isAdmin || $actor->hasGrant(BackendUserGrant::AGENT_APPROVE))
            ? null
            : $actor->backendUserUid;

        $waitingRuns  = $this->persister->findAwaitingRuns(beUser: $restrictTo);
        $terminalRuns = $this->persister->findRecentTerminalRuns(beUser: $restrictTo);
        $dataLoadError = $waitingRuns === null || $terminalRuns === null;

        // The preview a card carries is authorised a second time, per record,
        // against THIS request's user (ADR-136): the approval grant above is
        // tool-level and says nothing about individual pages. The ambient user
        // is the right source here — it IS the viewer, not the run's actor,
        // which is what ADR-083 forbids reading around.
        $viewer = $GLOBALS['BE_USER'] ?? null;

        $this->moduleTemplate->assignMultiple([
            'waiting'        => $this->viewFactory->buildWaiting($waitingRuns ?? [], $viewer instanceof BackendUserAuthentication ? $viewer : null),
            'terminal'       => $this->viewFactory->buildTerminal($terminalRuns ?? [], $actor),
            'dataLoadError'  => $dataLoadError,
            'errorRunUuid'   => $errorRunUuid,
            'rawInput'       => $rawInput,
            'errorSummary'   => $errorSummary,
            'restrictedView' => $restrictTo !== null,
        ]);

        return $this->moduleTemplate->renderResponse('Backend/AgentRun/List');
    }

    /**
     * Map a settled {@see AgentRunResult} onto a flash message. AgentRunOutcome
     * is non-exhaustive (the queue epic adds cases), so the default arm must
     * surface — never silently swallow — an unexpected outcome.
     */
    private function flashOutcome(AgentRunResult $result, bool $approved): void
    {
        [$key, $severity] = match ($result->outcome) {
            AgentRunOutcome::COMPLETED => [
                $approved ? 'runs.flash.approved' : 'runs.flash.denied',
                ContextualFeedbackSeverity::OK,
            ],
            AgentRunOutcome::AWAITING_APPROVAL, AgentRunOutcome::AWAITING_INPUT => [
                'runs.flash.waitingAgain',
                ContextualFeedbackSeverity::INFO,
            ],
            AgentRunOutcome::GUARDRAIL_BLOCKED, AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED => [
                'runs.flash.error',
                ContextualFeedbackSeverity::WARNING,
            ],
            AgentRunOutcome::CANCELLED => ['runs.flash.error', ContextualFeedbackSeverity::INFO],
            default => ['runs.flash.error', ContextualFeedbackSeverity::ERROR],
        };

        $this->flash($key, $severity);
    }

    private function flashRedirect(string $key, ContextualFeedbackSeverity $severity): ResponseInterface
    {
        $this->flash($key, $severity);

        return $this->redirect('list');
    }

    private function flash(string $key, ContextualFeedbackSeverity $severity): void
    {
        // storeInSession = true (the default) so the message survives the PRG.
        $this->addFlashMessage($this->localize(self::LL . $key, $key), '', $severity);
    }
}
