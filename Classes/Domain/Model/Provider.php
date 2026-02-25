<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Throwable;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * Domain model for LLM Providers.
 *
 * Represents an API provider connection with endpoint, credentials, and adapter type.
 * Supports multiple provider instances of the same type (e.g., openai-prod, openai-dev).
 */
class Provider extends AbstractEntity
{
    protected string $identifier = '';
    protected string $name = '';
    protected string $description = '';
    protected string $adapterType = '';
    protected string $endpointUrl = '';
    protected string $apiKey = '';
    protected string $organizationId = '';
    protected int $apiTimeout = 30;
    protected int $maxRetries = 3;
    protected string $options = '';
    protected bool $isActive = true;
    protected int $priority = 50;
    protected int $sorting = 0;
    protected int $tstamp = 0;
    protected int $crdate = 0;
    protected int $cruserId = 0;

    /**
     * Models associated with this provider.
     *
     * @var ObjectStorage<Model>|null
     */
    protected ?ObjectStorage $models = null;

    public function __construct()
    {
        $this->models = new ObjectStorage();
    }

    // ========================================
    // Getters
    // ========================================

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAdapterType(): string
    {
        return $this->adapterType;
    }

    /**
     * Get the adapter type as enum.
     *
     * @return AdapterType|null Null if stored value doesn't match any enum case
     */
    public function getAdapterTypeEnum(): ?AdapterType
    {
        return AdapterType::tryFrom($this->adapterType);
    }

    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }

    /**
     * Get the raw (encrypted) API key value from database.
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Get the decrypted API key for use in API calls.
     *
     * This method retrieves the API key from nr-vault using the stored identifier.
     * The value is retrieved on-demand and not cached to minimize exposure.
     */
    public function getDecryptedApiKey(): string
    {
        if ($this->apiKey === '') {
            return '';
        }

        try {
            // apiKey contains a vault identifier (e.g., "tx_nrllm_provider__api_key__123")
            $vault = GeneralUtility::makeInstance(VaultServiceInterface::class);
            return $vault->retrieve($this->apiKey) ?? '';
        } catch (Throwable) {
            // If retrieval fails, return empty string
            return '';
        }
    }

    public function getOrganizationId(): string
    {
        return $this->organizationId;
    }

    public function getApiTimeout(): int
    {
        return $this->apiTimeout;
    }

    /**
     * Alias for getApiTimeout.
     */
    public function getTimeout(): int
    {
        return $this->getApiTimeout();
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getOptions(): string
    {
        return $this->options;
    }

    /**
     * Get parsed options array.
     *
     * @return array<string, mixed>
     */
    public function getOptionsArray(): array
    {
        if ($this->options === '') {
            return [];
        }
        $decoded = json_decode($this->options, true);
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getSorting(): int
    {
        return $this->sorting;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function getCrdate(): int
    {
        return $this->crdate;
    }

    public function getCruserId(): int
    {
        return $this->cruserId;
    }

    /**
     * @return ObjectStorage<Model>|null
     */
    public function getModels(): ?ObjectStorage
    {
        return $this->models;
    }

    // ========================================
    // Setters
    // ========================================

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function setAdapterType(string $adapterType): void
    {
        $this->adapterType = $adapterType;
    }

    /**
     * Set the adapter type from an AdapterType enum.
     */
    public function setAdapterTypeEnum(AdapterType $adapterType): void
    {
        $this->adapterType = $adapterType->value;
    }

    public function setEndpointUrl(string $endpointUrl): void
    {
        $this->endpointUrl = $endpointUrl;
    }

    /**
     * Set the API key vault identifier.
     *
     * The actual secret storage is handled by nr-vault's TCA form element.
     * This method stores the vault identifier that references the encrypted secret.
     */
    public function setApiKey(string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function setOrganizationId(string $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function setApiTimeout(int $apiTimeout): void
    {
        $this->apiTimeout = max(1, $apiTimeout);
    }

    /**
     * Alias for setApiTimeout.
     */
    public function setTimeout(int $timeout): void
    {
        $this->setApiTimeout($timeout);
    }

    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = max(0, $maxRetries);
    }

    public function setOptions(string $options): void
    {
        $this->options = $options;
    }

    /**
     * Set options from array.
     *
     * @param array<string, mixed> $options
     */
    public function setOptionsArray(array $options): void
    {
        $this->options = json_encode($options, JSON_THROW_ON_ERROR);
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function setPriority(int $priority): void
    {
        $this->priority = max(0, min(100, $priority));
    }

    public function setSorting(int $sorting): void
    {
        $this->sorting = $sorting;
    }

    /**
     * @param ObjectStorage<Model>|null $models
     */
    public function setModels(?ObjectStorage $models): void
    {
        $this->models = $models;
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Get the default endpoint URL for the adapter type.
     */
    public static function getDefaultEndpointForAdapter(string|AdapterType $adapterType): string
    {
        $type = $adapterType instanceof AdapterType
            ? $adapterType
            : AdapterType::tryFrom($adapterType);

        return $type?->defaultEndpoint() ?? '';
    }

    /**
     * Get the effective endpoint URL (custom or default).
     */
    public function getEffectiveEndpointUrl(): string
    {
        if ($this->endpointUrl !== '') {
            return $this->endpointUrl;
        }
        return self::getDefaultEndpointForAdapter($this->adapterType);
    }

    /**
     * Check if this provider has custom endpoint.
     */
    public function hasCustomEndpoint(): bool
    {
        return $this->endpointUrl !== '';
    }

    /**
     * Check if API key is configured.
     *
     * Checks if there is a non-empty API key (encrypted or plaintext).
     */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== '' && $this->getDecryptedApiKey() !== '';
    }

    /**
     * Get all available adapter types.
     *
     * @return array<string, string>
     */
    public static function getAdapterTypes(): array
    {
        return AdapterType::toSelectArray();
    }

    /**
     * Get human-readable adapter name.
     */
    public function getAdapterName(): string
    {
        return $this->getAdapterTypeEnum()?->label() ?? $this->adapterType;
    }

    /**
     * Convert to configuration array for adapter initialization.
     *
     * Uses the decrypted API key for actual API calls.
     *
     * @return array<string, mixed>
     */
    public function toAdapterConfig(): array
    {
        $config = [
            'api_key' => $this->getDecryptedApiKey(),
            'endpoint' => $this->getEffectiveEndpointUrl(),
            'api_timeout' => $this->apiTimeout,
            'max_retries' => $this->maxRetries,
        ];

        if ($this->organizationId !== '') {
            $config['organization_id'] = $this->organizationId;
        }

        // Merge additional options
        $additionalOptions = $this->getOptionsArray();
        if (!empty($additionalOptions)) {
            $config = array_merge($config, $additionalOptions);
        }

        return $config;
    }
}
