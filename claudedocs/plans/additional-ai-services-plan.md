# Implementation Plan: Additional AI Services

**Project**: `/home/sme/p/t3x-nr-llm/main`
**Date**: 2025-12-23 (Updated after ZEN Review)
**Status**: Planning - Ready for Implementation
**PHP**: 8.5 | **TYPO3**: 14.0

---

## Executive Summary

Extend nr_llm with additional AI providers and specialized services while maintaining the existing LLM-based approach as a primary option.

| Phase | Scope | Priority | Effort |
|-------|-------|----------|--------|
| **Phase 0** | Foundation (exceptions, options, migrations) | Critical | 2 days |
| **Phase 1** | Mistral + Groq LLM providers | High | 1-2 days |
| **Phase 2A** | Translation registry architecture | High | 2 days |
| **Phase 2B** | DeepL integration | High | 1-2 days |
| **Phase 3** | Speech services (Whisper, TTS) | Medium | 2-3 days |
| **Phase 4** | Image generation (DALL-E + FAL) | Medium | 2-3 days |
| **Total** | | | **10-14 days** |

---

## Phase 0: Foundation (2 days) - CRITICAL

> **Must complete before any other phase**

### 0.1 Exception Hierarchy

Create consistent exception hierarchy for specialized services:

```
Classes/Specialized/Exception/
├── SpecializedServiceException.php    # Abstract base
├── TranslatorException.php            # Translation errors
├── SpeechServiceException.php         # Speech errors
├── ImageGenerationException.php       # Image errors
├── UnsupportedLanguageException.php   # Invalid language codes
├── UnsupportedFormatException.php     # Invalid file formats
├── ServiceQuotaExceededException.php  # Rate limit / quota
└── ServiceUnavailableException.php    # External service down
```

**Implementation:**

```php
<?php
// Classes/Specialized/Exception/SpecializedServiceException.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

abstract class SpecializedServiceException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $service,
        public readonly ?array $context = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
```

### 0.2 Option Objects for Specialized Services

Following the existing pattern from `Classes/Service/Option/`:

| File | Purpose |
|------|---------|
| `Classes/Specialized/Option/TranscriptionOptions.php` | Whisper options |
| `Classes/Specialized/Option/SpeechSynthesisOptions.php` | TTS options |
| `Classes/Specialized/Option/ImageGenerationOptions.php` | DALL-E options |
| `Classes/Specialized/Option/DeepLOptions.php` | DeepL-specific options |

**Example (TranscriptionOptions):**

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use Netresearch\NrLlm\Service\Option\AbstractOptions;

final class TranscriptionOptions extends AbstractOptions
{
    public function __construct(
        public readonly ?string $model = 'whisper-1',
        public readonly ?string $language = null,
        public readonly ?string $format = 'json',
        public readonly ?string $prompt = null,
        public readonly ?float $temperature = null,
    ) {
        if ($this->format !== null) {
            self::validateEnum($this->format, ['json', 'text', 'srt', 'vtt', 'verbose_json'], 'format');
        }
        if ($this->temperature !== null) {
            self::validateRange($this->temperature, 0.0, 1.0, 'temperature');
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'model' => $this->model,
            'language' => $this->language,
            'response_format' => $this->format,
            'prompt' => $this->prompt,
            'temperature' => $this->temperature,
        ], fn($v) => $v !== null);
    }

    public static function fromArray(array $options): static
    {
        return new self(
            model: $options['model'] ?? null,
            language: $options['language'] ?? null,
            format: $options['format'] ?? $options['response_format'] ?? null,
            prompt: $options['prompt'] ?? null,
            temperature: $options['temperature'] ?? null,
        );
    }
}
```

### 0.3 LlmConfiguration Entity Update

Add `translator` field to support translation presets:

**Database Migration (ext_tables.sql addition):**

```sql
-- Add translator field to tx_nrllm_configuration
-- Migration: ALTER TABLE tx_nrllm_configuration ADD translator varchar(50) DEFAULT '' NOT NULL AFTER model;
```

**Domain Model Update:**

```php
// Classes/Domain/Model/LlmConfiguration.php - additions
protected string $translator = '';

public function getTranslator(): string
{
    return $this->translator;
}

public function setTranslator(string $translator): void
{
    $this->translator = $translator;
}

public function toOptionsArray(): array
{
    $options = [/* existing */];
    if ($this->translator !== '') {
        $options['translator'] = $this->translator;
    }
    return $options;
}
```

**TCA Update:**

```php
// Configuration/TCA/tx_nrllm_configuration.php - add to columns
'translator' => [
    'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_configuration.translator',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'items' => [
            ['label' => 'None (use LLM)', 'value' => ''],
            ['label' => 'DeepL', 'value' => 'deepl'],
        ],
        'default' => '',
    ],
],
```

### 0.4 TranslatorResult DTO

> **CRITICAL**: Avoid conflict with existing `Domain\Model\TranslationResult`

```php
<?php
// Classes/Specialized/Translation/TranslatorResult.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;

/**
 * Result from specialized translators (DeepL, Google, etc.)
 *
 * Note: This is separate from Domain\Model\TranslationResult which is
 * used by LLM-based translation and includes UsageStatistics.
 */
final readonly class TranslatorResult
{
    public function __construct(
        public string $translatedText,
        public string $sourceLanguage,
        public string $targetLanguage,
        public string $translator,
        public ?float $confidence = null,
        public ?array $alternatives = null,
        public ?int $charactersUsed = null,
        public ?array $metadata = null,
    ) {}

    public function getText(): string
    {
        return $this->translatedText;
    }

    public function isFromLlm(): bool
    {
        return str_starts_with($this->translator, 'llm:');
    }

    public function isFromDeepL(): bool
    {
        return $this->translator === 'deepl';
    }
}
```

### 0.5 Database Schema for Service Usage Tracking

```sql
-- ext_tables.sql addition

#
# Table for tracking specialized service usage (translation, speech, image)
#
CREATE TABLE tx_nrllm_service_usage (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    -- Service identification
    service_type varchar(50) DEFAULT '' NOT NULL,
    service_provider varchar(50) DEFAULT '' NOT NULL,
    configuration_uid int(11) unsigned DEFAULT '0' NOT NULL,

    -- User context
    be_user int(11) unsigned DEFAULT '0' NOT NULL,

    -- Usage metrics
    request_count int(11) unsigned DEFAULT '0' NOT NULL,
    tokens_used int(11) unsigned DEFAULT '0' NOT NULL,
    characters_used int(11) unsigned DEFAULT '0' NOT NULL,
    audio_seconds_used int(11) unsigned DEFAULT '0' NOT NULL,
    images_generated int(11) unsigned DEFAULT '0' NOT NULL,

    -- Cost tracking
    estimated_cost decimal(10,6) DEFAULT '0.000000' NOT NULL,

    -- Time tracking
    request_date int(11) unsigned DEFAULT '0' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY lookup (service_type, service_provider, request_date),
    KEY user_lookup (be_user, service_type, request_date),
    KEY config_lookup (configuration_uid, request_date)
);
```

**Usage Tracker Service:**

```php
<?php
// Classes/Service/UsageTrackerService.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

final class UsageTrackerService implements SingletonInterface
{
    private const TABLE = 'tx_nrllm_service_usage';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function trackUsage(
        string $serviceType,
        string $provider,
        array $metrics = [],
        ?int $configurationUid = null,
    ): void {
        $beUser = $GLOBALS['BE_USER']->user['uid'] ?? 0;
        $today = strtotime('today');

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        // Try to update existing record for today
        $affected = $connection->update(
            self::TABLE,
            [
                'request_count' => new \Doctrine\DBAL\Query\Expression\Expression(
                    'request_count + 1'
                ),
                'tokens_used' => new \Doctrine\DBAL\Query\Expression\Expression(
                    sprintf('tokens_used + %d', $metrics['tokens'] ?? 0)
                ),
                'characters_used' => new \Doctrine\DBAL\Query\Expression\Expression(
                    sprintf('characters_used + %d', $metrics['characters'] ?? 0)
                ),
                'estimated_cost' => new \Doctrine\DBAL\Query\Expression\Expression(
                    sprintf('estimated_cost + %f', $metrics['cost'] ?? 0.0)
                ),
                'tstamp' => time(),
            ],
            [
                'service_type' => $serviceType,
                'service_provider' => $provider,
                'be_user' => $beUser,
                'request_date' => $today,
            ]
        );

        // Insert new record if none existed
        if ($affected === 0) {
            $connection->insert(self::TABLE, [
                'pid' => 0,
                'service_type' => $serviceType,
                'service_provider' => $provider,
                'configuration_uid' => $configurationUid ?? 0,
                'be_user' => $beUser,
                'request_count' => 1,
                'tokens_used' => $metrics['tokens'] ?? 0,
                'characters_used' => $metrics['characters'] ?? 0,
                'audio_seconds_used' => $metrics['audioSeconds'] ?? 0,
                'images_generated' => $metrics['images'] ?? 0,
                'estimated_cost' => $metrics['cost'] ?? 0.0,
                'request_date' => $today,
                'tstamp' => time(),
                'crdate' => time(),
            ]);
        }
    }

    public function getUsageReport(
        string $serviceType,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('service_provider')
            ->addSelectLiteral('SUM(request_count) as total_requests')
            ->addSelectLiteral('SUM(tokens_used) as total_tokens')
            ->addSelectLiteral('SUM(characters_used) as total_characters')
            ->addSelectLiteral('SUM(estimated_cost) as total_cost')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('service_type', $queryBuilder->createNamedParameter($serviceType)),
                $queryBuilder->expr()->gte('request_date', $from->getTimestamp()),
                $queryBuilder->expr()->lte('request_date', $to->getTimestamp())
            )
            ->groupBy('service_provider')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
```

### 0.6 Services.yaml Registration Pattern

```yaml
# Configuration/Services.yaml additions

  # ========================================
  # Specialized Service Infrastructure
  # ========================================

  Netresearch\NrLlm\Service\UsageTrackerService:
    public: true

  # ========================================
  # Translation Registry
  # ========================================

  Netresearch\NrLlm\Specialized\Translation\TranslatorRegistry:
    public: true

  Netresearch\NrLlm\Specialized\Translation\LlmTranslator:
    public: true
    tags:
      - name: nr_llm.translator
        identifier: llm
        priority: 100

  Netresearch\NrLlm\Specialized\Translation\DeepLTranslator:
    public: true
    tags:
      - name: nr_llm.translator
        identifier: deepl
        priority: 90

  # ========================================
  # Speech Services
  # ========================================

  Netresearch\NrLlm\Specialized\Speech\WhisperService:
    public: true

  Netresearch\NrLlm\Specialized\Speech\OpenAiTtsService:
    public: true

  # ========================================
  # Image Generation Services
  # ========================================

  Netresearch\NrLlm\Specialized\ImageGeneration\DallEService:
    public: true
```

---

## Phase 1: Additional LLM Providers (1-2 days)

### 1.1 MistralProvider

**Why Mistral:**
- EU-based company (GDPR compliance)
- Competitive performance/cost ratio
- OpenAI-compatible API format
- Models: mistral-tiny, mistral-small, mistral-medium, mistral-large

```php
<?php
// Classes/Provider/MistralProvider.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Provider\Capability\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Capability\ToolCapableInterface;

final class MistralProvider extends AbstractProvider implements
    StreamingCapableInterface,
    ToolCapableInterface
{
    private const API_BASE = 'https://api.mistral.ai/v1';

    private const MODELS = [
        'mistral-tiny' => 'Mistral Tiny (7B)',
        'mistral-small' => 'Mistral Small',
        'mistral-medium' => 'Mistral Medium',
        'mistral-large-latest' => 'Mistral Large',
        'codestral-latest' => 'Codestral (Code)',
    ];

    public function getIdentifier(): string
    {
        return 'mistral';
    }

    public function getName(): string
    {
        return 'Mistral AI';
    }

    // Implementation follows OpenAI pattern...
}
```

**Configuration:**

```
# cat=mistral; type=string; label=Mistral API Key
providers.mistral.apiKey =

# cat=mistral; type=string; label=Default Model
providers.mistral.defaultModel = mistral-large-latest

# cat=mistral; type=int+; label=Timeout (seconds)
providers.mistral.timeout = 60
```

### 1.2 GroqProvider

**Why Groq:**
- Extremely fast inference (LPU architecture)
- OpenAI-compatible API
- Cost-effective for high-volume use
- Models: llama-3.3-70b, mixtral-8x7b, gemma2-9b

```php
<?php
// Classes/Provider/GroqProvider.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

final class GroqProvider extends AbstractProvider implements
    StreamingCapableInterface,
    ToolCapableInterface
{
    private const API_BASE = 'https://api.groq.com/openai/v1';

    private const MODELS = [
        'llama-3.3-70b-versatile' => 'Llama 3.3 70B',
        'llama-3.1-8b-instant' => 'Llama 3.1 8B (Fast)',
        'mixtral-8x7b-32768' => 'Mixtral 8x7B',
        'gemma2-9b-it' => 'Gemma 2 9B',
    ];

    public function getIdentifier(): string
    {
        return 'groq';
    }

    public function getName(): string
    {
        return 'Groq';
    }

    // Implementation follows OpenAI pattern...
}
```

**Configuration:**

```
# cat=groq; type=string; label=Groq API Key
providers.groq.apiKey =

# cat=groq; type=string; label=Default Model
providers.groq.defaultModel = llama-3.3-70b-versatile

# cat=groq; type=int+; label=Timeout (seconds)
providers.groq.timeout = 30
```

---

## Phase 2A: Translation Registry Architecture (2 days)

### 2.1 TranslatorInterface

```php
<?php
// Classes/Specialized/Translation/TranslatorInterface.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

interface TranslatorInterface
{
    public function getIdentifier(): string;

    public function getName(): string;

    public function isAvailable(): bool;

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslatorResult;

    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): array;

    public function getSupportedLanguages(): array;

    public function detectLanguage(string $text): string;
}
```

### 2.2 TranslatorRegistry

```php
<?php
// Classes/Specialized/Translation/TranslatorRegistry.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use TYPO3\CMS\Core\SingletonInterface;

final class TranslatorRegistry implements SingletonInterface
{
    /** @var array<string, TranslatorInterface> */
    private array $translators = [];

    public function __construct(
        #[TaggedIterator('nr_llm.translator')]
        iterable $translators
    ) {
        foreach ($translators as $translator) {
            $this->translators[$translator->getIdentifier()] = $translator;
        }
    }

    public function get(string $identifier): TranslatorInterface
    {
        if (!isset($this->translators[$identifier])) {
            throw new ServiceUnavailableException(
                sprintf('Translator "%s" not found', $identifier),
                'translation'
            );
        }

        $translator = $this->translators[$identifier];

        if (!$translator->isAvailable()) {
            throw new ServiceUnavailableException(
                sprintf('Translator "%s" is not available (not configured)', $identifier),
                'translation'
            );
        }

        return $translator;
    }

    public function has(string $identifier): bool
    {
        return isset($this->translators[$identifier])
            && $this->translators[$identifier]->isAvailable();
    }

    /** @return array<string, TranslatorInterface> */
    public function getAvailable(): array
    {
        return array_filter(
            $this->translators,
            fn(TranslatorInterface $t) => $t->isAvailable()
        );
    }
}
```

### 2.3 LlmTranslator (Wrapper)

Wraps existing LLM-based translation:

```php
<?php
// Classes/Specialized/Translation/LlmTranslator.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Service\Feature\TranslationService;

final class LlmTranslator implements TranslatorInterface
{
    public function __construct(
        private readonly TranslationService $translationService,
    ) {}

    public function getIdentifier(): string
    {
        return 'llm';
    }

    public function getName(): string
    {
        return 'LLM-based Translation';
    }

    public function isAvailable(): bool
    {
        return true; // Always available if any LLM provider is configured
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslatorResult {
        // Extract provider from options
        $provider = $options['provider'] ?? null;

        $result = $this->translationService->translate(
            $text,
            $targetLanguage,
            $sourceLanguage,
            $options
        );

        return new TranslatorResult(
            translatedText: $result->translation,
            sourceLanguage: $result->sourceLanguage,
            targetLanguage: $result->targetLanguage,
            translator: 'llm:' . ($provider ?? 'default'),
            confidence: $result->confidence,
            alternatives: $result->alternatives,
            metadata: ['usage' => $result->usage],
        );
    }

    // ... other methods
}
```

### 2.4 Updated TranslationService (Dual-Path)

```php
<?php
// Classes/Service/Feature/TranslationService.php - updated
declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Feature;

use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistry;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Service\LlmConfigurationService;

class TranslationService
{
    public function __construct(
        private readonly LlmServiceManager $llmManager,
        private readonly TranslatorRegistry $translatorRegistry,
        private readonly LlmConfigurationService $configService,
    ) {}

    /**
     * Translate using specified method
     *
     * @param array{
     *     provider?: string,      // LLM provider (openai, claude, etc.)
     *     translator?: string,    // Specialized translator (deepl, llm)
     *     preset?: string,        // Named preset from LlmConfiguration
     *     glossary?: array,       // Term translations
     *     formality?: string,     // Formality level
     *     context?: string,       // Context for LLM translation
     * } $options
     */
    public function translateWithTranslator(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslatorResult {
        // Priority 1: Explicit translator specified
        if (isset($options['translator'])) {
            return $this->translatorRegistry
                ->get($options['translator'])
                ->translate($text, $targetLanguage, $sourceLanguage, $options);
        }

        // Priority 2: Preset specified
        if (isset($options['preset'])) {
            try {
                $config = $this->configService->getConfiguration($options['preset']);

                // If preset specifies a translator, use it
                if ($config->getTranslator() !== '') {
                    return $this->translatorRegistry
                        ->get($config->getTranslator())
                        ->translate(
                            $text,
                            $targetLanguage,
                            $sourceLanguage,
                            array_merge($config->toOptionsArray(), $options)
                        );
                }

                // Otherwise use LLM with preset's provider/model
                $options = array_merge($config->toOptionsArray(), $options);
            } catch (\Throwable) {
                // Preset not found, continue with defaults
            }
        }

        // Default: LLM-based translation
        return $this->translatorRegistry
            ->get('llm')
            ->translate($text, $targetLanguage, $sourceLanguage, $options);
    }

    // Original translate() method remains unchanged for backward compatibility
}
```

---

## Phase 2B: DeepL Integration (1-2 days)

### 2.5 DeepLTranslator

```php
<?php
// Classes/Specialized/Translation/DeepLTranslator.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Specialized\Exception\TranslatorException;
use Netresearch\NrLlm\Specialized\Exception\UnsupportedLanguageException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class DeepLTranslator implements TranslatorInterface
{
    private const API_BASE_FREE = 'https://api-free.deepl.com/v2';
    private const API_BASE_PRO = 'https://api.deepl.com/v2';

    private const SUPPORTED_LANGUAGES = [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr',
        'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'nb', 'nl', 'pl',
        'pt', 'ro', 'ru', 'sk', 'sl', 'sv', 'tr', 'uk', 'zh',
    ];

    private ?array $config = null;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly UsageTrackerService $usageTracker,
    ) {}

    public function getIdentifier(): string
    {
        return 'deepl';
    }

    public function getName(): string
    {
        return 'DeepL';
    }

    public function isAvailable(): bool
    {
        return $this->getApiKey() !== '';
    }

    public function translate(
        string $text,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): TranslatorResult {
        $this->validateLanguage($targetLanguage, 'target');
        if ($sourceLanguage !== null) {
            $this->validateLanguage($sourceLanguage, 'source');
        }

        $payload = [
            'text' => [$text],
            'target_lang' => strtoupper($targetLanguage),
        ];

        if ($sourceLanguage !== null) {
            $payload['source_lang'] = strtoupper($sourceLanguage);
        }

        // Optional parameters
        if (isset($options['formality']) && $options['formality'] !== 'default') {
            $payload['formality'] = $options['formality'];
        }

        if (isset($options['glossary'])) {
            $payload['glossary_id'] = $options['glossary'];
        }

        if (isset($options['preserveFormatting'])) {
            $payload['preserve_formatting'] = $options['preserveFormatting'];
        }

        $response = $this->sendRequest('/translate', $payload);

        $translation = $response['translations'][0];
        $detectedSource = strtolower($translation['detected_source_language'] ?? $sourceLanguage ?? 'unknown');

        // Track usage
        $this->usageTracker->trackUsage('translation', 'deepl', [
            'characters' => mb_strlen($text),
            'cost' => $this->estimateCost(mb_strlen($text)),
        ]);

        return new TranslatorResult(
            translatedText: $translation['text'],
            sourceLanguage: $detectedSource,
            targetLanguage: $targetLanguage,
            translator: 'deepl',
            confidence: 0.95, // DeepL typically high quality
            charactersUsed: mb_strlen($text),
            metadata: [
                'detected_source_language' => $detectedSource,
            ],
        );
    }

    public function translateBatch(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage = null,
        array $options = []
    ): array {
        if (empty($texts)) {
            return [];
        }

        $payload = [
            'text' => $texts,
            'target_lang' => strtoupper($targetLanguage),
        ];

        if ($sourceLanguage !== null) {
            $payload['source_lang'] = strtoupper($sourceLanguage);
        }

        $response = $this->sendRequest('/translate', $payload);

        $results = [];
        foreach ($response['translations'] as $index => $translation) {
            $detectedSource = strtolower($translation['detected_source_language'] ?? $sourceLanguage ?? 'unknown');

            $results[] = new TranslatorResult(
                translatedText: $translation['text'],
                sourceLanguage: $detectedSource,
                targetLanguage: $targetLanguage,
                translator: 'deepl',
                confidence: 0.95,
                charactersUsed: mb_strlen($texts[$index]),
            );
        }

        // Track batch usage
        $totalChars = array_sum(array_map('mb_strlen', $texts));
        $this->usageTracker->trackUsage('translation', 'deepl', [
            'characters' => $totalChars,
            'cost' => $this->estimateCost($totalChars),
        ]);

        return $results;
    }

    public function getSupportedLanguages(): array
    {
        return self::SUPPORTED_LANGUAGES;
    }

    public function detectLanguage(string $text): string
    {
        // DeepL doesn't have a separate detection endpoint
        // Use translate with a simple target and extract detected language
        $result = $this->translate(substr($text, 0, 100), 'en');
        return $result->sourceLanguage;
    }

    private function validateLanguage(string $code, string $type): void
    {
        $normalizedCode = strtolower(explode('-', $code)[0]);

        if (!in_array($normalizedCode, self::SUPPORTED_LANGUAGES, true)) {
            throw new UnsupportedLanguageException(
                sprintf('Language "%s" is not supported by DeepL for %s', $code, $type),
                'translation',
                ['language' => $code, 'type' => $type]
            );
        }
    }

    private function sendRequest(string $endpoint, array $payload): array
    {
        $apiBase = $this->getConfig()['apiType'] === 'pro'
            ? self::API_BASE_PRO
            : self::API_BASE_FREE;

        $request = $this->requestFactory
            ->createRequest('POST', $apiBase . $endpoint)
            ->withHeader('Authorization', 'DeepL-Auth-Key ' . $this->getApiKey())
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($payload)));

        $response = $this->client->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $body = $response->getBody()->getContents();
            throw new TranslatorException(
                sprintf('DeepL API error: %d - %s', $statusCode, $body),
                'translation',
                ['status' => $statusCode, 'response' => $body]
            );
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    private function getApiKey(): string
    {
        return $this->getConfig()['apiKey'] ?? '';
    }

    private function getConfig(): array
    {
        if ($this->config === null) {
            try {
                $config = $this->extensionConfiguration->get('nr_llm');
                $this->config = $config['translation']['deepl'] ?? [];
            } catch (\Throwable) {
                $this->config = [];
            }
        }
        return $this->config;
    }

    private function estimateCost(int $characters): float
    {
        // DeepL pricing: ~$20 per 1M characters (Pro)
        return ($characters / 1_000_000) * 20.0;
    }
}
```

**Configuration:**

```
# cat=translation/deepl; type=string; label=DeepL API Key
translation.deepl.apiKey =

# cat=translation/deepl; type=options[free,pro]; label=DeepL API Type
translation.deepl.apiType = free

# cat=translation/deepl; type=string; label=Default Formality (default, more, less, prefer_more, prefer_less)
translation.deepl.defaultFormality = default
```

---

## Phase 3: Speech Services (2-3 days)

### 3.1 WhisperService

```php
<?php
// Classes/Specialized/Speech/WhisperService.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Speech;

use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Specialized\Exception\SpeechServiceException;
use Netresearch\NrLlm\Specialized\Exception\UnsupportedFormatException;
use Netresearch\NrLlm\Specialized\Option\TranscriptionOptions;

final class WhisperService implements SpeechToTextInterface
{
    private const API_URL = 'https://api.openai.com/v1/audio/transcriptions';
    private const SUPPORTED_FORMATS = ['mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'wav', 'webm'];
    private const MAX_FILE_SIZE = 25 * 1024 * 1024; // 25MB

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly UsageTrackerService $usageTracker,
    ) {}

    public function transcribe(
        string $audioFilePath,
        TranscriptionOptions|array $options = []
    ): TranscriptionResult {
        $options = $options instanceof TranscriptionOptions
            ? $options
            : TranscriptionOptions::fromArray($options);

        $this->validateFile($audioFilePath);

        // Build multipart form data
        $multipart = $this->buildMultipartData($audioFilePath, $options);

        $request = $this->requestFactory
            ->createRequest('POST', self::API_URL)
            ->withHeader('Authorization', 'Bearer ' . $this->getApiKey())
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $multipart['boundary'])
            ->withBody($multipart['body']);

        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new SpeechServiceException(
                'Whisper API error: ' . $response->getBody()->getContents(),
                'speech'
            );
        }

        $data = json_decode($response->getBody()->getContents(), true);

        // Estimate audio duration for cost tracking
        $audioDuration = $this->estimateAudioDuration($audioFilePath);

        $this->usageTracker->trackUsage('speech', 'whisper', [
            'audioSeconds' => $audioDuration,
            'cost' => $audioDuration * 0.0001, // $0.006/minute = $0.0001/second
        ]);

        return new TranscriptionResult(
            text: $data['text'],
            language: $data['language'] ?? $options->language ?? 'unknown',
            duration: $audioDuration,
            segments: $data['segments'] ?? null,
            metadata: [
                'model' => $options->model,
                'format' => $options->format,
            ],
        );
    }

    public function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }

    private function validateFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new SpeechServiceException(
                sprintf('Audio file not found: %s', $path),
                'speech'
            );
        }

        $size = filesize($path);
        if ($size > self::MAX_FILE_SIZE) {
            throw new SpeechServiceException(
                sprintf('Audio file exceeds 25MB limit: %d bytes', $size),
                'speech'
            );
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_FORMATS, true)) {
            throw new UnsupportedFormatException(
                sprintf('Unsupported audio format: %s', $extension),
                'speech',
                ['format' => $extension, 'supported' => self::SUPPORTED_FORMATS]
            );
        }
    }

    private function estimateAudioDuration(string $path): int
    {
        // Simple estimation based on file size and format
        // For accurate duration, use getID3 or similar library
        $size = filesize($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $bytesPerSecond = match ($extension) {
            'mp3' => 16000,  // ~128kbps
            'wav' => 176400, // 44.1kHz 16-bit stereo
            'webm' => 12000, // ~96kbps
            default => 16000,
        };

        return (int) ceil($size / $bytesPerSecond);
    }

    // ... multipart handling, API key retrieval, etc.
}
```

### 3.2 OpenAiTtsService

```php
<?php
// Classes/Specialized/Speech/OpenAiTtsService.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Speech;

use Netresearch\NrLlm\Specialized\Option\SpeechSynthesisOptions;

final class OpenAiTtsService implements TextToSpeechInterface
{
    private const API_URL = 'https://api.openai.com/v1/audio/speech';
    private const VOICES = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    private const MODELS = ['tts-1', 'tts-1-hd'];

    public function synthesize(
        string $text,
        SpeechSynthesisOptions|array $options = []
    ): SpeechResult {
        $options = $options instanceof SpeechSynthesisOptions
            ? $options
            : SpeechSynthesisOptions::fromArray($options);

        $payload = [
            'model' => $options->model ?? 'tts-1',
            'voice' => $options->voice ?? 'alloy',
            'input' => $text,
            'response_format' => $options->format ?? 'mp3',
            'speed' => $options->speed ?? 1.0,
        ];

        $request = $this->requestFactory
            ->createRequest('POST', self::API_URL)
            ->withHeader('Authorization', 'Bearer ' . $this->getApiKey())
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($payload)));

        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new SpeechServiceException(
                'TTS API error: ' . $response->getBody()->getContents(),
                'speech'
            );
        }

        $audioData = $response->getBody()->getContents();

        // Track usage
        $this->usageTracker->trackUsage('speech', 'openai-tts', [
            'characters' => mb_strlen($text),
            'cost' => $this->estimateCost(mb_strlen($text), $options->model ?? 'tts-1'),
        ]);

        return new SpeechResult(
            audioData: $audioData,
            format: $options->format ?? 'mp3',
            model: $options->model ?? 'tts-1',
            voice: $options->voice ?? 'alloy',
            charactersUsed: mb_strlen($text),
        );
    }

    public function getAvailableVoices(): array
    {
        return self::VOICES;
    }

    private function estimateCost(int $characters, string $model): float
    {
        // TTS pricing: $15/1M chars (tts-1), $30/1M chars (tts-1-hd)
        $rate = $model === 'tts-1-hd' ? 30.0 : 15.0;
        return ($characters / 1_000_000) * $rate;
    }
}
```

---

## Phase 4: Image Generation (2-3 days)

### 4.1 DallEService with FAL Integration

```php
<?php
// Classes/Specialized/ImageGeneration/DallEService.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\ImageGeneration;

use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\File;

final class DallEService implements ImageGenerationInterface
{
    private const API_URL = 'https://api.openai.com/v1/images/generations';
    private const MODELS = ['dall-e-2', 'dall-e-3'];
    private const SIZES_DALLE3 = ['1024x1024', '1792x1024', '1024x1792'];
    private const SIZES_DALLE2 = ['256x256', '512x512', '1024x1024'];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly UsageTrackerService $usageTracker,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    public function generate(
        string $prompt,
        ImageGenerationOptions|array $options = []
    ): GeneratedImage {
        $options = $options instanceof ImageGenerationOptions
            ? $options
            : ImageGenerationOptions::fromArray($options);

        $model = $options->model ?? 'dall-e-3';
        $size = $options->size ?? '1024x1024';

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'quality' => $options->quality ?? 'standard',
            'response_format' => $options->format ?? 'url',
        ];

        if ($model === 'dall-e-3') {
            $payload['style'] = $options->style ?? 'vivid';
        }

        $request = $this->requestFactory
            ->createRequest('POST', self::API_URL)
            ->withHeader('Authorization', 'Bearer ' . $this->getApiKey())
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($payload)));

        $response = $this->client->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new ImageGenerationException(
                'DALL-E API error: ' . $response->getBody()->getContents(),
                'image'
            );
        }

        $data = json_decode($response->getBody()->getContents(), true);
        $imageData = $data['data'][0];

        // Track usage
        $this->usageTracker->trackUsage('image', 'dall-e', [
            'images' => 1,
            'cost' => $this->estimateCost($model, $size, $options->quality ?? 'standard'),
        ]);

        return new GeneratedImage(
            url: $imageData['url'] ?? null,
            base64: $imageData['b64_json'] ?? null,
            model: $model,
            revisedPrompt: $imageData['revised_prompt'] ?? $prompt,
            size: $size,
            metadata: [
                'quality' => $options->quality ?? 'standard',
                'style' => $options->style ?? 'vivid',
            ],
        );
    }

    /**
     * Save generated image to TYPO3 FAL
     */
    public function saveToFal(
        GeneratedImage $image,
        int $storageUid = 0,
        string $targetFolder = 'llm_generated/',
        ?string $fileName = null,
        array $metadata = []
    ): File {
        $storage = $this->resourceFactory->getStorageObject($storageUid);

        // Ensure target folder exists
        if (!$storage->hasFolder($targetFolder)) {
            $storage->createFolder($targetFolder);
        }
        $folder = $storage->getFolder($targetFolder);

        // Download image content
        $imageContent = $image->base64 !== null
            ? base64_decode($image->base64)
            : file_get_contents($image->url);

        if ($imageContent === false) {
            throw new ImageGenerationException(
                'Failed to download generated image',
                'image'
            );
        }

        // Generate filename
        $fileName = $fileName ?? sprintf(
            'dalle_%s_%s.png',
            date('YmdHis'),
            substr(md5($image->revisedPrompt), 0, 8)
        );

        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'dalle_');
        file_put_contents($tempFile, $imageContent);

        try {
            // Add to storage
            $file = $storage->addFile($tempFile, $folder, $fileName);

            // Update metadata
            $file->updateProperties([
                'title' => substr($image->revisedPrompt, 0, 255),
                'alternative' => 'AI-generated: ' . substr($image->revisedPrompt, 0, 200),
                'description' => sprintf(
                    'Generated by %s (%s, %s quality)',
                    $image->model,
                    $image->size,
                    $image->metadata['quality'] ?? 'standard'
                ),
                ...$metadata,
            ]);

            return $file;
        } finally {
            // Clean up temp file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    private function estimateCost(string $model, string $size, string $quality): float
    {
        // DALL-E 3 pricing
        if ($model === 'dall-e-3') {
            return match (true) {
                $quality === 'hd' && $size === '1024x1024' => 0.080,
                $quality === 'hd' => 0.120,
                $size === '1024x1024' => 0.040,
                default => 0.080,
            };
        }

        // DALL-E 2 pricing
        return match ($size) {
            '256x256' => 0.016,
            '512x512' => 0.018,
            default => 0.020,
        };
    }
}
```

---

## Testing Strategy

### Unit Tests

```php
<?php
// Tests/Unit/Specialized/Translation/DeepLTranslatorTest.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Netresearch\NrLlm\Specialized\Exception\UnsupportedLanguageException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

class DeepLTranslatorTest extends TestCase
{
    private DeepLTranslator $subject;
    private ClientInterface&MockObject $httpClientMock;

    protected function setUp(): void
    {
        $this->httpClientMock = $this->createMock(ClientInterface::class);
        // ... setup with mocks
    }

    /** @test */
    public function translateReturnsTranslatorResult(): void
    {
        $this->mockSuccessfulResponse([
            'translations' => [
                ['text' => 'Hallo Welt', 'detected_source_language' => 'EN']
            ]
        ]);

        $result = $this->subject->translate('Hello World', 'de');

        $this->assertInstanceOf(TranslatorResult::class, $result);
        $this->assertEquals('Hallo Welt', $result->translatedText);
        $this->assertEquals('en', $result->sourceLanguage);
        $this->assertEquals('de', $result->targetLanguage);
        $this->assertEquals('deepl', $result->translator);
    }

    /** @test */
    public function translateThrowsOnUnsupportedLanguage(): void
    {
        $this->expectException(UnsupportedLanguageException::class);
        $this->expectExceptionMessage('Language "xyz" is not supported');

        $this->subject->translate('Hello', 'xyz');
    }

    /** @test */
    public function translateBatchProcessesMultipleTexts(): void
    {
        $this->mockSuccessfulResponse([
            'translations' => [
                ['text' => 'Hallo', 'detected_source_language' => 'EN'],
                ['text' => 'Welt', 'detected_source_language' => 'EN'],
            ]
        ]);

        $results = $this->subject->translateBatch(['Hello', 'World'], 'de');

        $this->assertCount(2, $results);
        $this->assertEquals('Hallo', $results[0]->translatedText);
        $this->assertEquals('Welt', $results[1]->translatedText);
    }

    /** @test */
    public function translatePassesFormality(): void
    {
        $this->httpClientMock
            ->expects($this->once())
            ->method('sendRequest')
            ->with($this->callback(function ($request) {
                $body = json_decode($request->getBody()->getContents(), true);
                return $body['formality'] === 'more';
            }))
            ->willReturn($this->createSuccessResponse());

        $this->subject->translate('Hello', 'de', null, ['formality' => 'more']);
    }

    // ... more tests
}
```

### Functional Tests

```php
<?php
// Tests/Functional/Specialized/Translation/TranslationServiceIntegrationTest.php
declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Specialized\Translation;

use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class TranslationServiceIntegrationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-llm'];

    private TranslationService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(TranslationService::class);
    }

    /** @test */
    public function translateWithTranslatorRoutes ToDeepL(): void
    {
        // Skip if DeepL not configured
        if (!$this->isDeepLConfigured()) {
            $this->markTestSkipped('DeepL API key not configured');
        }

        $result = $this->subject->translateWithTranslator(
            'Hello World',
            'de',
            null,
            ['translator' => 'deepl']
        );

        $this->assertInstanceOf(TranslatorResult::class, $result);
        $this->assertTrue($result->isFromDeepL());
    }

    /** @test */
    public function translateWithTranslatorDefaultsToLlm(): void
    {
        $result = $this->subject->translateWithTranslator(
            'Hello World',
            'de'
        );

        $this->assertInstanceOf(TranslatorResult::class, $result);
        $this->assertTrue($result->isFromLlm());
    }
}
```

---

## Directory Structure

```
Classes/
├── Provider/
│   ├── MistralProvider.php             # NEW Phase 1
│   └── GroqProvider.php                # NEW Phase 1
│
├── Specialized/
│   ├── Exception/                       # NEW Phase 0
│   │   ├── SpecializedServiceException.php
│   │   ├── TranslatorException.php
│   │   ├── SpeechServiceException.php
│   │   ├── ImageGenerationException.php
│   │   ├── UnsupportedLanguageException.php
│   │   ├── UnsupportedFormatException.php
│   │   ├── ServiceQuotaExceededException.php
│   │   └── ServiceUnavailableException.php
│   │
│   ├── Option/                          # NEW Phase 0
│   │   ├── TranscriptionOptions.php
│   │   ├── SpeechSynthesisOptions.php
│   │   ├── ImageGenerationOptions.php
│   │   └── DeepLOptions.php
│   │
│   ├── Translation/                     # NEW Phase 2
│   │   ├── TranslatorInterface.php
│   │   ├── TranslatorRegistry.php
│   │   ├── TranslatorResult.php
│   │   ├── DeepLTranslator.php
│   │   └── LlmTranslator.php
│   │
│   ├── Speech/                          # NEW Phase 3
│   │   ├── SpeechToTextInterface.php
│   │   ├── TextToSpeechInterface.php
│   │   ├── WhisperService.php
│   │   ├── OpenAiTtsService.php
│   │   ├── TranscriptionResult.php
│   │   └── SpeechResult.php
│   │
│   └── ImageGeneration/                 # NEW Phase 4
│       ├── ImageGenerationInterface.php
│       ├── DallEService.php
│       └── GeneratedImage.php
│
├── Service/
│   ├── UsageTrackerService.php          # NEW Phase 0
│   └── Feature/
│       └── TranslationService.php       # MODIFIED Phase 2
│
├── Domain/Model/
│   └── LlmConfiguration.php             # MODIFIED Phase 0
│
└── Exception/
    └── (existing exceptions)

Tests/
├── Unit/
│   └── Specialized/
│       ├── Translation/
│       │   ├── DeepLTranslatorTest.php
│       │   ├── LlmTranslatorTest.php
│       │   └── TranslatorRegistryTest.php
│       ├── Speech/
│       │   ├── WhisperServiceTest.php
│       │   └── OpenAiTtsServiceTest.php
│       └── ImageGeneration/
│           └── DallEServiceTest.php
│
└── Functional/
    └── Specialized/
        └── Translation/
            └── TranslationServiceIntegrationTest.php
```

---

## Cost Considerations

| Service | Pricing Model | Typical Cost |
|---------|---------------|--------------|
| **Mistral** | Per 1M tokens | $0.04-$2 depending on model |
| **Groq** | Per 1M tokens | $0.05-$0.27 |
| **DeepL** | Per character | Free: 500k chars/month, Pro: ~$20/1M chars |
| **Whisper** | Per minute | $0.006/minute |
| **TTS** | Per 1M characters | $15 (tts-1) / $30 (tts-1-hd) |
| **DALL-E 3** | Per image | $0.04-$0.12 per image |

---

## Implementation Checklist

See `claudedocs/plans/phase-0-implementation-checklist.md` for detailed Phase 0 checklist.
