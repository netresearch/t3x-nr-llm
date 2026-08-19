<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Task;

use Netresearch\NrLlm\Domain\Enum\TaskOutputFormat;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\UsageMiddleware;
use Netresearch\NrLlm\Service\CallMetadataFactory;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestrates running a `Task` through the LLM.
 *
 * Owns the steps that previously sat inline in
 * `TaskController::executeAction()`: build the prompt with the user
 * input, route through the task's `LlmConfiguration` if one is set
 * (otherwise fall back to the global default chat options), and
 * package the response as a typed `TaskExecutionResult`.
 *
 * **REC #4 (audit), landed:** the controller passes the calling backend
 * user's uid, which flows as budget metadata into the pipeline — the
 * `BudgetMiddleware` pre-flights the per-user cap and denies with
 * `BudgetExceededException` before the provider is paid, and the
 * `UsageMiddleware` attributes the recorded usage to that user. A
 * null/0 uid skips the user cap (configuration limits still apply).
 */
final readonly class TaskExecutionService implements TaskExecutionServiceInterface
{
    public function __construct(
        private LlmServiceManagerInterface $llmServiceManager,
        private SkillInjectionService $skillInjection,
        private CallMetadataFactory $callMetadata = new CallMetadataFactory(),
    ) {}

    public function execute(Task $task, string $input, ?int $beUserUid = null): TaskExecutionResult
    {
        $prompt = $task->buildPrompt(['input' => $input]);

        $taskUid  = $task->getUid();
        $metadata = ($taskUid !== null && $taskUid > 0) ? [UsageMiddleware::METADATA_TASK_UID => $taskUid] : [];
        if ($beUserUid !== null && $beUserUid > 0) {
            $metadata[BudgetMiddleware::METADATA_BE_USER_UID] = $beUserUid;
        }

        // Resolve the effective configuration exactly once: the task's own
        // configuration when set, otherwise the backend-managed active default
        // (resolved by LlmServiceManager with the same guards as a generic call).
        // Routing through this single resolution — and dispatching via
        // completeWithConfiguration(), which does NOT re-inject — guarantees the
        // skill block is composed exactly once: without it a configuration-less
        // task would inject the task skills here and then have complete() resolve
        // the default and inject ITS skills again (double preamble, leaked default
        // skills, appliedSkills missing the default's skills).
        $configuration = $this->llmServiceManager->resolveEffectiveConfiguration($task->getConfiguration());

        // The effective configuration's skills form the baseline, the task's own
        // skills are additive (precedence + dedup handled by SkillComposer). The
        // applied identifiers therefore cover BOTH sets, deduped.
        $configSkills = $configuration instanceof LlmConfiguration
            ? SkillInjectionService::toList($configuration->getSkills())
            : [];
        [$prompt, $appliedSkills] = $this->skillInjection->augmentPromptWithReport(
            $prompt,
            $configSkills,
            SkillInjectionService::toList($task->getSkills()),
        );

        // A JSON task gets real JSON mode (ADR-128) instead of hoping the
        // prompt says so. The appended instruction is load-bearing twice: it
        // tells the model what shape is wanted, and OpenAI-dialect JSON mode
        // requires the word "json" in the messages — a user-authored
        // prompt_template cannot guarantee that.
        $jsonOutput = $task->getOutputFormatEnum() === TaskOutputFormat::JSON;
        if ($jsonOutput) {
            $prompt .= "\n\nRespond with valid JSON only. No markdown, no explanation.";
        }

        // With no resolvable configuration the task cannot run; the generic path
        // raises the existing "no provider specified" error — preserve that.
        // completeWithConfiguration() takes plain option-override arrays, not
        // ChatOptions — the fourth argument merges over the configuration's
        // stored defaults. On the default path the uid travels as a ChatOptions
        // budget field instead; the manager builds the same metadata from it.
        $fallbackOptions = new ChatOptions();
        if ($jsonOutput) {
            $fallbackOptions = $fallbackOptions->withResponseFormat('json');
        }

        if ($beUserUid !== null && $beUserUid > 0) {
            $fallbackOptions = $fallbackOptions->withBeUserUid($beUserUid);
        }

        // Minted here so the result can carry it. The pipeline would generate
        // one anyway, but inside itself and without handing it back, which
        // leaves a caller unable to say which telemetry row was its own call
        // (ADR-176). Both branches must receive the SAME id — they reach the
        // pipeline through different channels, and a second id here would
        // split one execution across two traces.
        $correlationId = Uuid::v4()->toRfc4122();

        $response = $configuration instanceof LlmConfiguration
            ? $this->llmServiceManager->completeWithConfiguration(
                $prompt,
                $configuration,
                $metadata + $this->callMetadata->correlation($correlationId),
                $jsonOutput ? ['response_format' => 'json'] : [],
            )
            : $this->llmServiceManager->complete($prompt, $fallbackOptions->withCorrelationId($correlationId));

        // The skills injected above contributed to the prompt the provider
        // tokenised, so their cost is already part of $response->usage. Surface
        // the applied identifiers to attribute that post-call usage to skills.
        return new TaskExecutionResult(
            content: $response->content,
            model: $response->model,
            outputFormat: $task->getOutputFormat(),
            usage: $response->usage,
            appliedSkills: $appliedSkills,
            correlationId: $correlationId,
        );
    }
}
