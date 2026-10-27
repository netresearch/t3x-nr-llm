<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\DTO\RefreshInputRequest;
use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\Enum\TaskInputType;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Domain\ValueObject\TaskBudgetVerdict;
use Netresearch\NrLlm\Domain\ValueObject\TaskCostEstimate;
use Netresearch\NrLlm\Service\Budget\UserBudgetStatusService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Task\TaskCostEstimator;
use Netresearch\NrLlm\Service\Task\TaskInputResolverInterface;
use Netresearch\NrLlm\Service\Task\TaskRunBudgetPreflight;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
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
 * Both views show the editor's OWN usage against their budget (NEXT-170),
 * and the run form adds a cost estimate and the budget's verdict on the next
 * run. Whose numbers are shown comes from the logged-in backend user only,
 * never from a request value.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final class AiTaskController extends ActionController
{
    use BackendUserUidTrait;
    use DefensiveLocalizationTrait;
    use ModuleChromeTrait;
    use RequiresBackendAdminTrait;

    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    private const CSS_FILE = 'EXT:nr_llm/Resources/Public/Css/Backend/AiTasks.css';

    /**
     * English fallbacks for the limit names, keyed like the xlf labels
     * `aitasks.budget.limit.<key>`.
     *
     * @var array<string, string>
     */
    private const LIMIT_FALLBACKS = [
        BudgetCheckResult::LIMIT_DAILY_REQUESTS               => 'your daily number of requests',
        BudgetCheckResult::LIMIT_DAILY_TOKENS                 => 'your daily number of tokens',
        BudgetCheckResult::LIMIT_DAILY_COST                   => 'your daily cost',
        BudgetCheckResult::LIMIT_MONTHLY_REQUESTS             => 'your monthly number of requests',
        BudgetCheckResult::LIMIT_MONTHLY_TOKENS               => 'your monthly number of tokens',
        BudgetCheckResult::LIMIT_MONTHLY_COST                 => 'your monthly cost',
        BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_REQUESTS => "the daily number of requests of this task's configuration, shared by all users",
        BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_TOKENS   => "the daily number of tokens of this task's configuration, shared by all users",
        BudgetCheckResult::LIMIT_CONFIGURATION_DAILY_COST     => "the daily cost of this task's configuration, shared by all users",
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly TaskRepository $taskRepository,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly TaskInputResolverInterface $taskInputResolver,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly UserBudgetStatusService $budgetStatus,
        private readonly TaskCostEstimator $costEstimator,
        private readonly TaskRunBudgetPreflight $budgetPreflight,
        private readonly LlmServiceManagerInterface $llmServiceManager,
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
                return new RedirectResponse((string) $this->backendUriBuilder->buildUriFromRoute('nrllm_aitasks', [
                    'controller' => 'Backend\\AgentRun',
                    'action'     => 'list',
                ]));
            }

            return $deny;
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->applyModuleChrome($moduleTemplate, $this->request);
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());

        $this->pageRenderer->addCssFile(self::CSS_FILE);

        $moduleTemplate->assignMultiple([
            'tasks'         => $this->executableTasks(),
            'showApprovals' => $this->currentActor()->hasGrant(BackendUserGrant::AGENT_APPROVE),
            'budget'        => $this->budgetStatus->forUser($this->currentBackendUserUid()),
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
        $this->applyModuleChrome($moduleTemplate, $this->request);
        $moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());

        $task = $this->taskRepository->findByUid($uid);
        if (!$this->isRunnable($task)) {
            // TABLE tasks stay admin-only until the record picker has a read
            // boundary (ADR-130/131) — same redirect as an unknown task, so
            // a guessed uid leaks nothing about what exists.
            $this->addFlashMessage(
                $this->localize(self::LL . 'aitasks.notAvailable', 'This task is not available.'),
                $this->localize(self::LL . 'task.error', 'Error'),
                ContextualFeedbackSeverity::ERROR,
            );

            return new RedirectResponse((string) $this->backendUriBuilder->buildUriFromRoute('nrllm_aitasks'));
        }

        $this->pageRenderer->addInlineSettingArray('ajaxUrls', [
            'nrllm_task_refresh_input' => (string) $this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_refresh_input'),
            'nrllm_task_execute'       => (string) $this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_task_execute'),
            'nrllm_aitask_budget'      => (string) $this->backendUriBuilder->buildUriFromRoute('ajax_nrllm_aitask_budget'),
        ]);
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/TaskExecute.js');
        $this->pageRenderer->addCssFile(self::CSS_FILE);

        $configuration  = $task->getConfiguration();
        $isUsingDefault = false;
        if (!$configuration instanceof LlmConfiguration) {
            $configuration  = $this->configurationRepository->findDefault();
            $isUsingDefault = true;
        }

        $inputData = $this->taskInputResolver->resolve($task);
        $estimate  = $this->estimate($task, $inputData);

        $moduleTemplate->assignMultiple([
            'task'                 => $task,
            'inputData'            => $inputData,
            'requiresManualInput'  => $task->requiresManualInput(),
            'effectiveConfig'      => $configuration,
            'isUsingDefaultConfig' => $isUsingDefault,
            'budget'               => $this->budgetStatus->forUser($this->currentBackendUserUid()),
            'estimate'             => $estimate,
            'verdict'              => $this->verdictPayload($this->verdict($task, $estimate)),
        ]);

        return $moduleTemplate->renderResponse('Backend/AiTask/Execute');
    }

    /**
     * The current user's budget status and the verdict on the next run of one
     * task, for the run form to refresh after a run (NEXT-170).
     *
     * AJAX routes bypass the module access check, so the TASKS_USE grant is the
     * gate here, as on the execute endpoint. The only uid read is the logged-in
     * user's; the request names the task, never a user. A task the run form
     * would not open answers 404, like an unknown one.
     */
    public function budgetStatusAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyWithoutGrant(BackendUserGrant::TASKS_USE)) instanceof ResponseInterface) {
            return $deny;
        }

        $task = $this->taskRepository->findByUid(RefreshInputRequest::fromRequest($request)->uid);
        if (!$this->isRunnable($task)) {
            return new JsonResponse((new ErrorResponse($this->localize(self::LL . 'aitasks.notAvailable', 'This task is not available.')))->jsonSerialize(), 404);
        }

        $estimate = $this->estimate($task, $task->requiresManualInput() ? '' : $this->taskInputResolver->resolve($task));

        return new JsonResponse([
            'success' => true,
            'status'  => $this->budgetStatus->forUser($this->currentBackendUserUid())->toArray(),
            'verdict' => $this->verdictPayload($this->verdict($task, $estimate)),
        ]);
    }

    /**
     * @phpstan-assert-if-true Task $task
     */
    private function isRunnable(?Task $task): bool
    {
        // TABLE tasks stay admin-only until the record picker has a read
        // boundary (ADR-130/131).
        return $task instanceof Task && $task->isActive() && $task->getInputTypeEnum() !== TaskInputType::TABLE;
    }

    /**
     * One run of the task with the configuration the execution resolves —
     * TaskExecutionService asks the same resolver, so the estimate prices the
     * model that will answer. A manual task's input is typed after the page
     * loads and is not part of the estimate; the page says so.
     */
    private function estimate(Task $task, string $resolvedInput): TaskCostEstimate
    {
        $manual = $task->requiresManualInput();

        return $this->costEstimator->estimate(
            $task,
            $this->llmServiceManager->resolveEffectiveConfiguration($task->getConfiguration()),
            $manual ? '' : $resolvedInput,
            !$manual,
        );
    }

    private function verdict(Task $task, TaskCostEstimate $estimate): TaskBudgetVerdict
    {
        return $this->budgetPreflight->verdict(
            $this->currentBackendUserUid(),
            $this->llmServiceManager->resolveEffectiveConfiguration($task->getConfiguration()),
            $estimate,
        );
    }

    /**
     * The verdict as one sentence, built here for the page and the JSON
     * refresh alike so both say the same. It names the limit and no number.
     *
     * @return array{state: string, message: string}
     */
    private function verdictPayload(TaskBudgetVerdict $verdict): array
    {
        if ($verdict->state === TaskBudgetVerdict::ALLOWED) {
            return [
                'state'   => $verdict->state,
                'message' => $this->localize(self::LL . 'aitasks.budget.verdict.allowed', 'Your next run of this task is within your limits.'),
            ];
        }

        $limit = $this->localize(
            self::LL . 'aitasks.budget.limit.' . $verdict->exceededLimit,
            self::LIMIT_FALLBACKS[$verdict->exceededLimit] ?? $verdict->exceededLimit,
        );
        $template = $verdict->state === TaskBudgetVerdict::DENIED
            ? $this->localize(self::LL . 'aitasks.budget.verdict.denied', 'Your next run of this task will be refused. This limit is reached: %s.')
            : $this->localize(self::LL . 'aitasks.budget.verdict.exceedsAtUpperEstimate', 'Your next run of this task can start. At the upper cost estimate it would exceed this limit: %s.');

        return [
            'state'   => $verdict->state,
            'message' => sprintf($template, $limit),
        ];
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
