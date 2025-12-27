<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use Netresearch\NrLlm\Service\Crypto\ProviderEncryptionService;
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
    /** Supported adapter types. */
    public const ADAPTER_OPENAI = 'openai';
    public const ADAPTER_ANTHROPIC = 'anthropic';
    public const ADAPTER_GEMINI = 'gemini';
    public const ADAPTER_OPENROUTER = 'openrouter';
    public const ADAPTER_MISTRAL = 'mistral';
    public const ADAPTER_GROQ = 'groq';
    public const ADAPTER_OLLAMA = 'ollama';
    public const ADAPTER_AZURE_OPENAI = 'azure_openai';
    public const ADAPTER_CUSTOM = 'custom';

    protected string $identifier = '';
    protected string $name = '';
    protected string $description = '';
    protected string $adapterType = '';
    protected string $endpointUrl = '';
    protected string $apiKey = '';
    protected string $organizationId = '';
    protected int $timeout = 30;
    protected int $maxRetries = 3;
    protected string $options = '';
    protected bool $isActive = true;
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
     * This method decrypts the stored API key using the ProviderEncryptionService.
     * The value is decrypted on-demand and not cached to minimize exposure.
     */
    public function getDecryptedApiKey(): string
    {
        if ($this->apiKey === '') {
            return '';
        }

        try {
            $encryptionService = GeneralUtility::makeInstance(ProviderEncryptionService::class);
            return $encryptionService->decrypt($this->apiKey);
        } catch (Throwable) {
            // If decryption fails, return the raw value (backwards compatibility for unencrypted keys)
            return $this->apiKey;
        }
    }

    public function getOrganizationId(): string
    {
        return $this->organizationId;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
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

    public function setEndpointUrl(string $endpointUrl): void
    {
        $this->endpointUrl = $endpointUrl;
    }

    /**
     * Set the API key (will be encrypted before storage).
     *
     * If the value is already encrypted (starts with 'enc:'), it's stored as-is.
     * Otherwise, it's encrypted using the ProviderEncryptionService.
     */
    public function setApiKey(string $apiKey): void
    {
        if ($apiKey === '') {
            $this->apiKey = '';
            return;
        }

        try {
            $encryptionService = GeneralUtility::makeInstance(ProviderEncryptionService::class);

            // Only encrypt if not already encrypted
            if (!$encryptionService->isEncrypted($apiKey)) {
                $this->apiKey = $encryptionService->encrypt($apiKey);
            } else {
                $this->apiKey = $apiKey;
            }
        } catch (Throwable) {
            // If encryption fails, store as plaintext (fallback)
            $this->apiKey = $apiKey;
        }
    }

    public function setOrganizationId(string $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = max(1, $timeout);
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
    public static function getDefaultEndpointForAdapter(string $adapterType): string
    {
        return match ($adapterType) {
            self::ADAPTER_OPENAI => 'https://api.openai.com/v1',
            self::ADAPTER_ANTHROPIC => 'https://api.anthropic.com/v1',
            self::ADAPTER_GEMINI => 'https://generativelanguage.googleapis.com/v1beta',
            self::ADAPTER_OPENROUTER => 'https://openrouter.ai/api/v1',
            self::ADAPTER_MISTRAL => 'https://api.mistral.ai/v1',
            self::ADAPTER_GROQ => 'https://api.groq.com/openai/v1',
            self::ADAPTER_OLLAMA => 'http://localhost:11434/api',
            default => '',
        };
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
        return [
            self::ADAPTER_OPENAI => 'OpenAI',
            self::ADAPTER_ANTHROPIC => 'Anthropic (Claude)',
            self::ADAPTER_GEMINI => 'Google Gemini',
            self::ADAPTER_OPENROUTER => 'OpenRouter',
            self::ADAPTER_MISTRAL => 'Mistral AI',
            self::ADAPTER_GROQ => 'Groq',
            self::ADAPTER_OLLAMA => 'Ollama (Local)',
            self::ADAPTER_AZURE_OPENAI => 'Azure OpenAI',
            self::ADAPTER_CUSTOM => 'Custom (OpenAI-compatible)',
        ];
    }

    /**
     * Get human-readable adapter name.
     */
    public function getAdapterName(): string
    {
        $types = self::getAdapterTypes();
        return $types[$this->adapterType] ?? $this->adapterType;
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
            'timeout' => $this->timeout,
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
