<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\DTO;

use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;

/**
 * Factory for creating/updating Task entities from form input DTOs.
 *
 * This service encapsulates the logic for mapping form data to domain entities,
 * including resolving relations via repositories. This keeps controllers thin
 * and ensures Single Responsibility Principle compliance.
 *
 * @internal Not part of public API, may change without notice.
 */
final readonly class TaskFormInputFactory
{
    public function __construct(
        private LlmConfigurationRepository $configurationRepository,
    ) {}

    /**
     * Create a new Task entity from form input.
     */
    public function createFromInput(TaskFormInput $input): Task
    {
        $task = new Task();
        $this->applyInput($task, $input);
        return $task;
    }

    /**
     * Update an existing Task entity from form input.
     */
    public function updateFromInput(Task $task, TaskFormInput $input): void
    {
        $this->applyInput($task, $input);
    }

    /**
     * Apply form input data to a Task entity.
     */
    private function applyInput(Task $task, TaskFormInput $input): void
    {
        $task->setIdentifier($input->identifier);
        $task->setName($input->name);
        $task->setDescription($input->description);
        $task->setCategory($input->category);
        $task->setPromptTemplate($input->promptTemplate);
        $task->setInputType($input->inputType);
        $task->setInputSourceArray($input->inputSourceConfig);
        $task->setOutputFormat($input->outputFormat);
        $task->setIsActive($input->isActive);
        $task->setIsSystem($input->isSystem);
        $task->setSorting($input->sorting);

        // Resolve and set configuration relation
        if ($input->configurationUid > 0) {
            $configuration = $this->configurationRepository->findByUid($input->configurationUid);
            $task->setConfiguration($configuration);
        } else {
            $task->setConfiguration(null);
        }
    }
}
