<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Countable;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

/**
 * Backend controller for the Task catalog (list view).
 *
 * Slice 13e of the `TaskController` split (ADR-027). Owns the
 * single `listAction()` that renders the grouped-by-category Task
 * dashboard.
 */
#[AsController]
final class TaskListController extends ActionController
{
    private const TABLE_NAME = 'tx_nrllm_task';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly TaskRepository $taskRepository,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    /**
     * List all tasks grouped by category.
     */
    public function listAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        /** @var QueryResultInterface<int, Task>&Countable $tasks */
        $tasks = $this->taskRepository->findAll();
        $categories = Task::getCategories();

        $groupedTasks = [];
        foreach ($categories as $categoryKey => $categoryLabel) {
            $groupedTasks[$categoryKey] = [
                'label' => $categoryLabel,
                'tasks' => [],
            ];
        }

        /** @var array<int, string> $editUrls */
        $editUrls = [];
        foreach ($tasks as $task) {
            if (!$task instanceof Task) { // @phpstan-ignore instanceof.alwaysTrue
                continue;
            }
            $uid = $task->getUid();
            if ($uid === null) {
                continue;
            }
            $editUrls[$uid] = $this->buildEditUrl($uid);
            $category = $task->getCategory();
            if (!isset($groupedTasks[$category])) {
                $groupedTasks[$category] = [
                    'label' => $category,
                    'tasks' => [],
                ];
            }
            $groupedTasks[$category]['tasks'][] = $task;
        }

        $groupedTasks = array_filter($groupedTasks, fn($group) => !empty($group['tasks']));

        $moduleTemplate->assignMultiple([
            'groupedTasks' => $groupedTasks,
            'totalCount'   => $tasks->count(),
            'editUrls'     => $editUrls,
            'newUrl'       => $this->buildNewUrl(),
            'wizardUrl'    => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_wizard'),
        ]);

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $createButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle('New Task')
            ->setShowLabelText(true)
            ->setHref($this->buildNewUrl());
        $buttonBar->addButton($createButton);

        $aiButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-bolt', IconSize::SMALL))
            ->setTitle('Create with AI')
            ->setShowLabelText(true)
            ->setHref($this->uriBuilder->reset()->uriFor('wizardForm', controller: 'TaskWizard'));
        $buttonBar->addButton($aiButton, ButtonBar::BUTTON_POSITION_LEFT, 2);

        return $moduleTemplate->renderResponse('Backend/Task/List');
    }

    private function buildEditUrl(int $uid): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit'      => [self::TABLE_NAME => [$uid => 'edit']],
            'returnUrl' => $this->buildReturnUrl(),
        ]);
    }

    private function buildNewUrl(): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit'      => [self::TABLE_NAME => [0 => 'new']],
            'returnUrl' => $this->buildReturnUrl(),
        ]);
    }

    private function buildReturnUrl(): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_tasks');
    }
}
