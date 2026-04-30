<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;

/**
 * Public surface of the AI-powered wizard generator service.
 *
 * Consumers (controllers, tests, downstream extensions) should depend on
 * this interface rather than the concrete `WizardGeneratorService` so the
 * implementation can be substituted without inheritance.
 */
interface WizardGeneratorServiceInterface
{
    /**
     * Resolve the configuration that will be used for generation.
     *
     * Public so controllers can show the user which LLM powers the wizard.
     */
    public function resolveConfiguration(?int $configurationUid = null): ?LlmConfiguration;

    /**
     * Generate a configuration from a description.
     *
     * @return array<string, mixed> Generated configuration fields
     */
    public function generateConfiguration(string $description, ?LlmConfiguration $config = null): array;

    /**
     * Generate a task from a description.
     *
     * @return array<string, mixed> Generated task fields
     */
    public function generateTask(string $description, ?LlmConfiguration $config = null): array;

    /**
     * Generate a task with its full chain (task + configuration + model recommendation).
     *
     * Returns task fields, a dedicated configuration, and the best-fitting existing
     * model plus an AI-suggested model specification for cases where no good match exists.
     *
     * @return array<string, mixed> Keys: task, configuration, existing_model, suggested_model, generated
     */
    public function generateTaskWithChain(string $description, ?LlmConfiguration $config = null): array;

    /**
     * Find the best existing model for a recommended model ID.
     */
    public function findBestExistingModel(string $recommendedModelId): ?Model;

    /**
     * Find the best existing configuration for a task's needs.
     */
    public function findBestExistingConfiguration(string $description): ?LlmConfiguration;
}
