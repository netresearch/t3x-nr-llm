<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\TaskOutputFormat;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Task\TaskExecutionService;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;

/**
 * The agent run that executes one AI Task in the background (ADR-234).
 *
 * The same request {@see TaskExecutionService} sends, in the shape the queue
 * carries: the task's configuration or the active default, its prompt, its
 * skills on top of the configuration's, JSON mode for a JSON task, and the task
 * uid on the usage row. An empty tool list makes the run one plain completion;
 * a task has never had tools, and queueing one must not hand it any.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TaskRunRequestFactory
{
    public function __construct(
        private LlmServiceManagerInterface $llmServiceManager,
    ) {}

    /**
     * Null when neither the task nor the installation names a configuration:
     * there is nothing to run the task on, and the interactive path refuses
     * that case as well.
     */
    public function build(Task $task, string $input, AiActorContext $actor): ?AgentRunRequest
    {
        $configuration = $this->llmServiceManager->resolveEffectiveConfiguration($task->getConfiguration());
        if (!$configuration instanceof LlmConfiguration) {
            return null;
        }

        $prompt  = $task->buildPrompt(['input' => $input]);
        $options = new ToolOptions(beUserUid: $actor->backendUserUid > 0 ? $actor->backendUserUid : null);

        if ($task->getOutputFormatEnum() === TaskOutputFormat::JSON) {
            $prompt .= TaskExecutionService::JSON_INSTRUCTION;
            $options = $options->withResponseFormat('json');
        }

        $taskUid = $task->getUid();
        if ($taskUid !== null && $taskUid > 0) {
            $options = $options->withTaskUid($taskUid);
        }

        $skills = SkillInjectionService::toList($task->getSkills());

        return new AgentRunRequest(
            configuration: $configuration,
            messages: [ChatMessage::user($prompt)],
            actor: $actor,
            allowedToolNames: [],
            options: $options,
            // Null without task skills: a non-null augmentation changes how the
            // loop assembles the prompt (ADR-102).
            augmentation: $skills !== [] ? new RunAugmentation(forcedSkills: $skills) : null,
            origin: 'task:' . $task->getIdentifier(),
        );
    }
}
