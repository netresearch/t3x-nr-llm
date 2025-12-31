<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\DTO;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;

/**
 * Factory for creating/updating LlmConfiguration entities from form input DTOs.
 *
 * This service encapsulates the logic for mapping form data to domain entities,
 * including resolving relations via repositories. This keeps controllers thin
 * and ensures Single Responsibility Principle compliance.
 *
 * @internal Not part of public API, may change without notice.
 */
final readonly class ConfigurationFormInputFactory
{
    public function __construct(
        private ModelRepository $modelRepository,
    ) {}

    /**
     * Create a new LlmConfiguration entity from form input.
     */
    public function createFromInput(ConfigurationFormInput $input): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $this->applyInput($configuration, $input);
        return $configuration;
    }

    /**
     * Update an existing LlmConfiguration entity from form input.
     */
    public function updateFromInput(LlmConfiguration $configuration, ConfigurationFormInput $input): void
    {
        $this->applyInput($configuration, $input);
    }

    /**
     * Apply form input data to an LlmConfiguration entity.
     */
    private function applyInput(LlmConfiguration $configuration, ConfigurationFormInput $input): void
    {
        $configuration->setIdentifier($input->identifier);
        $configuration->setName($input->name);
        $configuration->setDescription($input->description);
        $configuration->setModelSelectionMode($input->modelSelectionMode);
        // @phpstan-ignore argument.type (DTO criteria is flexible, domain validates)
        $configuration->setModelSelectionCriteriaArray($input->modelSelectionCriteria);
        $configuration->setSystemPrompt($input->systemPrompt);
        $configuration->setTemperature($input->temperature);
        $configuration->setMaxTokens($input->maxTokens);
        $configuration->setTopP($input->topP);
        $configuration->setFrequencyPenalty($input->frequencyPenalty);
        $configuration->setPresencePenalty($input->presencePenalty);
        $configuration->setTimeout($input->timeout);
        $configuration->setMaxRequestsPerDay($input->maxRequestsPerDay);
        $configuration->setMaxTokensPerDay($input->maxTokensPerDay);
        $configuration->setMaxCostPerDay($input->maxCostPerDay);
        $configuration->setIsActive($input->isActive);
        $configuration->setIsDefault($input->isDefault);

        // Resolve and set model relation
        if ($input->modelUid > 0) {
            $model = $this->modelRepository->findByUid($input->modelUid);
            $configuration->setLlmModel($model);
        } else {
            $configuration->setLlmModel(null);
        }
    }
}
