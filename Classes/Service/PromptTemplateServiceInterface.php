<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\PromptTemplate;
use Netresearch\NrLlm\Domain\Model\RenderedPrompt;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Exception\PromptTemplateNotFoundException;

/**
 * Public surface of the prompt-template service.
 *
 * Consumers (controllers, feature services, tests) should depend on this
 * interface rather than the concrete `PromptTemplateService` so the
 * implementation can be substituted without inheritance.
 */
interface PromptTemplateServiceInterface
{
    /**
     * Get active prompt template by identifier.
     *
     * @param string $identifier Unique prompt identifier
     *
     * @throws PromptTemplateNotFoundException
     */
    public function getPrompt(string $identifier): PromptTemplate;

    /**
     * Render prompt with variables.
     *
     * @param string               $identifier Prompt template identifier
     * @param array<string, mixed> $variables  Template variables
     * @param array<string, mixed> $options    Rendering options
     *
     * @throws PromptTemplateNotFoundException
     * @throws InvalidArgumentException
     */
    public function render(
        string $identifier,
        array $variables = [],
        array $options = [],
    ): RenderedPrompt;

    /**
     * Create new version of existing template.
     *
     * @param string               $identifier Base template identifier
     * @param array<string, mixed> $updates    Fields to update
     *
     * @throws PromptTemplateNotFoundException
     */
    public function createVersion(string $identifier, array $updates): PromptTemplate;

    /**
     * Get A/B test variant.
     *
     * @param string $identifier  Base template identifier
     * @param string $variantName Variant name/tag
     *
     * @throws PromptTemplateNotFoundException
     */
    public function getVariant(string $identifier, string $variantName): PromptTemplate;

    /**
     * Record usage statistics for prompt.
     *
     * @param string $identifier   Prompt template identifier
     * @param int    $responseTime Response time in milliseconds
     * @param int    $tokensUsed   Total tokens used
     * @param float  $qualityScore Quality score (0.0-1.0)
     */
    public function recordUsage(
        string $identifier,
        int $responseTime,
        int $tokensUsed,
        float $qualityScore,
    ): void;

    /**
     * Get all templates for a feature.
     *
     * @param string $feature Feature identifier
     *
     * @return array<int, PromptTemplate>
     */
    public function getTemplatesForFeature(string $feature): array;
}
