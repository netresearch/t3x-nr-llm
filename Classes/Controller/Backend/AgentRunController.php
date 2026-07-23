<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\CorruptSuspendedStateException;
use Netresearch\NrLlm\Service\Agent\Exception\InvalidInputSubmissionException;
use Netresearch\NrLlm\Service\Agent\Exception\RunAlreadyResumingException;
use Netresearch\NrLlm\Service\Agent\Exception\RunConfigurationGoneException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingApprovalException;
use Netresearch\NrLlm\Service\Agent\Exception\RunNotAwaitingInputException;
use Netresearch\NrLlm\Service\Agent\Exception\RunStateUnavailableException;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\SchemaInputCoercer;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The "Agent Runs" approvals inbox (ADR-109): the human-facing surface for runs
 * suspended WAITING_FOR_APPROVAL (ADR-084) or WAITING_FOR_INPUT (ADR-105).
 *
 * All three actions are module-route controllerActions gated by the module's
 * `access => admin` (Configuration/Backend/Modules.php) — the sole authorization
 * gate. Unlike the AJAX endpoints on {@see ToolPlaygroundController}, a
 * module-route action cannot be reached without that access, so
 * RequiresBackendAdminTrait is not needed here (and its JSON 403 body would be
 * wrong for an HTML page). Any admin may act on any run; the recorded
 * decidedBy/submittedBy uid is audit-only.
 *
 * The page works fully with JavaScript OFF: native `<f:form>` POST, a
 * POST-redirect-GET flush with session flash messages, and a 422 in-place
 * re-render that preserves the operator's raw input. The CSRF defence is the
 * backend module route token the `<f:form action=...>` URL carries (validated by
 * the RouteDispatcher), NOT `__trustedProperties`.
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
     * Approve or deny the pending tool call of a run suspended WAITING_FOR_APPROVAL.
     */
    public function approveAction(string $runUuid = '', bool $approve = false, string $turnDigest = ''): ResponseInterface
    {
        if ($runUuid === '') {
            $this->flash('runs.flash.error', ContextualFeedbackSeverity::WARNING);

            return $this->redirect('list');
        }

        // Stale-review guard: reload the CURRENT state and refuse to approve a
        // turn the operator did not review (a stale tab or a second admin).
        $run = $this->persister->findRun($runUuid);
        if ($run === null) {
            $this->flash('runs.flash.error', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }

        $currentDigest = $this->viewFactory->turnDigestForRun($run);
        if ($currentDigest === null) {
            $this->flash('runs.unreadable', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }
        if ($currentDigest !== $turnDigest) {
            $this->flash('runs.error.staleReview', ContextualFeedbackSeverity::WARNING);

            return $this->redirect('list');
        }

        try {
            $result = $this->agentRuntime->approve(
                $this->currentActor(),
                $runUuid,
                new ApprovalDecision($approve, $this->currentBackendUserUid()),
            );
        } catch (RunNotAwaitingApprovalException) {
            return $this->flashRedirect('runs.flash.error', ContextualFeedbackSeverity::WARNING);
        } catch (RunConfigurationGoneException) {
            return $this->flashRedirect('runs.flash.configGone', ContextualFeedbackSeverity::ERROR);
        } catch (RunAlreadyResumingException) {
            return $this->flashRedirect('runs.flash.alreadyResuming', ContextualFeedbackSeverity::WARNING);
        } catch (CorruptSuspendedStateException|RunStateUnavailableException) {
            return $this->flashRedirect('runs.unreadable', ContextualFeedbackSeverity::ERROR);
        }

        $this->flashOutcome($result, $approve);

        return $this->redirect('list');
    }

    /**
     * Submit typed input for a run suspended WAITING_FOR_INPUT and continue it.
     *
     * @param array<string, mixed> $input the form's raw `input[...]` values (all strings)
     */
    public function submitInputAction(string $runUuid = '', array $input = []): ResponseInterface
    {
        if ($runUuid === '') {
            $this->flash('runs.flash.error', ContextualFeedbackSeverity::WARNING);

            return $this->redirect('list');
        }

        // Reload the CURRENT schema and coerce the all-strings POST against it,
        // so the no-JS path validates. Never coerce against a broken schema.
        $run = $this->persister->findRun($runUuid);
        $schema = $run !== null ? $this->viewFactory->inputSchemaForRun($run) : null;
        if ($schema === null) {
            $this->flash('runs.unreadable', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }

        $data = $this->coercer->coerce($input, $schema);

        try {
            $result = $this->agentRuntime->submitInput(
                $this->currentActor(),
                $runUuid,
                new InputSubmission($data, $this->currentBackendUserUid()),
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
        $waitingRuns  = $this->persister->findAwaitingRuns();
        $terminalRuns = $this->persister->findRecentTerminalRuns();
        $dataLoadError = $waitingRuns === null || $terminalRuns === null;

        $this->moduleTemplate->assignMultiple([
            'waiting'       => $this->viewFactory->buildWaiting($waitingRuns ?? []),
            'terminal'      => $this->viewFactory->buildTerminal($terminalRuns ?? []),
            'dataLoadError' => $dataLoadError,
            'errorRunUuid'  => $errorRunUuid,
            'rawInput'      => $rawInput,
            'errorSummary'  => $errorSummary,
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
