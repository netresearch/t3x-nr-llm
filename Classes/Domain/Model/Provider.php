<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\DTO\ProviderOptions;
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
     *
     * If the stored value is not a valid vault identifier (legacy plaintext data),
     * a security warning is logged and an empty string is returned to prevent
     * accidental use of unencrypted secrets.
     */
    public function getDecryptedApiKey(): string
    {
        if ($this->apiKey === '') {
            return '';
        }

        // Detect legacy plaintext API keys that were stored before vault integration
        if (!self::isVaultIdentifier($this->apiKey)) {
            trigger_error(
                \sprintf(
                    'Provider %d has a plaintext API key instead of a vault identifier. '
                    . 'Re-save the provider record to migrate it to the vault.',
                    $this->uid,
                ),
                E_USER_WARNING,
            );

            return '';
        }

        try {
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

    /**
     * @deprecated since 0.8.0 — application code should use the typed
     *             `getOptionsObject(): ProviderOptions` (REC #6 slice 20).
     *             The raw-JSON accessor is retained for Extbase property
     *             mapping (the framework hydrates the entity through this
     *             getter / setter pair) and will not be removed before a
     *             major version bump.
     */
    public function getOptions(): string
    {
        return $this->options;
    }

    /**
     * Get parsed options array.
     *
     * @return array<string, mixed>
     *
     * @deprecated since 0.8.0 — use `getOptionsObject()` for the typed
     *             `ProviderOptions` value object (REC #6 slice 20). The
     *             array accessor is retained for back-compat with
     *             pre-DTO callers (`ProviderAdapterRegistry` merges it
     *             into the adapter-init config) and will not be removed
     *             before a major version bump; new code should consume
     *             the DTO directly.
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

    /**
     * Get options as a typed `ProviderOptions` value object (REC #6 slice 20).
     *
     * Preferred accessor for new callers. The DTO is built fresh from
     * the persisted JSON on each call (cheap — single `json_decode`
     * plus a few key extractions); it never throws on malformed input
     * (returns an empty object instead) so consumers never have to
     * defensive-decode.
     *
     * The legacy string / array accessors do NOT route through this
     * method — they preserve their pre-REC-#6 behaviour byte-for-byte.
     * A future slice will migrate `ProviderAdapterRegistry` and other
     * call sites onto this typed surface.
     */
    public function getOptionsObject(): ProviderOptions
    {
        return ProviderOptions::fromJson($this->options);
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
     * The actual secret storage is handled by nr-vault's TCA form element
     * or by the SetupWizardController via VaultServiceInterface::store().
     * This method only accepts vault identifiers (UUIDs), not raw secrets.
     *
     * @throws InvalidArgumentException If the value looks like a raw API key instead of a vault UUID
     */
    public function setApiKey(string $apiKey): void
    {
        // Allow empty string (no key / clearing)
        // Reject values that look like raw API keys (not vault UUIDs)
        if ($apiKey !== '' && !self::isVaultIdentifier($apiKey)) {
            throw new InvalidArgumentException(
                'API key must be a vault identifier (UUID), not a raw secret. '
                . 'Use VaultServiceInterface::store() first.',
                1741268400,
            );
        }

        $this->apiKey = $apiKey;
    }

    /**
     * Check whether a value looks like a vault identifier (UUID v7).
     */
    private static function isVaultIdentifier(string $value): bool
    {
        // UUID v7 format: 8-4-4-4-12 hex digits with version 7
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        );
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

    /**
     * @deprecated since 0.8.0 — application code should use the typed
     *             `setOptionsObject(ProviderOptions)` so the persisted
     *             JSON is produced by the DTO's own serialiser rather
     *             than passed in as an arbitrary string. The raw-JSON
     *             setter is retained for Extbase property mapping and
     *             will not be removed before a major version bump.
     */
    public function setOptions(string $options): void
    {
        $this->options = $options;
    }

    /**
     * Set options from array.
     *
     * @param array<string, mixed> $options
     *
     * @deprecated since 0.8.0 — use `setOptionsObject(ProviderOptions)`
     *             for typed validation of well-known fields (`proxy`,
     *             `customHeaders`) and to centralise the encoding rule.
     *             The array setter is retained for back-compat and
     *             will not be removed before a major version bump.
     */
    public function setOptionsArray(array $options): void
    {
        $this->options = json_encode($options, JSON_THROW_ON_ERROR);
    }

    /**
     * Set options from a typed `ProviderOptions` value object (REC #6 slice 20).
     *
     * Preferred setter — invariants on the DTO (`proxy` is `?string`,
     * `customHeaders` is `array<string, string>`) flow through to the
     * persisted JSON. An empty DTO collapses to the empty-string
     * sentinel `''` (matching how `setOptions('')` historically
     * cleared the field) rather than persisting `'[]'`, so the
     * round-trip through Extbase does not produce noisier JSON than
     * the entity actually needs.
     */
    public function setOptionsObject(ProviderOptions $options): void
    {
        $this->options = $options->isEmpty() ? '' : $options->toJson();
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
     * Note: getHasApiKey() alias is needed because Fluid's {provider.hasApiKey}
     * resolves to getHasApiKey(), not hasApiKey().
     */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== '' && $this->getDecryptedApiKey() !== '';
    }

    /**
     * Fluid-compatible alias for hasApiKey().
     */
    public function getHasApiKey(): bool
    {
        return $this->hasApiKey();
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
