<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Asks a configured provider which models it offers, and what one model's
 * limits are.
 *
 * Split out of ModelController following ADR-027's per-pathway shape: both
 * actions talk to the provider's catalogue rather than to stored records, and
 * neither touches the model repository.
 *
 * Reached only through AJAX routes, which dispatch outside the module route and
 * so bypass its `access => 'admin'`; every action calls denyNonAdmin() first
 * (ADR-037).
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final class ModelDiscoveryController extends ActionController
{
    use RequiresBackendAdminTrait;
    use DefensiveLocalizationTrait;
    use ProviderMisconfigurationTrait;

    private const ERROR_NO_PROVIDER_UID = 'No provider UID specified';

    public function __construct(
        private readonly ProviderRepository $providerRepository,
        private readonly ModelDiscoveryInterface $modelDiscovery,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * AJAX: Fetch available model IDs from provider's API.
     *
     * Uses the ModelDiscoveryService to query the provider's API and return
     * a list of available models that can be used in the model_id field.
     */
    public function fetchAvailableModelsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $body = $request->getParsedBody();
        $providerUid = $this->extractIntFromBody($body, 'providerUid');

        if ($providerUid === 0) {
            return new JsonResponse([
                'success' => false,
                'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.model.noProviderUid', self::ERROR_NO_PROVIDER_UID),
            ], 400);
        }

        $provider = $this->providerRepository->findByUid($providerUid);
        if ($provider === null) {
            return new JsonResponse([
                'success' => false,
                'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.notFound', 'Provider not found'),
            ], 404);
        }

        return $this->discoverAvailableModels($provider);
    }

    /**
     * Query the provider's API for the list of available models.
     */
    private function discoverAvailableModels(Provider $provider): ResponseInterface
    {
        try {
            // Create a DetectedProvider to use with ModelDiscoveryService
            $detected = new DetectedProvider(
                adapterType: $provider->getAdapterType(),
                suggestedName: $provider->getName(),
                endpoint: $provider->getEffectiveEndpointUrl(),
            );

            // Discover available models from the provider's API
            $discoveredModels = $this->modelDiscovery->discover(
                $detected,
                $provider->getDecryptedApiKey(),
            );

            // Convert to simple array for JSON response
            $models = [];
            foreach ($discoveredModels as $model) {
                $models[] = [
                    'id' => $model->modelId,
                    'name' => $model->name,
                    'description' => $model->description,
                    'contextLength' => $model->contextLength,
                    'maxOutputTokens' => $model->maxOutputTokens,
                    'capabilities' => $model->capabilities,
                    'costInput' => $model->costInput,
                    'costOutput' => $model->costOutput,
                    'recommended' => $model->recommended,
                ];
            }

            return new JsonResponse([
                'success' => true,
                'models' => $models,
                'providerName' => $provider->getName(),
                'source' => $this->modelDiscovery->wasLastDiscoveryFromFallback() ? 'fallback' : 'live',
            ]);
        } catch (ProviderConfigurationException $e) {
            $error = $this->describeMisconfiguration($e, $this->logger, 'Model detectLimits');
            $status = 502;
        } catch (ProviderException $e) {
            $this->logger->warning('Model fetchAvailableModels: provider error', ['exception' => $e]);
            $error = 'LLM provider error while fetching model IDs. See system log for details.';
            $status = 502;
        } catch (Throwable $e) {
            $this->logger->error('Model fetchAvailableModels: unexpected error', ['exception' => $e]);
            $error = 'Failed to fetch model IDs. See system log for details.';
            $status = 500;
        }

        return new JsonResponse([
            'success' => false,
            'error' => $error,
        ], $status);
    }

    /**
     * AJAX: Detect model limits by querying the provider's API.
     *
     * Takes a provider UID and model ID, queries the provider's API,
     * and returns the model's context length, max output tokens, and capabilities.
     */
    public function detectLimitsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $body = $request->getParsedBody();
        $providerUid = $this->extractIntFromBody($body, 'providerUid');
        $modelId = $this->extractStringFromBody($body, 'modelId');

        $validationError = $this->validateDetectLimitsInput($providerUid, $modelId);
        if ($validationError instanceof ResponseInterface) {
            return $validationError;
        }

        $provider = $this->providerRepository->findByUid($providerUid);
        if ($provider === null) {
            return new JsonResponse([
                'success' => false,
                'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.provider.notFound', 'Provider not found'),
            ], 404);
        }

        return $this->discoverModelLimits($provider, $modelId);
    }

    /**
     * Validate the detect-limits request input.
     *
     * Returns a JSON error response when invalid, or null when the input is valid.
     */
    private function validateDetectLimitsInput(int $providerUid, string $modelId): ?ResponseInterface
    {
        if ($providerUid === 0) {
            return new JsonResponse([
                'success' => false,
                'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.model.noProviderUid', self::ERROR_NO_PROVIDER_UID),
            ], 400);
        }

        if ($modelId === '') {
            return new JsonResponse([
                'success' => false,
                'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.model.noModelId', 'No model ID specified'),
            ], 400);
        }

        return null;
    }

    /**
     * Query the provider's API and return the limits for a specific model.
     */
    private function discoverModelLimits(Provider $provider, string $modelId): ResponseInterface
    {
        try {
            // Create a DetectedProvider to use with ModelDiscovery
            $detected = new DetectedProvider(
                adapterType: $provider->getAdapterType(),
                suggestedName: $provider->getName(),
                endpoint: $provider->getEffectiveEndpointUrl(),
            );

            // Discover available models from the provider's API
            $discoveredModels = $this->modelDiscovery->discover(
                $detected,
                $provider->getDecryptedApiKey(),
            );

            // Find the specific model
            $foundModel = null;
            foreach ($discoveredModels as $model) {
                if ($model->modelId === $modelId) {
                    $foundModel = $model;
                    break;
                }
            }

            if ($foundModel === null) {
                return new JsonResponse([
                    'success' => false,
                    'error' => sprintf($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.model.modelIdNotFound', 'Model "%s" not found in provider\'s available models'), $modelId),
                ], 404);
            }

            return new JsonResponse([
                'success' => true,
                'modelId' => $foundModel->modelId,
                'name' => $foundModel->name,
                'description' => $foundModel->description,
                'contextLength' => $foundModel->contextLength,
                'maxOutputTokens' => $foundModel->maxOutputTokens,
                'capabilities' => $foundModel->capabilities,
                'costInput' => $foundModel->costInput,
                'costOutput' => $foundModel->costOutput,
            ]);
        } catch (ProviderConfigurationException $e) {
            // A misconfiguration is our own diagnostic, not a provider response
            // body: the message names the setting at fault and is what the
            // administrator on this screen has to act on. ErrorResponse redacts
            // credential-bearing URL parameters at the boundary.
            $this->logger->warning('Model detectLimits: provider is misconfigured', ['exception' => $e]);
            $error = $this->sanitizeErrorMessage($e->getMessage());
            $status = 502;
        } catch (ProviderException $e) {
            $this->logger->warning('Model detectLimits: provider error', ['exception' => $e]);
            $error = 'LLM provider error while detecting model limits. See system log for details.';
            $status = 502;
        } catch (Throwable $e) {
            $this->logger->error('Model detectLimits: unexpected error', ['exception' => $e]);
            $error = 'Failed to detect model limits. See system log for details.';
            $status = 500;
        }

        return new JsonResponse([
            'success' => false,
            'error' => $error,
        ], $status);
    }

    /**
     * Extract integer value from request body.
     */
    private function extractIntFromBody(mixed $body, string $key): int
    {
        if (!is_array($body)) {
            return 0;
        }

        $value = $body[$key] ?? 0;

        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * Extract string value from request body.
     */
    private function extractStringFromBody(mixed $body, string $key): string
    {
        if (!is_array($body)) {
            return '';
        }

        $value = $body[$key] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }

}
