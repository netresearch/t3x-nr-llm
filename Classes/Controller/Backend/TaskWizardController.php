<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Domain\Repository\TaskRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\WizardGeneratorService;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Backend controller for the AI-powered Task wizard.
 *
 * Slice 13e of the `TaskController` split (ADR-027). Owns the four
 * wizard actions:
 *
 * - `wizardFormAction()` — render the natural-language description
 *   input form
 * - `wizardGenerateAction()` — single-task generation preview
 * - `wizardGenerateChainAction()` — full task + configuration +
 *   model preview
 * - `wizardCreateAction()` — persist a wizard-generated chain
 *
 * Constructor scope is wider than the sibling controllers (the
 * wizard touches every relevant repository) but narrower than the
 * pre-13e god class.
 */
#[AsController]
final class TaskWizardController extends ActionController
{
    use SafeCastTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly TaskRepository $taskRepository,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly ModelRepository $modelRepository,
        private readonly WizardGeneratorService $wizardGeneratorService,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly FlashMessageService $flashMessageService,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly LoggerInterface $logger,
    ) {}

    public function wizardFormAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/WizardFormLoading.js');

        $availableConfigs = $this->configurationRepository->findActive();
        $resolvedConfig   = $this->wizardGeneratorService->resolveConfiguration();

        $moduleTemplate->assignMultiple([
            'wizardType'       => 'task',
            'availableConfigs' => $availableConfigs,
            'resolvedConfig'   => $resolvedConfig,
        ]);
        return $moduleTemplate->renderResponse('Backend/Task/WizardForm');
    }

    public function wizardGenerateAction(string $description = '', int $configurationUid = 0): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $description = trim($description);
        if ($description === '') {
            $this->enqueueFlashMessage('Please describe what this task should do.', 'Missing description', ContextualFeedbackSeverity::WARNING);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }
        if (mb_strlen($description) > 2000) {
            $description = mb_substr($description, 0, 2000);
        }

        try {
            $config = $this->wizardGeneratorService->resolveConfiguration(
                $configurationUid > 0 ? $configurationUid : null,
            );
            $result = $this->wizardGeneratorService->generateTask($description, $config);
            $table = 'tx_nrllm_task';
            $params = [
                'edit'      => [$table => [0 => 'new']],
                'returnUrl' => $this->buildReturnUrl(),
            ];
            foreach ($result as $key => $value) {
                if ($value !== '' && $value !== null && $key !== 'generated' && $key !== 'recommended_model') {
                    $params['defVals[' . $table . '][' . $key . ']'] = is_string($value) || is_numeric($value) ? (string)$value : '';
                }
            }
            $newUrl = (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', $params);

            $moduleTemplate->assignMultiple([
                'wizardType'       => 'task',
                'description'      => $description,
                'generated'        => $result,
                'newUrl'           => $newUrl,
                'usedConfig'       => $config,
                'configurationUid' => $configurationUid,
            ]);
            return $moduleTemplate->renderResponse('Backend/Task/WizardPreview');
        } catch (ProviderException $e) {
            // REC #8b: provider error text often references endpoints / payloads /
            // model names that aren't safe to render verbatim into the backend UI.
            $this->logger->error('Task wizard: single-task generation failed (provider)', ['exception' => $e]);
            $this->enqueueFlashMessage('Generation failed (LLM provider error). See system log for details.', 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        } catch (Throwable $e) {
            $this->logger->error('Task wizard: single-task generation failed unexpectedly', ['exception' => $e]);
            $this->enqueueFlashMessage('Generation failed. See system log for details.', 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }
    }

    public function wizardGenerateChainAction(string $description = '', int $configurationUid = 0): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $description = trim($description);
        if ($description === '') {
            $this->enqueueFlashMessage('Please describe what this task should do.', 'Missing description', ContextualFeedbackSeverity::WARNING);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }
        if (mb_strlen($description) > 2000) {
            $description = mb_substr($description, 0, 2000);
        }

        try {
            $config = $this->wizardGeneratorService->resolveConfiguration(
                $configurationUid > 0 ? $configurationUid : null,
            );
            $result = $this->wizardGeneratorService->generateTaskWithChain($description, $config);

            $rawModelId = $result['recommended_model_id'] ?? '';
            $recommendedModelId = is_string($rawModelId) || is_numeric($rawModelId) ? (string)$rawModelId : '';
            $existingModel = $this->wizardGeneratorService->findBestExistingModel($recommendedModelId);
            $existingConfig = $this->wizardGeneratorService->findBestExistingConfiguration($description);

            $moduleTemplate->assignMultiple([
                'wizardType'       => 'task',
                'description'      => $description,
                'chain'            => $result,
                'existingModel'    => $existingModel,
                'existingConfig'   => $existingConfig,
                'usedConfig'       => $config,
                'configurationUid' => $configurationUid,
            ]);
            return $moduleTemplate->renderResponse('Backend/Task/WizardChainPreview');
        } catch (ProviderException $e) {
            $this->logger->error('Task wizard: chain generation failed (provider)', ['exception' => $e]);
            $this->enqueueFlashMessage('Generation failed (LLM provider error). See system log for details.', 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        } catch (Throwable $e) {
            $this->logger->error('Task wizard: chain generation failed unexpectedly', ['exception' => $e]);
            $this->enqueueFlashMessage('Generation failed. See system log for details.', 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }
    }

    public function wizardCreateAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        if (!is_array($body)) {
            $this->enqueueFlashMessage('Invalid request.', 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }

        $taskData          = is_array($body['task'] ?? null) ? $body['task'] : [];
        $configData        = is_array($body['configuration'] ?? null) ? $body['configuration'] : [];
        $modelChoice       = self::toStr($body['model_choice'] ?? 'existing');
        $existingModelUid  = self::toInt($body['existing_model_uid'] ?? 0);
        $configChoice      = self::toStr($body['config_choice'] ?? 'new');
        $existingConfigUid = self::toInt($body['existing_config_uid'] ?? 0);

        try {
            // Step 1: Resolve or create Model
            $model = null;
            if ($modelChoice === 'existing' && $existingModelUid > 0) {
                $model = $this->modelRepository->findByUid($existingModelUid);
            }
            if (!$model instanceof Model) {
                $model = $this->modelRepository->findDefault();
            }
            if (!$model instanceof Model) {
                $first = $this->modelRepository->findActive()->getFirst();
                if ($first instanceof Model) {
                    $model = $first;
                }
            }

            // Step 2: Resolve or create Configuration
            $configuration = null;
            if ($configChoice === 'existing' && $existingConfigUid > 0) {
                $configuration = $this->configurationRepository->findByUid($existingConfigUid);
            }

            if ($configChoice === 'new' || !$configuration instanceof LlmConfiguration) {
                $configuration = new LlmConfiguration();
                $configuration->setIdentifier(self::toStr($configData['identifier'] ?? 'task-config'));
                $configuration->setName(self::toStr($configData['name'] ?? 'Task Configuration'));
                $configuration->setDescription(self::toStr($configData['description'] ?? ''));
                $configuration->setSystemPrompt(self::toStr($configData['system_prompt'] ?? ''));
                $configuration->setTemperature(max(0.0, min(2.0, self::toFloat($configData['temperature'] ?? 0.7))));
                $configuration->setMaxTokens(max(1, min(128000, self::toInt($configData['max_tokens'] ?? 4096))));
                $configuration->setTopP(max(0.0, min(1.0, self::toFloat($configData['top_p'] ?? 1.0))));
                $configuration->setFrequencyPenalty(max(-2.0, min(2.0, self::toFloat($configData['frequency_penalty'] ?? 0.0))));
                $configuration->setPresencePenalty(max(-2.0, min(2.0, self::toFloat($configData['presence_penalty'] ?? 0.0))));
                $configuration->setIsActive(true);
                if ($model instanceof Model) {
                    $configuration->setLlmModel($model);
                }
                $this->configurationRepository->add($configuration);
                $this->persistenceManager->persistAll();
            }

            // Step 3: Create Task
            $task = new Task();
            $task->setIdentifier(self::toStr($taskData['identifier'] ?? 'new-task'));
            $task->setName(self::toStr($taskData['name'] ?? 'New Task'));
            $task->setDescription(self::toStr($taskData['description'] ?? ''));
            $allowedCategories    = ['content', 'log_analysis', 'system', 'developer', 'general'];
            $allowedOutputFormats = ['markdown', 'json', 'plain', 'html'];

            $category = self::toStr($taskData['category'] ?? 'general');
            $task->setCategory(in_array($category, $allowedCategories, true) ? $category : 'general');
            $task->setPromptTemplate(self::toStr($taskData['prompt_template'] ?? ''));

            $outputFormat = self::toStr($taskData['output_format'] ?? 'markdown');
            $task->setOutputFormat(in_array($outputFormat, $allowedOutputFormats, true) ? $outputFormat : 'markdown');
            $task->setConfiguration($configuration);
            $task->setIsActive(true);
            $this->taskRepository->add($task);
            $this->persistenceManager->persistAll();

            $this->enqueueFlashMessage(
                sprintf('Task "%s" created successfully with dedicated configuration.', $task->getName()),
                'Task Created',
                ContextualFeedbackSeverity::OK,
            );

            // Redirect to the task's execute form (now on TaskExecutionController).
            $taskUid = $task->getUid();
            if ($taskUid !== null) {
                return new RedirectResponse(
                    $this->uriBuilder->reset()->uriFor('executeForm', ['uid' => $taskUid], 'TaskExecution'),
                );
            }

            return new RedirectResponse($this->uriBuilder->reset()->uriFor('list', [], 'TaskList'));
        } catch (Throwable $e) {
            // wizardCreateAction persists Extbase entities through the
            // PersistenceManager. Failure modes are mostly Doctrine /
            // Extbase persistence errors whose messages reference
            // schema and connection internals — log and surface a
            // generic message.
            $this->logger->error('Task wizard: failed to persist generated task', ['exception' => $e]);
            $this->enqueueFlashMessage('Failed to create task. See system log for details.', 'Error', ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('wizardForm'));
        }
    }

    private function buildReturnUrl(): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_tasks');
    }

    private function enqueueFlashMessage(
        string $message,
        string $title,
        ContextualFeedbackSeverity $severity,
    ): void {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            $message,
            $title,
            $severity,
            true,
        );
        $this->flashMessageService
            ->getMessageQueueByIdentifier()
            ->addMessage($flashMessage);
    }
}
