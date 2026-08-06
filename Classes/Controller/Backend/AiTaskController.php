<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\Enum\TaskInputType;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Service\Task\TaskInputResolverInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The editor-facing task surface (ADR-131): list executable tasks and run
 * one — nothing else. Registered under the `nrllm_aitasks` module
 * (`access => 'user'`), so reaching it takes BOTH the module permission
 * (be_groups module list) AND the `tasks_use` grant checked per action here
 * — the module switch alone never grants execution.
 *
 * Deliberately narrower than the admin task module: no FormEngine edit
 * links (an editor cannot edit tx_nrllm_* records), no analytics, no
 * wizard, and tasks with the `table` input type are filtered out — the
 * record picker has no read boundary yet (ADR-130) and stays admin-only.
 * The execute/refresh AJAX endpoints this page drives are grant-gated
 * themselves (ADR-130) and budget-bounded per user.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final class AiTaskController extends ActionController
{
    use BackendUserUidTrait;
    use DefensiveLocalizationTrait;
    use RequiresBackendAdminTrait;

    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly TaskRepository $taskRepository,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly TaskInputResolverInterface $taskInputResolver,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
    ) {}

    /**
     * Executable tasks, grouped for selection. A user holding only the
     * approval grant is forwarded to the runs view — for them this module IS
     * the approvals inbox.
     */
    public function listAction(): ResponseInterface
    {
        if (($deny = $this->denyWithoutGrantHtml(BackendUserGrant::TASKS_USE)) instanceof ResponseInterface) {
            $actor = $this->currentActor();
            if ($actor->hasGrant(BackendUserGrant::AGENT_APPROVE)) {
                return new RedirectResponse((string)$this->backendUriBuilder->buildUriFromRoute('nrllm_aitasks', [
                    'controller' => 'Backend\\AgentRun',
                    'action'     => 'list',
                ]));
            }

            return $deny;
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());

        $moduleTemplate->assignMultiple([
            'tasks'        => $this->executableTasks(),
            'showApprovals' => $this->currentActor()->hasGrant(BackendUserGrant::AGENT_APPROVE),
        ]);

        return $moduleTemplate->renderResponse('Backend/AiTask/List');
    }

    /**
     * The run form for one task — the editor sibling of the admin module's
     * execute form, minus every management affordance.
     */
    public function executeFormAction(int $uid): ResponseInterface
    {
        if (($deny = $this->denyWithoutGrantHtml(BackendUserGrant::TASKS_USE)) instanceof ResponseInterface) {
            return $deny;
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());

        $task = $this->taskRepository->findByUid($uid);
        if (!$task instanceof Task || !$task->isActive() || $task->getInputTypeEnum() === TaskInputType::TABLE) {
            // TABLE tasks stay admin-only until the record picker has a read
            // boundary (ADR-130/131) — same redirect as an unknown task, so
            // a guessed uid leaks nothing about what exists.
            $this->addFlashMessage(
                $this->localize(self::LL . 'aitasks.notAvailable', 'This task is not available.'),
                $this->localize(self::LL . 'task.error', 'Error'),
                ContextualFeedbackSeverity::ERROR,
            );

            return new RedirectResponse((string)$this->backendUriBuilder->buildUriFromRoute('nrllm_aitasks'));
        }

        $this->pageRenderer->addInlineSettingArray('ajaxUrls', [
            'nrllm_task_refresh_input' => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_refresh_input'),
            'nrllm_task_execute'       => (string)$this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_execute'),
        ]);
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/TaskExecute.js');

        $configuration  = $task->getConfiguration();
        $isUsingDefault = false;
        if (!$configuration instanceof LlmConfiguration) {
            $configuration  = $this->configurationRepository->findDefault();
            $isUsingDefault = true;
        }

        $moduleTemplate->assignMultiple([
            'task'                 => $task,
            'inputData'            => $this->taskInputResolver->resolve($task),
            'requiresManualInput'  => $task->requiresManualInput(),
            'effectiveConfig'      => $configuration,
            'isUsingDefaultConfig' => $isUsingDefault,
        ]);

        return $moduleTemplate->renderResponse('Backend/AiTask/Execute');
    }

    /**
     * Active tasks an editor may run: everything except the `table` input
     * type (no record-picker read boundary yet), grouped by category.
     *
     * @return array<string, list<Task>>
     */
    private function executableTasks(): array
    {
        $grouped = [];
        foreach ($this->taskRepository->findActive() as $task) {
            if (!$task instanceof Task) {
                continue;
            }

            if ($task->getInputTypeEnum() === TaskInputType::TABLE) {
                continue;
            }

            $grouped[$task->getCategory()][] = $task;
        }

        ksort($grouped);

        return $grouped;
    }
}
