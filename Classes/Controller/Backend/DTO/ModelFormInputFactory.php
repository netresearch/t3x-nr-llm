<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\DTO;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;

/**
 * Factory for creating/updating Model entities from form input DTOs.
 *
 * This service encapsulates the logic for mapping form data to domain entities,
 * including resolving relations via repositories. This keeps controllers thin
 * and ensures Single Responsibility Principle compliance.
 *
 * @internal Not part of public API, may change without notice.
 */
final readonly class ModelFormInputFactory
{
    public function __construct(
        private ProviderRepository $providerRepository,
    ) {}

    /**
     * Create a new Model entity from form input.
     */
    public function createFromInput(ModelFormInput $input): Model
    {
        $model = new Model();
        $this->applyInput($model, $input);
        return $model;
    }

    /**
     * Update an existing Model entity from form input.
     */
    public function updateFromInput(Model $model, ModelFormInput $input): void
    {
        $this->applyInput($model, $input);
    }

    /**
     * Apply form input data to a Model entity.
     */
    private function applyInput(Model $model, ModelFormInput $input): void
    {
        $model->setIdentifier($input->identifier);
        $model->setName($input->name);
        $model->setDescription($input->description);
        $model->setModelId($input->modelId);
        $model->setContextLength($input->contextLength);
        $model->setMaxOutputTokens($input->maxOutputTokens);
        $model->setDefaultTimeout($input->defaultTimeout);
        $model->setCapabilitiesArray($input->capabilities);
        $model->setCostInput($input->costInput);
        $model->setCostOutput($input->costOutput);
        $model->setIsActive($input->isActive);
        $model->setIsDefault($input->isDefault);

        // Resolve and set provider relation
        if ($input->providerUid > 0) {
            $provider = $this->providerRepository->findByUid($input->providerUid);
            $model->setProvider($provider);
        } else {
            $model->setProvider(null);
        }
    }
}
