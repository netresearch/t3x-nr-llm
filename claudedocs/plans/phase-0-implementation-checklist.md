# Phase 0: Foundation Implementation Checklist

**Project**: `/home/sme/p/t3x-nr-llm/main`
**Date**: 2025-12-23
**Estimated Effort**: 2 days
**Priority**: CRITICAL - Must complete before any other phase

---

## Prerequisites

- [ ] PHP 8.5 environment running
- [ ] TYPO3 14 development instance available
- [ ] Composer dependencies installed
- [ ] Unit test framework working (`composer test`)
- [ ] Extension activated in TYPO3

---

## Task 0.1: Exception Hierarchy

**Directory**: `Classes/Specialized/Exception/`

### Files to Create

#### 0.1.1 Abstract Base Exception
- [ ] Create `Classes/Specialized/Exception/SpecializedServiceException.php`

```php
<?php
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

#### 0.1.2 Service-Specific Exceptions
- [ ] Create `TranslatorException.php`
- [ ] Create `SpeechServiceException.php`
- [ ] Create `ImageGenerationException.php`

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

final class TranslatorException extends SpecializedServiceException {}
```

#### 0.1.3 Error Condition Exceptions
- [ ] Create `UnsupportedLanguageException.php`
- [ ] Create `UnsupportedFormatException.php`
- [ ] Create `ServiceQuotaExceededException.php`
- [ ] Create `ServiceUnavailableException.php`

Each follows the same pattern as `TranslatorException`.

### Verification
- [ ] All exception files created (8 files total)
- [ ] PHP syntax valid: `php -l Classes/Specialized/Exception/*.php`
- [ ] Namespace matches directory structure

---

## Task 0.2: Specialized Option Objects

**Directory**: `Classes/Specialized/Option/`

### Files to Create

#### 0.2.1 TranscriptionOptions (Whisper)
- [ ] Create `Classes/Specialized/Option/TranscriptionOptions.php`

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use Netresearch\NrLlm\Service\Option\AbstractOptions;

final class TranscriptionOptions extends AbstractOptions
{
    private const VALID_FORMATS = ['json', 'text', 'srt', 'vtt', 'verbose_json'];

    public function __construct(
        public readonly ?string $model = 'whisper-1',
        public readonly ?string $language = null,
        public readonly ?string $format = 'json',
        public readonly ?string $prompt = null,
        public readonly ?float $temperature = null,
    ) {
        if ($this->format !== null) {
            self::validateEnum($this->format, self::VALID_FORMATS, 'format');
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

#### 0.2.2 SpeechSynthesisOptions (TTS)
- [ ] Create `Classes/Specialized/Option/SpeechSynthesisOptions.php`

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use Netresearch\NrLlm\Service\Option\AbstractOptions;

final class SpeechSynthesisOptions extends AbstractOptions
{
    private const VALID_VOICES = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    private const VALID_MODELS = ['tts-1', 'tts-1-hd'];
    private const VALID_FORMATS = ['mp3', 'opus', 'aac', 'flac', 'wav', 'pcm'];

    public function __construct(
        public readonly ?string $model = 'tts-1',
        public readonly ?string $voice = 'alloy',
        public readonly ?string $format = 'mp3',
        public readonly ?float $speed = 1.0,
    ) {
        if ($this->model !== null) {
            self::validateEnum($this->model, self::VALID_MODELS, 'model');
        }
        if ($this->voice !== null) {
            self::validateEnum($this->voice, self::VALID_VOICES, 'voice');
        }
        if ($this->format !== null) {
            self::validateEnum($this->format, self::VALID_FORMATS, 'format');
        }
        if ($this->speed !== null) {
            self::validateRange($this->speed, 0.25, 4.0, 'speed');
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'model' => $this->model,
            'voice' => $this->voice,
            'response_format' => $this->format,
            'speed' => $this->speed,
        ], fn($v) => $v !== null);
    }

    public static function fromArray(array $options): static
    {
        return new self(
            model: $options['model'] ?? null,
            voice: $options['voice'] ?? null,
            format: $options['format'] ?? $options['response_format'] ?? null,
            speed: isset($options['speed']) ? (float) $options['speed'] : null,
        );
    }
}
```

#### 0.2.3 ImageGenerationOptions (DALL-E)
- [ ] Create `Classes/Specialized/Option/ImageGenerationOptions.php`

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use Netresearch\NrLlm\Service\Option\AbstractOptions;

final class ImageGenerationOptions extends AbstractOptions
{
    private const VALID_MODELS = ['dall-e-2', 'dall-e-3'];
    private const VALID_QUALITIES = ['standard', 'hd'];
    private const VALID_STYLES = ['vivid', 'natural'];
    private const VALID_FORMATS = ['url', 'b64_json'];
    private const VALID_SIZES_DALLE3 = ['1024x1024', '1792x1024', '1024x1792'];
    private const VALID_SIZES_DALLE2 = ['256x256', '512x512', '1024x1024'];

    public function __construct(
        public readonly ?string $model = 'dall-e-3',
        public readonly ?string $size = '1024x1024',
        public readonly ?string $quality = 'standard',
        public readonly ?string $style = 'vivid',
        public readonly ?string $format = 'url',
    ) {
        if ($this->model !== null) {
            self::validateEnum($this->model, self::VALID_MODELS, 'model');
        }
        if ($this->quality !== null) {
            self::validateEnum($this->quality, self::VALID_QUALITIES, 'quality');
        }
        if ($this->style !== null) {
            self::validateEnum($this->style, self::VALID_STYLES, 'style');
        }
        if ($this->format !== null) {
            self::validateEnum($this->format, self::VALID_FORMATS, 'format');
        }
        $this->validateSize();
    }

    private function validateSize(): void
    {
        if ($this->size === null) {
            return;
        }

        $validSizes = $this->model === 'dall-e-2'
            ? self::VALID_SIZES_DALLE2
            : self::VALID_SIZES_DALLE3;

        if (!in_array($this->size, $validSizes, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid size "%s" for model %s', $this->size, $this->model)
            );
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'model' => $this->model,
            'size' => $this->size,
            'quality' => $this->quality,
            'style' => $this->style,
            'response_format' => $this->format,
        ], fn($v) => $v !== null);
    }

    public static function fromArray(array $options): static
    {
        return new self(
            model: $options['model'] ?? null,
            size: $options['size'] ?? null,
            quality: $options['quality'] ?? null,
            style: $options['style'] ?? null,
            format: $options['format'] ?? $options['response_format'] ?? null,
        );
    }
}
```

#### 0.2.4 DeepLOptions
- [ ] Create `Classes/Specialized/Option/DeepLOptions.php`

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use Netresearch\NrLlm\Service\Option\AbstractOptions;

final class DeepLOptions extends AbstractOptions
{
    private const VALID_FORMALITIES = ['default', 'more', 'less', 'prefer_more', 'prefer_less'];

    public function __construct(
        public readonly ?string $formality = 'default',
        public readonly ?string $glossaryId = null,
        public readonly ?bool $preserveFormatting = null,
        public readonly ?bool $splitSentences = null,
        public readonly ?string $tagHandling = null,
    ) {
        if ($this->formality !== null) {
            self::validateEnum($this->formality, self::VALID_FORMALITIES, 'formality');
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'formality' => $this->formality,
            'glossary_id' => $this->glossaryId,
            'preserve_formatting' => $this->preserveFormatting,
            'split_sentences' => $this->splitSentences,
            'tag_handling' => $this->tagHandling,
        ], fn($v) => $v !== null);
    }

    public static function fromArray(array $options): static
    {
        return new self(
            formality: $options['formality'] ?? null,
            glossaryId: $options['glossary_id'] ?? $options['glossaryId'] ?? null,
            preserveFormatting: $options['preserve_formatting'] ?? $options['preserveFormatting'] ?? null,
            splitSentences: $options['split_sentences'] ?? $options['splitSentences'] ?? null,
            tagHandling: $options['tag_handling'] ?? $options['tagHandling'] ?? null,
        );
    }
}
```

### Verification
- [ ] All option files created (4 files)
- [ ] PHP syntax valid: `php -l Classes/Specialized/Option/*.php`
- [ ] Inherits from `AbstractOptions`
- [ ] All validation works correctly

---

## Task 0.3: LlmConfiguration Entity Update

### 0.3.1 Database Migration
- [ ] Add to `ext_tables.sql`:

```sql
-- Add translator field (line after 'model' field in existing schema)
-- Manual migration: ALTER TABLE tx_nrllm_configuration ADD translator varchar(50) DEFAULT '' NOT NULL AFTER model;
```

- [ ] Run database compare in Install Tool or:
```bash
./vendor/bin/typo3 database:updateschema
```

### 0.3.2 Domain Model Update
- [ ] Edit `Classes/Domain/Model/LlmConfiguration.php`

Add property and methods:

```php
protected string $translator = '';

public function getTranslator(): string
{
    return $this->translator;
}

public function setTranslator(string $translator): void
{
    $this->translator = $translator;
}
```

- [ ] Update `toOptionsArray()` method to include translator:

```php
public function toOptionsArray(): array
{
    $options = [
        'provider' => $this->provider,
        'model' => $this->model,
        'temperature' => $this->temperature,
        'maxTokens' => $this->maxTokens,
        'topP' => $this->topP,
        'frequencyPenalty' => $this->frequencyPenalty,
        'presencePenalty' => $this->presencePenalty,
    ];

    if ($this->systemPrompt !== '') {
        $options['systemPrompt'] = $this->systemPrompt;
    }

    if ($this->translator !== '') {
        $options['translator'] = $this->translator;
    }

    return $options;
}
```

### 0.3.3 TCA Update
- [ ] Edit `Configuration/TCA/tx_nrllm_configuration.php`

Add to columns:

```php
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

- [ ] Add translator to 'types' showitem after 'model'

### 0.3.4 Language Labels
- [ ] Edit `Resources/Private/Language/locallang_tca.xlf`

Add:
```xml
<trans-unit id="tx_nrllm_configuration.translator">
    <source>Specialized Translator</source>
</trans-unit>
```

### Verification
- [ ] Database field exists: `DESCRIBE tx_nrllm_configuration;`
- [ ] TCA field shows in backend form
- [ ] Can save and retrieve translator value
- [ ] toOptionsArray() includes translator when set

---

## Task 0.4: TranslatorResult DTO

- [ ] Create `Classes/Specialized/Translation/TranslatorResult.php`

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Translation;

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

    public function getTranslatorName(): string
    {
        return $this->isFromLlm()
            ? 'LLM (' . substr($this->translator, 4) . ')'
            : ucfirst($this->translator);
    }
}
```

### Verification
- [ ] File created
- [ ] PHP syntax valid
- [ ] No conflict with existing `Domain\Model\TranslationResult`
- [ ] Namespace is `Specialized\Translation`

---

## Task 0.5: Database Schema for Usage Tracking

### 0.5.1 Add Table Schema
- [ ] Add to `ext_tables.sql`:

```sql
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

### 0.5.2 Run Database Update
- [ ] Execute: `./vendor/bin/typo3 database:updateschema`

### Verification
- [ ] Table exists: `SHOW TABLES LIKE 'tx_nrllm_service_usage';`
- [ ] Indexes created: `SHOW INDEX FROM tx_nrllm_service_usage;`

---

## Task 0.6: UsageTrackerService

- [ ] Create `Classes/Service/UsageTrackerService.php`

```php
<?php
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

    /**
     * Track service usage with daily aggregation
     */
    public function trackUsage(
        string $serviceType,
        string $provider,
        array $metrics = [],
        ?int $configurationUid = null,
    ): void {
        $beUser = ($GLOBALS['BE_USER']->user['uid'] ?? null) ?: 0;
        $today = strtotime('today');
        $now = time();

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();

        // Check if record exists for today
        $existingUid = $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('service_type', $queryBuilder->createNamedParameter($serviceType)),
                $queryBuilder->expr()->eq('service_provider', $queryBuilder->createNamedParameter($provider)),
                $queryBuilder->expr()->eq('be_user', $beUser),
                $queryBuilder->expr()->eq('request_date', $today)
            )
            ->executeQuery()
            ->fetchOne();

        if ($existingUid !== false) {
            // Update existing record
            $connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET
                    request_count = request_count + 1,
                    tokens_used = tokens_used + :tokens,
                    characters_used = characters_used + :characters,
                    audio_seconds_used = audio_seconds_used + :audioSeconds,
                    images_generated = images_generated + :images,
                    estimated_cost = estimated_cost + :cost,
                    tstamp = :tstamp
                WHERE uid = :uid',
                [
                    'tokens' => $metrics['tokens'] ?? 0,
                    'characters' => $metrics['characters'] ?? 0,
                    'audioSeconds' => $metrics['audioSeconds'] ?? 0,
                    'images' => $metrics['images'] ?? 0,
                    'cost' => $metrics['cost'] ?? 0.0,
                    'tstamp' => $now,
                    'uid' => $existingUid,
                ]
            );
        } else {
            // Insert new record
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
                'tstamp' => $now,
                'crdate' => $now,
            ]);
        }
    }

    /**
     * Get usage report for a date range
     */
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
            ->addSelectLiteral('SUM(audio_seconds_used) as total_audio_seconds')
            ->addSelectLiteral('SUM(images_generated) as total_images')
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

    /**
     * Get usage for specific user
     */
    public function getUserUsage(
        int $beUserUid,
        \DateTimeInterface $from,
        \DateTimeInterface $to,
    ): array {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('service_type', 'service_provider')
            ->addSelectLiteral('SUM(request_count) as total_requests')
            ->addSelectLiteral('SUM(estimated_cost) as total_cost')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user', $beUserUid),
                $queryBuilder->expr()->gte('request_date', $from->getTimestamp()),
                $queryBuilder->expr()->lte('request_date', $to->getTimestamp())
            )
            ->groupBy('service_type', 'service_provider')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
```

### Verification
- [ ] File created
- [ ] PHP syntax valid
- [ ] Can inject service in other classes
- [ ] trackUsage() creates/updates records correctly

---

## Task 0.7: Services.yaml Registration

- [ ] Edit `Configuration/Services.yaml`

Add after existing services:

```yaml
  # ========================================
  # Specialized Service Infrastructure
  # ========================================

  Netresearch\NrLlm\Service\UsageTrackerService:
    public: true

  # ========================================
  # Translation Registry (Phase 2)
  # ========================================

  # Commented until Phase 2:
  # Netresearch\NrLlm\Specialized\Translation\TranslatorRegistry:
  #   public: true

  # Netresearch\NrLlm\Specialized\Translation\LlmTranslator:
  #   public: true
  #   tags:
  #     - name: nr_llm.translator
  #       identifier: llm
  #       priority: 100

  # Netresearch\NrLlm\Specialized\Translation\DeepLTranslator:
  #   public: true
  #   tags:
  #     - name: nr_llm.translator
  #       identifier: deepl
  #       priority: 90
```

### Verification
- [ ] YAML syntax valid
- [ ] Extension loads without DI errors
- [ ] UsageTrackerService injectable

---

## Task 0.8: Unit Tests for Phase 0

### 0.8.1 Exception Tests
- [ ] Create `Tests/Unit/Specialized/Exception/SpecializedServiceExceptionTest.php`

### 0.8.2 Option Tests
- [ ] Create `Tests/Unit/Specialized/Option/TranscriptionOptionsTest.php`
- [ ] Create `Tests/Unit/Specialized/Option/SpeechSynthesisOptionsTest.php`
- [ ] Create `Tests/Unit/Specialized/Option/ImageGenerationOptionsTest.php`
- [ ] Create `Tests/Unit/Specialized/Option/DeepLOptionsTest.php`

Example test:

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Option;

use Netresearch\NrLlm\Specialized\Option\TranscriptionOptions;
use PHPUnit\Framework\TestCase;

class TranscriptionOptionsTest extends TestCase
{
    /** @test */
    public function constructorWithDefaultsCreatesValidOptions(): void
    {
        $options = new TranscriptionOptions();

        $this->assertEquals('whisper-1', $options->model);
        $this->assertEquals('json', $options->format);
        $this->assertNull($options->language);
    }

    /** @test */
    public function toArrayExcludesNullValues(): void
    {
        $options = new TranscriptionOptions(language: 'en');
        $array = $options->toArray();

        $this->assertArrayHasKey('language', $array);
        $this->assertArrayNotHasKey('prompt', $array);
    }

    /** @test */
    public function invalidFormatThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TranscriptionOptions(format: 'invalid');
    }

    /** @test */
    public function fromArrayCreatesEquivalentOptions(): void
    {
        $original = new TranscriptionOptions(model: 'whisper-1', language: 'de');
        $fromArray = TranscriptionOptions::fromArray(['model' => 'whisper-1', 'language' => 'de']);

        $this->assertEquals($original->toArray(), $fromArray->toArray());
    }
}
```

### 0.8.3 TranslatorResult Tests
- [ ] Create `Tests/Unit/Specialized/Translation/TranslatorResultTest.php`

### Verification
- [ ] All tests pass: `composer test`
- [ ] No PHPStan errors: `composer phpstan`

---

## Final Phase 0 Verification

### Code Quality
- [ ] `composer phpcs` passes
- [ ] `composer phpstan` passes
- [ ] `composer test` passes

### Integration
- [ ] Extension loads without errors
- [ ] Backend module still works
- [ ] No PHP errors in TYPO3 system log

### Documentation
- [ ] All new files have proper docblocks
- [ ] PHPDoc types match PHP 8.5 types
- [ ] Code follows existing project patterns

---

## Files Created in Phase 0

```
Classes/
├── Specialized/
│   ├── Exception/
│   │   ├── SpecializedServiceException.php
│   │   ├── TranslatorException.php
│   │   ├── SpeechServiceException.php
│   │   ├── ImageGenerationException.php
│   │   ├── UnsupportedLanguageException.php
│   │   ├── UnsupportedFormatException.php
│   │   ├── ServiceQuotaExceededException.php
│   │   └── ServiceUnavailableException.php
│   │
│   ├── Option/
│   │   ├── TranscriptionOptions.php
│   │   ├── SpeechSynthesisOptions.php
│   │   ├── ImageGenerationOptions.php
│   │   └── DeepLOptions.php
│   │
│   └── Translation/
│       └── TranslatorResult.php
│
├── Service/
│   └── UsageTrackerService.php
│
└── Domain/Model/
    └── LlmConfiguration.php  (MODIFIED)

Configuration/
├── TCA/
│   └── tx_nrllm_configuration.php  (MODIFIED)
└── Services.yaml  (MODIFIED)

Resources/Private/Language/
└── locallang_tca.xlf  (MODIFIED)

ext_tables.sql  (MODIFIED)

Tests/Unit/Specialized/
├── Exception/
│   └── SpecializedServiceExceptionTest.php
├── Option/
│   ├── TranscriptionOptionsTest.php
│   ├── SpeechSynthesisOptionsTest.php
│   ├── ImageGenerationOptionsTest.php
│   └── DeepLOptionsTest.php
└── Translation/
    └── TranslatorResultTest.php
```

---

## After Phase 0 Completion

Once all tasks are verified:

1. **Commit**: `git commit -m "Add Phase 0 foundation for specialized AI services"`
2. **Proceed to Phase 1**: MistralProvider + GroqProvider implementation
3. **Alternative**: Jump to Phase 2A if translation is higher priority
