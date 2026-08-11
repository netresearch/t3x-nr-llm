<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogueInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The Editor Action Center (ADR-158): the surface where an editor sees which
 * editorial actions apply to what they are looking at, starts one, and lands in
 * the approvals inbox that already exists.
 *
 * Registered under `nrllm_aitasks` (`access => 'user'`, parent `web`) beside
 * {@see AiTaskController} and the shared {@see AgentRunController} — the one
 * module tree a non-admin can reach (ADR-131). Both actions additionally
 * require the `tasks_use` grant: the module switch alone never grants
 * execution.
 *
 * This controller decides nothing about authorisation. Which actions exist is
 * {@see EditorActionCatalogueInterface}'s answer, and that answer comes from
 * the composite tool gate (ADR-094); {@see startAction()} obtains its run
 * request from the same seam, so a POST naming an action the catalogue never
 * offered produces no run. Everything after that is the agent runtime's: the
 * write suspends for approval (ADR-134), the preview is captured at suspension
 * (ADR-136), and the operator decides it on the existing inbox card.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final class EditorActionController extends ActionController
{
    use BackendUserUidTrait;
    use DefensiveLocalizationTrait;
    use RequiresBackendAdminTrait;

    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly EditorActionCatalogueInterface $catalogue,
        private readonly AgentRuntimeInterface $agentRuntime,
    ) {}

    /**
     * The catalogue: every action this user may start, grouped by tool group.
     *
     * With a record (`$recordTable` / `$recordUid`, carried in by the context
     * menu) the list is narrowed to the actions that declare that table and
     * each one can be started. Without a record the page is a read-only
     * overview — an action needs a subject, and this module deliberately has no
     * record picker of its own.
     */
    public function catalogueAction(string $recordTable = '', int $recordUid = 0): ResponseInterface
    {
        if (($deny = $this->denyWithoutGrantHtml(BackendUserGrant::TASKS_USE)) instanceof ResponseInterface) {
            return $deny;
        }

        $hasSubject = $recordTable !== '' && $recordUid > 0;

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        $moduleTemplate->makeDocHeaderModuleMenu();
        $moduleTemplate->assignMultiple([
            'groups'      => $this->catalogue->groupsFor($this->viewer(), $hasSubject ? $recordTable : ''),
            // A uid of 0 is falsy in Fluid and would take the else branch of a
            // condition on the uid itself, so the template asks this flag.
            'hasSubject'  => $hasSubject,
            'recordTable' => $hasSubject ? $recordTable : '',
            'recordUid'   => $hasSubject ? $recordUid : 0,
        ]);

        return $moduleTemplate->renderResponse('Backend/EditorAction/Catalogue');
    }

    /**
     * Start one action on one record as an ordinary agent run.
     *
     * No bulk, no second executor: one {@see AgentRunRequest}, handed to
     * {@see AgentRuntimeInterface::run()}, which settles it. A declared write
     * suspends AWAITING_APPROVAL before it touches anything, so the expected
     * outcome of this action is a redirect to the inbox — not a finished write.
     */
    public function startAction(string $toolName = '', string $recordTable = '', int $recordUid = 0, string $instruction = ''): ResponseInterface
    {
        if (($deny = $this->denyWithoutGrantHtml(BackendUserGrant::TASKS_USE)) instanceof ResponseInterface) {
            return $deny;
        }

        $runRequest = $this->catalogue->runRequestFor(
            $toolName,
            $recordTable,
            $recordUid,
            $instruction,
            $this->currentActor(),
            $this->viewer(),
        );

        if (!$runRequest instanceof AgentRunRequest) {
            // One message for "no such action", "not offered to you" and "no
            // record", so a guessed tool name learns nothing about what exists.
            $this->flash('editorActions.flash.notAvailable', ContextualFeedbackSeverity::ERROR);

            // Extbase's redirect() takes an ACTION NAME, not a URL. The
            // Symfony rule reads the first argument as a destination; here it
            // is the literal 'catalogue', and recordTable/recordUid travel as
            // query arguments the target action re-validates.
            // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
            return $this->redirect('catalogue', null, null, [
                'recordTable' => $recordTable,
                'recordUid'   => $recordUid,
            ]);
        }

        $result = $this->agentRuntime->run($runRequest);

        if ($result->outcome === AgentRunOutcome::AWAITING_APPROVAL || $result->outcome === AgentRunOutcome::AWAITING_INPUT) {
            $this->flash('editorActions.flash.awaiting', ContextualFeedbackSeverity::OK);

            // The existing inbox, in this same module: the card there carries
            // the preview the pause captured (ADR-136).
            // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
            return $this->redirect('list', 'Backend\\AgentRun');
        }

        $this->flashTerminalOutcome($result);

        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        return $this->redirect('catalogue', null, null, [
            'recordTable' => $recordTable,
            'recordUid'   => $recordUid,
        ]);
    }

    /**
     * A run that ended without asking anyone.
     *
     * For a writing action that is the unusual case — the model declined to
     * call the tool, a guardrail stopped it, or it failed — so none of these
     * arms claims a write happened. {@see AgentRunOutcome} is non-exhaustive
     * (ADR-101), hence the default arm.
     */
    private function flashTerminalOutcome(AgentRunResult $result): void
    {
        [$key, $severity] = match ($result->outcome) {
            AgentRunOutcome::COMPLETED => ['editorActions.flash.completedWithoutWrite', ContextualFeedbackSeverity::INFO],
            AgentRunOutcome::GUARDRAIL_BLOCKED, AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED => ['editorActions.flash.blocked', ContextualFeedbackSeverity::WARNING],
            AgentRunOutcome::CANCELLED => ['editorActions.flash.cancelled', ContextualFeedbackSeverity::INFO],
            default => ['editorActions.flash.failed', ContextualFeedbackSeverity::ERROR],
        };

        $this->flash($key, $severity);
    }

    private function flash(string $key, ContextualFeedbackSeverity $severity): void
    {
        $this->addFlashMessage($this->localize(self::LL . $key, $key), '', $severity);
    }

    /**
     * The backend user LOOKING at this page — the one the tool gate answers
     * for.
     *
     * The ambient user is the right source here, and reading it at the HTTP
     * boundary is what ADR-083 sanctions: it IS the caller. What the run
     * carries downstream is the explicit {@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext}
     * from {@see BackendUserUidTrait::currentActor()}, never a second read of
     * this global.
     */
    private function viewer(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
