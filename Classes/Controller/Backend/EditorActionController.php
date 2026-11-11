<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\GuardrailPolicyException;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\Exception\RunEnqueueFailedException;
use Netresearch\NrLlm\Service\Tool\BatchScopeReader;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchPlan;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchPlanner;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogueInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\ElementBrowser\ElementBrowserParameters;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;
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
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final class EditorActionController extends ActionController
{
    use BackendUserUidTrait;
    use DefensiveLocalizationTrait;
    use ModuleChromeTrait;
    use RequiresBackendAdminTrait;

    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    /**
     * The field references the two pickers of the batch form give the element
     * browser. A pick comes back carrying its field reference, so each picker
     * takes only its own picks.
     */
    private const RECORDS_FIELD = 'nrllm-batch-records';

    private const SCOPE_FIELD = 'nrllm-batch-scope';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly EditorActionCatalogueInterface $catalogue,
        private readonly AgentRuntimeInterface $agentRuntime,
        private readonly EditorActionBatchPlanner $batchPlanner,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
    ) {}

    /**
     * The catalogue: every action this user may start, grouped by tool group.
     *
     * With a record (`$recordTable` / `$recordUid`, carried in by the context
     * menu) the list is narrowed to the actions that declare that table and
     * each one can be started. Without a record the page is a read-only
     * overview — an action needs a subject, and this module deliberately has no
     * record picker of its own.
     *
     * With a folder (`$folder`, a combined identifier carried in by the file
     * list's context menu, ADR-267) the list is narrowed to the actions on
     * `sys_file`, and each one leads to the batch page with that folder as its
     * scope (ADR-236). Nothing is read here: the batch page lists the folder,
     * and every file in it is checked as the acting user there.
     */
    public function catalogueAction(string $recordTable = '', int $recordUid = 0, string $folder = ''): ResponseInterface
    {
        if (($deny = $this->denyWithoutGrantHtml(BackendUserGrant::TASKS_USE)) instanceof ResponseInterface) {
            return $deny;
        }

        $folder     = trim($folder);
        $hasFolder  = $recordTable === 'sys_file' && $folder !== '' && $recordUid < 1;
        $hasSubject = !$hasFolder && $recordTable !== '' && $recordUid > 0;

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        $this->applyModuleChrome($moduleTemplate, $this->request);
        $moduleTemplate->assignMultiple([
            'groups' => $this->catalogue->groupsFor($this->viewer(), $hasSubject || $hasFolder ? $recordTable : ''),
            // A uid of 0 is falsy in Fluid and would take the else branch of a
            // condition on the uid itself, so the template asks this flag.
            'hasSubject'  => $hasSubject,
            'hasFolder'   => $hasFolder,
            'folder'      => $hasFolder ? $folder : '',
            'recordTable' => $hasSubject || $hasFolder ? $recordTable : '',
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

        if (in_array($result->outcome, [AgentRunOutcome::AWAITING_APPROVAL, AgentRunOutcome::AWAITING_INPUT, AgentRunOutcome::AWAITING_DEPENDENCY], true)) {
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
     * The same action over several records of one table, planned but not
     * started (ADR-162).
     *
     * The page an editor confirms: which of the named records the catalogue
     * offers this action on, which it does not and why, what the batch is
     * expected to cost, and how far off that estimate can be. Nothing runs here
     * — the plan is rebuilt inside {@see startBatchAction()}, because a plan
     * carried across a request would be a permission carried across a request.
     */
    public function batchAction(string $toolName = '', string $recordTable = '', string $recordUids = '', string $instruction = '', string $scope = '', int $depth = BatchScopeReader::MAX_DEPTH, string $onlyEmpty = ''): ResponseInterface
    {
        if (($deny = $this->denyWithoutGrantHtml(BackendUserGrant::TASKS_USE)) instanceof ResponseInterface) {
            return $deny;
        }

        $plan = trim($scope) !== ''
            ? $this->backgroundPlan($toolName, $recordTable, $scope, $depth, $onlyEmpty, $instruction)
            : $this->plan($toolName, $recordTable, $recordUids, $instruction);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        $this->applyModuleChrome($moduleTemplate, $this->request);
        $moduleTemplate->assignMultiple([
            'plan'        => $plan,
            'toolName'    => $toolName,
            'recordTable' => $recordTable,
            'recordUids'  => $recordUids,
            'instruction' => $instruction,
            'maxRecords'  => EditorActionBatchPlanner::MAX_RECORDS,
            'maxInputs'   => EditorActionBatchPlanner::MAX_INPUTS,
            // A folder or a page tree, for the queue (ADR-236).
            'scope'           => $scope,
            'depth'           => $depth,
            'onlyEmpty'       => $onlyEmpty,
            'scopeUnreadable' => trim($scope) !== '' && !$plan instanceof EditorActionBatchPlan,
            'scopeLabelKey'   => $recordTable === 'sys_file' ? 'editorActions.batch.scope.folder' : 'editorActions.batch.scope.pageTree',
            'scopeable'       => in_array($recordTable, ['sys_file', 'pages'], true),
            'maxBackground'   => EditorActionBatchPlanner::MAX_BACKGROUND_RECORDS,
            // The pickers that replace the typed numbers. They write the same
            // `recordUids` and `scope` the actions read, so every check above
            // and in startBatchAction() sees what it always saw.
            'recordBrowserUrl' => $this->recordBrowserUrl($recordTable),
            'scopeBrowserUrl'  => $recordTable === 'sys_file'
                ? $this->elementBrowserUrl('folder', '', self::SCOPE_FIELD)
                : $this->elementBrowserUrl('db', 'pages', self::SCOPE_FIELD),
            'recordsField' => self::RECORDS_FIELD,
            'scopeField'   => self::SCOPE_FIELD,
        ]);
        // As a module, not inline: the backend Content Security Policy blocks
        // an inline script without a nonce.
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/BatchRecordPicker.js');

        return $moduleTemplate->renderResponse('Backend/EditorAction/Batch');
    }

    /**
     * Start the batch: N ordinary agent runs, one per record.
     *
     * No bulk runtime, no shared approval, no queue (ADR-162). Each record goes
     * through the same {@see AgentRuntimeInterface::run()} the single-record
     * path uses, so each suspends for its own approval and becomes its own inbox
     * card with its own preview.
     *
     * The loop stops at the first run the budget refuses — see
     * {@see deniedByBudget()} for what that looks like when it arrives — and
     * names the records the stop kept from starting. Every other terminal
     * outcome is per record and does not stop the rest: a model declining one
     * page is not a reason to abandon the other nineteen. Those are counted by
     * KIND, so a batch in which everything failed does not report as one in
     * which nothing needed changing.
     */
    public function startBatchAction(string $toolName = '', string $recordTable = '', string $recordUids = '', string $instruction = '', string $scope = '', int $depth = BatchScopeReader::MAX_DEPTH, string $onlyEmpty = ''): ResponseInterface
    {
        if (($deny = $this->denyWithoutGrantHtml(BackendUserGrant::TASKS_USE)) instanceof ResponseInterface) {
            return $deny;
        }

        if (trim($scope) !== '') {
            return $this->queueBatch($toolName, $recordTable, $scope, $depth, $onlyEmpty, $instruction);
        }

        // Re-planned, never restored: the catalogue is asked again for every
        // record, so a POST naming records the GET never offered starts nothing.
        $plan = $this->plan($toolName, $recordTable, $recordUids, $instruction);

        $started       = 0;
        $notAttempted  = [];
        $budgetStopped = false;
        // Keyed by label so the several outcomes that share a sentence share a
        // counter, and carrying the severity so a failed batch cannot be
        // reported at the tone of a harmless one.
        /** @var array<string, array{int, ContextualFeedbackSeverity}> $terminal */
        $terminal = [];
        // Each distinct guardrail reason once: a count alone does not say why
        // nothing came back (ADR-238).
        $guardrailReasons = [];

        foreach ($plan->entries as $entry) {
            $request = $entry->request;
            if (!$request instanceof AgentRunRequest) {
                continue;
            }

            if ($budgetStopped) {
                $notAttempted[] = $entry->recordUid;
                continue;
            }

            $result = $this->agentRuntime->run($request);

            if (in_array($result->outcome, [AgentRunOutcome::AWAITING_APPROVAL, AgentRunOutcome::AWAITING_INPUT, AgentRunOutcome::AWAITING_DEPENDENCY], true)) {
                ++$started;
                continue;
            }

            if ($this->deniedByBudget($result)) {
                // This record WAS handed to run(): it is the one the budget
                // refused, not one that was never started. Only the records
                // after it go on that list.
                $budgetStopped = true;
                continue;
            }

            [$key, $severity]   = $this->terminalLabel($result->outcome, 'editorActions.batch.flash.');
            $terminal[$key]     = [($terminal[$key][0] ?? 0) + 1, $severity];
            $guardrailReasons[] = $this->guardrailReason($result);
        }

        $this->reportBatch($plan, $started, $terminal, $budgetStopped, $notAttempted);

        foreach (array_unique(array_filter($guardrailReasons)) as $reason) {
            $this->addFlashMessage($reason, '', ContextualFeedbackSeverity::WARNING);
        }

        if ($started > 0) {
            // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
            return $this->redirect('list', 'Backend\\AgentRun');
        }

        // Nothing is waiting anywhere, so the inbox would be the wrong place to
        // land. Back to the plan, where the reasons are.
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        return $this->redirect('batch', null, null, [
            'toolName'    => $toolName,
            'recordTable' => $recordTable,
            'recordUids'  => $recordUids,
            'instruction' => $instruction,
        ]);
    }

    /**
     * The browser the records picker opens: the file browser for files, the
     * record browser for that table otherwise.
     */
    private function recordBrowserUrl(string $recordTable): string
    {
        if ($recordTable === '') {
            return '';
        }

        if ($recordTable === 'sys_file') {
            return $this->elementBrowserUrl('file', '', self::RECORDS_FIELD);
        }

        return $this->elementBrowserUrl('db', $recordTable, self::RECORDS_FIELD);
    }

    /**
     * TYPO3's element browser in the given mode: `db` for records of the
     * tables in `$allowedTypes`, `file` for files, `folder` for folders.
     *
     * TYPO3 14 takes separate parameters and, with `useEvents`, reports a pick
     * as an event on the browser's modal. TYPO3 13.4 has no
     * ElementBrowserParameters: it reads the pipe-separated `bparams`
     * (field reference, two RTE parts, allowed types, IRRE object) and posts a
     * pick as a message to the module frame. All five parts stay present,
     * because 13.4's file browser reads the fourth without a fallback.
     */
    private function elementBrowserUrl(string $mode, string $allowedTypes, string $fieldReference): string
    {
        $parameters = class_exists(ElementBrowserParameters::class)
            ? ['mode' => $mode, 'allowedTypes' => $allowedTypes, 'fieldReference' => $fieldReference, 'useEvents' => 1]
            : ['mode' => $mode, 'bparams' => $fieldReference . '|||' . $allowedTypes . '|'];

        return (string) $this->backendUriBuilder->buildUriFromRoute('wizard_element_browser', $parameters);
    }

    /**
     * Queue the batch over a folder or a page tree (ADR-236). Re-planned on the
     * POST like the other batch, then N ordinary runs, each of which suspends
     * for its own approval in a worker.
     */
    private function queueBatch(string $toolName, string $recordTable, string $scope, int $depth, string $onlyEmpty, string $instruction): ResponseInterface
    {
        $plan = $this->backgroundPlan($toolName, $recordTable, $scope, $depth, $onlyEmpty, $instruction);
        if (!$plan instanceof EditorActionBatchPlan) {
            $this->flash('editorActions.batch.scope.unreadable', ContextualFeedbackSeverity::ERROR);

            // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
            return $this->redirect('batch', null, null, ['toolName' => $toolName, 'recordTable' => $recordTable]);
        }

        $queued = 0;
        $failed = 0;
        foreach ($plan->entries as $entry) {
            if (!$entry->request instanceof AgentRunRequest) {
                continue;
            }

            try {
                $this->agentRuntime->defer($entry->request);
                ++$queued;
            } catch (RunEnqueueFailedException) {
                ++$failed;
            }
        }

        if ($queued > 0) {
            $this->flashCount('editorActions.batch.flash.queued', $queued, ContextualFeedbackSeverity::OK);
        }

        if ($failed > 0) {
            $this->flashCount('editorActions.batch.flash.notQueued', $failed, ContextualFeedbackSeverity::ERROR);
        }

        if ($queued === 0 && $failed === 0) {
            $this->flash('editorActions.batch.flash.nothingToQueue', ContextualFeedbackSeverity::INFO);
        }

        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        return $this->redirect('list', 'Backend\\AgentRun');
    }

    private function backgroundPlan(string $toolName, string $recordTable, string $scope, int $depth, string $onlyEmpty, string $instruction): ?EditorActionBatchPlan
    {
        $viewer = $this->viewer();

        return $viewer instanceof BackendUserAuthentication
            ? $this->batchPlanner->planBackground($toolName, $recordTable, trim($scope), $depth, $onlyEmpty, $instruction, $this->currentActor(), $viewer)
            : null;
    }

    private function plan(string $toolName, string $recordTable, string $recordUids, string $instruction): EditorActionBatchPlan
    {
        return $this->batchPlanner->plan(
            $toolName,
            $recordTable,
            $recordUids,
            $instruction,
            $this->currentActor(),
            $this->viewer(),
        );
    }

    /**
     * Say what the batch did, including everything it did not do.
     *
     * One message per distinct fact rather than one summary sentence: a record
     * that was skipped and a record that was never attempted are different
     * things to an editor, and folding them into a count hides which is which.
     *
     * @param array<string, array{int, ContextualFeedbackSeverity}> $terminal
     * @param list<int>                                             $notAttempted
     */
    private function reportBatch(EditorActionBatchPlan $plan, int $started, array $terminal, bool $budgetStopped, array $notAttempted): void
    {
        if ($started > 0) {
            $this->flashCount('editorActions.batch.flash.started', $started, ContextualFeedbackSeverity::OK);
        }

        // Grouped by reason: the uids are named, so "skipped" is never a number
        // an editor has to reverse-engineer.
        $byReason = [];
        foreach ($plan->getSkipped() as $entry) {
            $byReason[(string) $entry->skipReasonKey][] = $entry->recordUid;
        }

        foreach ($byReason as $reasonKey => $uids) {
            $this->addFlashMessage(
                $this->localize($reasonKey, $reasonKey) . ' ' . implode(', ', $uids),
                '',
                ContextualFeedbackSeverity::WARNING,
            );
        }

        if ($plan->inputTruncated) {
            $this->flashCount('editorActions.batch.flash.truncated', EditorActionBatchPlanner::MAX_INPUTS, ContextualFeedbackSeverity::WARNING);
        }

        if ($plan->discardedInputs > 0) {
            $this->flashCount('editorActions.batch.flash.discarded', $plan->discardedInputs, ContextualFeedbackSeverity::WARNING);
        }

        foreach ($terminal as $key => [$count, $severity]) {
            $this->flashCount($key, $count, $severity);
        }

        if ($budgetStopped) {
            $key     = 'editorActions.batch.flash.budgetStopped';
            $message = $this->localize(self::LL . $key, $key);

            // Two facts, one message: the batch stopped, and — only when the
            // stop actually kept records from starting — which ones. A denial on
            // the last record names nothing, and must still say the budget ran
            // out.
            if ($notAttempted !== []) {
                $listKey = 'editorActions.batch.flash.notAttempted';
                $message .= ' ' . $this->localize(self::LL . $listKey, $listKey) . ' ' . implode(', ', $notAttempted);
            }

            $this->addFlashMessage($message, '', ContextualFeedbackSeverity::ERROR);
        }

        if ($started === 0 && $plan->entries === []) {
            $this->flash('editorActions.batch.flash.nothingNamed', ContextualFeedbackSeverity::WARNING);
        }
    }

    /**
     * Whether the budget gate is what ended this run.
     *
     * A budget denial reaches this surface in two different shapes, and only
     * the first one occurs on the path an editor action takes:
     *
     * - **With tools offered** — the ordinary case — {@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}
     *   CATCHES the denial itself and returns a truncated result carrying
     *   {@see AgentRunTerminationReason::BUDGET_EXHAUSTED}. The executor settles
     *   that as a normal COMPLETED run with no `error` at all (ADR-092), so the
     *   termination reason is the only signal there is.
     * - **With no tool on the wire** — an allow-list whose tool is not
     *   registered — the send sits outside that catch and the exception
     *   propagates, arriving as the result's `error`. A provider or middleware
     *   layer may have wrapped it, so the whole chain is walked rather than the
     *   outermost type compared.
     */
    private function deniedByBudget(AgentRunResult $result): bool
    {
        if ($result->loopResult?->terminationReason === AgentRunTerminationReason::BUDGET_EXHAUSTED) {
            return true;
        }

        for ($error = $result->error; $error instanceof Throwable; $error = $error->getPrevious()) {
            if ($error instanceof BudgetExceededException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which bucket a terminal outcome belongs in, and how loudly it reads.
     *
     * ONE partition for both surfaces, differing only in the label namespace
     * the caller passes: the single-record path says it of one run, the batch
     * says it of a count. They said the same thing in two match statements
     * before, which is a pair that drifts the moment an {@see AgentRunOutcome}
     * case is added or moved.
     *
     * Neither surface may report a failed, guardrail-stopped or unresumable run
     * as the benign "finished without proposing a change". SUSPEND_FAILED is the
     * sharpest of those — an approval was required and could not be stored, so
     * no resume is possible — and lands in the default arm with FAILED.
     * {@see AgentRunOutcome} is non-exhaustive (ADR-101), hence that arm.
     *
     * @return array{string, ContextualFeedbackSeverity}
     */
    private function terminalLabel(AgentRunOutcome $outcome, string $prefix): array
    {
        return match ($outcome) {
            AgentRunOutcome::COMPLETED                                                       => [$prefix . 'completedWithoutWrite', ContextualFeedbackSeverity::INFO],
            AgentRunOutcome::GUARDRAIL_BLOCKED, AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED => [$prefix . 'blocked', ContextualFeedbackSeverity::WARNING],
            AgentRunOutcome::CANCELLED                                                       => [$prefix . 'cancelled', ContextualFeedbackSeverity::INFO],
            default                                                                          => [$prefix . 'failed', ContextualFeedbackSeverity::ERROR],
        };
    }

    private function flashCount(string $key, int $count, ContextualFeedbackSeverity $severity): void
    {
        $this->addFlashMessage(
            sprintf($this->localize(self::LL . $key, $key), $count),
            '',
            $severity,
        );
    }

    /**
     * A run that ended without asking anyone.
     *
     * For a writing action that is the unusual case — the model declined to
     * call the tool, a guardrail stopped it, or it failed — so no sentence
     * {@see terminalLabel()} names claims a write happened.
     */
    private function flashTerminalOutcome(AgentRunResult $result): void
    {
        [$key, $severity] = $this->terminalLabel($result->outcome, 'editorActions.flash.');

        $this->addFlashMessage(trim($this->localize(self::LL . $key, $key) . ' ' . $this->guardrailReason($result)), '', $severity);
    }

    /**
     * What a guardrail said about this run: it tells the editor why nothing
     * came back (ADR-238). Empty for any other outcome.
     */
    private function guardrailReason(AgentRunResult $result): string
    {
        return $result->error instanceof GuardrailPolicyException ? $result->error->getMessage() : '';
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
