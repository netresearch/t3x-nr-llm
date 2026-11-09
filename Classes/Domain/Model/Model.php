<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\DTO\CapabilitySet;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\ValueObject\CapabilityProvenance;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * Domain model for LLM Models.
 *
 * Represents an available LLM model with capabilities, pricing, and provider relation.
 * Models can be reused across multiple configurations.
 *
 * @api
 */
class Model extends AbstractEntity
{
    protected string $identifier = '';

    protected string $name = '';

    protected string $description = '';

    protected ?Provider $provider = null;

    protected string $modelId = '';

    protected int $contextLength = 0;

    protected int $maxOutputTokens = 0;

    /** Embedding vector dimensionality of the model (0 = unknown). */
    protected int $dimensions = 0;

    /**
     * The `@var` below is load-bearing and must not be removed as "redundant
     * with the property type" — its description is what keeps PHP-CS-Fixer's
     * `no_superfluous_phpdoc_tags` from stripping it.
     *
     * Extbase's ClassSchema resolves property types through Symfony's
     * PropertyInfo, whose ReflectionExtractor infers a COLLECTION from an
     * adder/remover pair: `addCapability()` / `removeCapability()` below inflect
     * to this property and made it resolve as `array`. Extbase's DataMapper has
     * no array mapping (`'array' => null` in `thawProperties()`), so the column
     * was silently dropped on every load — every repository-loaded model came
     * back with an EMPTY capability set, which in turn made every `capabilities`
     * selection criterion match nothing. PhpDocExtractor runs before
     * ReflectionExtractor, so the tag restores the declared type (ADR-138).
     *
     * @var string Comma-separated capability tokens, exactly as persisted
     */
    protected string $capabilities = '';

    /**
     * What the LAST provider discovery reported for this model, as the same
     * comma-separated token list `$capabilities` uses. Empty when no
     * discovery ever answered for this record — which is the honest state
     * for a hand-created model (ADR-160).
     *
     * The `@var` tag is load-bearing for the same ClassSchema reason as
     * `$capabilities` above: keep it.
     *
     * @var string Comma-separated capability tokens, exactly as persisted
     */
    protected string $capabilitiesDiscovered = '';

    /** Unix timestamp of that discovery run; 0 = never confirmed. */
    protected int $capabilitiesConfirmedAt = 0;

    /**
     * Which kind of answer the confirmation was — a
     * {@see CapabilitySource} value, or empty when nothing confirmed yet.
     * A live provider answer and the bundled static catalog are NOT the same
     * claim, so they are not stored as the same value.
     */
    protected string $capabilitiesSource = '';

    /**
     * Operator override: is this a reasoning model (ADR-275)? `yes`, `no`, or
     * empty to defer to the bundled table, the discovered profile and the
     * family default, in that order.
     */
    protected string $reasoningOverride = '';

    /**
     * Operator override: the reasoning efforts the model accepts, as
     * comma-separated {@see ReasoningEffort} values, or empty to defer.
     */
    protected string $reasoningEffortsOverride = '';

    /**
     * Operator override: where tool calls go, `chat_completions` or
     * `responses`, or empty to defer to the bundled table and family default.
     * No discovery source states it, so there is no discovered counterpart.
     */
    protected string $toolRouteOverride = '';

    /**
     * What the last run that stated it said about reasoning: `yes`, `no`, or
     * empty when no run did. Empty is "not measured", never "no" (ADR-275).
     */
    protected string $reasoningDiscovered = '';

    /** The effort scale that run stated, comma-separated; empty = not stated. */
    protected string $reasoningEffortsDiscovered = '';

    /** A {@see CapabilitySource} value, or empty when nothing was recorded. */
    protected string $reasoningSource = '';

    /** Unix timestamp of that run; 0 = never recorded. */
    protected int $reasoningConfirmedAt = 0;

    protected int $defaultTimeout = 120;

    /**
     * Cents per million input tokens. A decimal (ADR-211): a price below one
     * cent per million tokens is a price, not zero.
     */
    protected float $costInput = 0.0;

    /** Cents per million output tokens, decimal like {@see $costInput}. */
    protected float $costOutput = 0.0;

    protected bool $isActive = true;

    protected bool $isDefault = false;

    protected int $sorting = 0;

    protected int $tstamp = 0;

    protected int $crdate = 0;

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

    public function getProvider(): ?Provider
    {
        return $this->provider;
    }

    public function getModelId(): string
    {
        return $this->modelId;
    }

    public function getContextLength(): int
    {
        return $this->contextLength;
    }

    public function getMaxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    /**
     * Embedding vector dimensionality of the model (0 = unknown).
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @deprecated since 0.8.0 — use `getCapabilitySet()` for a typed
     *             `Domain\DTO\CapabilitySet`. Kept for back-compat;
     *             will not be removed before a major version bump.
     *             See REC #6 slice 16a/16b.
     */
    public function getCapabilities(): string
    {
        return $this->capabilities;
    }

    /**
     * Get capabilities as array.
     *
     * @return string[]
     *
     * @deprecated since 0.8.0 — use `getCapabilitySet()->toStringList()`
     *             (deduplicated, only valid enum tokens) or
     *             `getCapabilitySet()->capabilities` (typed
     *             `list<ModelCapability>`). Kept for back-compat —
     *             this accessor preserves duplicate tokens and order
     *             from the persisted CSV (it does NOT dedupe, unlike
     *             the typed DTO) but it DOES trim surrounding
     *             whitespace on every token. Unknown tokens are
     *             passed through verbatim as raw strings; the typed
     *             DTO would drop them at parse time.
     */
    public function getCapabilitiesArray(): array
    {
        if ($this->capabilities === '') {
            return [];
        }

        return array_map(trim(...), explode(',', $this->capabilities));
    }

    /**
     * Get capabilities as enum array.
     *
     * Intentionally does NOT delegate to `getCapabilitySet()`: the
     * typed DTO deduplicates on construction, and a caller that has
     * been holding a duplicate-preserving list (because the persisted
     * CSV does — `setCapabilities()`/`addCapability()` do not dedupe)
     * would observe a behaviour change. This accessor stays
     * byte-for-byte identical.
     *
     * @return list<ModelCapability>
     *
     * @deprecated since 0.8.0 — use `getCapabilitySet()->capabilities`
     *             unless you specifically need the duplicate-
     *             preserving behaviour. The two accessors behave
     *             identically for *valid* tokens that occur once,
     *             and both drop unknown tokens (this method via
     *             `ModelCapability::tryFrom()` returning null, the
     *             typed DTO via `coerceToEnum()`); the only delta is
     *             that this method preserves duplicates from the
     *             persisted CSV while the typed DTO dedupes.
     */
    public function getCapabilitiesAsEnums(): array
    {
        $enums = [];
        foreach ($this->getCapabilitiesArray() as $capability) {
            $enum = ModelCapability::tryFrom($capability);
            if ($enum !== null) {
                $enums[] = $enum;
            }
        }

        return $enums;
    }

    /**
     * Get capabilities as a typed `CapabilitySet` value object (REC #6).
     *
     * Preferred accessor for new callers that need to query capability
     * membership. The DTO is built fresh from the persisted CSV on each
     * call (cheap — splits a string and runs `tryFrom` per token); it
     * deduplicates and drops unknown tokens.
     *
     * The legacy string accessors (`getCapabilities()`,
     * `getCapabilitiesArray()`, `getCapabilitiesAsEnums()`) do NOT
     * route through this method — they preserve their pre-REC-#6
     * behaviour byte-for-byte (including any duplicates the persisted
     * CSV may carry). Slice 16b will migrate callers caller-by-caller.
     */
    public function getCapabilitySet(): CapabilitySet
    {
        return CapabilitySet::fromCsv($this->capabilities);
    }

    // ========================================
    // Capability provenance (ADR-160)
    // ========================================

    /**
     * When the last provider discovery answered for this model, or null when
     * none ever did.
     *
     * Deliberately nullable rather than "timestamp 0": a Fluid
     * `<f:if condition="{model.capabilitiesConfirmedDate}">` over an integer
     * 0 takes the else branch for the right reason by accident and the wrong
     * one as soon as the value becomes legitimate. Null is unambiguous.
     */
    public function getCapabilitiesConfirmedDate(): ?DateTimeImmutable
    {
        if ($this->capabilitiesConfirmedAt <= 0) {
            return null;
        }

        return (new DateTimeImmutable())->setTimestamp($this->capabilitiesConfirmedAt);
    }

    /**
     * Every declared capability with where it came from and when it was last
     * confirmed (ADR-160).
     *
     * The attribution is a set comparison, not a second stored field per
     * capability: a capability the last discovery named carries that run's
     * source and date; one only the operator ticked carries
     * {@see CapabilitySource::Operator} and no date, because there is no
     * confirmation to date. That also gives the right answer for a record
     * written before provenance existed — nothing confirmed it, and it says
     * so.
     *
     * Ordered like the declared capability set, so the operator surface can
     * render it in place of the bare token list.
     *
     * @return list<CapabilityProvenance>
     */
    public function getCapabilityProvenance(): array
    {
        $confirmedSource = CapabilitySource::tryFromStored($this->capabilitiesSource);
        $confirmedAt     = $this->getCapabilitiesConfirmedDate();
        $discovered      = CapabilitySet::fromCsv($this->capabilitiesDiscovered);

        // A source without a date (or the reverse) is a half-written
        // confirmation; treat the whole record as unconfirmed rather than
        // reporting half a claim.
        $confirmationIsComplete = $confirmedSource instanceof CapabilitySource
            && $confirmedAt instanceof DateTimeImmutable;

        $provenance = [];
        foreach ($this->getCapabilitySet()->capabilities as $capability) {
            $wasDiscovered = $confirmationIsComplete && $discovered->has($capability);

            $provenance[] = new CapabilityProvenance(
                capability: $capability,
                source: $wasDiscovered && $confirmedSource instanceof CapabilitySource
                    ? $confirmedSource
                    : CapabilitySource::Operator,
                confirmedAt: $wasDiscovered ? $confirmedAt : null,
            );
        }

        return $provenance;
    }

    /**
     * Record what a discovery run reported for this model.
     *
     * The declared capability set is NOT overwritten: an operator who ticked
     * a capability the provider does not advertise keeps it, and it is now
     * visibly attributed to them instead of borrowing the provider's
     * authority.
     *
     * @param list<string>|CapabilitySet $discovered what the run reported
     */
    public function recordCapabilityDiscovery(
        array|CapabilitySet $discovered,
        CapabilitySource $source,
        DateTimeImmutable $confirmedAt,
    ): void {
        $set = $discovered instanceof CapabilitySet ? $discovered : CapabilitySet::fromArray($discovered);

        $this->capabilitiesDiscovered  = $set->toCsv();
        $this->capabilitiesSource      = $source->value;
        $this->capabilitiesConfirmedAt = $confirmedAt->getTimestamp();
    }

    // ========================================
    // Reasoning profile (ADR-275)
    // ========================================

    private const REASONING_YES = 'yes';

    private const REASONING_NO = 'no';

    private const TOOL_ROUTE_CHAT_COMPLETIONS = 'chat_completions';

    private const TOOL_ROUTE_RESPONSES = 'responses';

    public function getReasoningOverride(): string
    {
        return $this->reasoningOverride;
    }

    /**
     * `yes` or `no`; anything else clears the override.
     */
    public function setReasoningOverride(string $reasoningOverride): void
    {
        $this->reasoningOverride = $this->normaliseFlag($reasoningOverride);
    }

    /**
     * The operator's answer, or null when the operator gave none.
     */
    public function getReasoningOverrideFlag(): ?bool
    {
        return $this->flagOf($this->reasoningOverride);
    }

    public function getReasoningEffortsOverride(): string
    {
        return $this->reasoningEffortsOverride;
    }

    /**
     * Comma-separated {@see ReasoningEffort} values. Unknown values are
     * dropped and the rest stored lowest first, without duplicates.
     */
    public function setReasoningEffortsOverride(string $reasoningEffortsOverride): void
    {
        $this->reasoningEffortsOverride = $this->effortsToCsv($this->effortsFromCsv($reasoningEffortsOverride));
    }

    /**
     * @return list<ReasoningEffort> lowest first; empty when the operator set none
     */
    public function getReasoningEffortsOverrideList(): array
    {
        return $this->effortsFromCsv($this->reasoningEffortsOverride);
    }

    public function getToolRouteOverride(): string
    {
        return $this->toolRouteOverride;
    }

    /**
     * `chat_completions` or `responses`; anything else clears the override.
     */
    public function setToolRouteOverride(string $toolRouteOverride): void
    {
        $this->toolRouteOverride = in_array($toolRouteOverride, [self::TOOL_ROUTE_CHAT_COMPLETIONS, self::TOOL_ROUTE_RESPONSES], true)
            ? $toolRouteOverride
            : '';
    }

    /**
     * True when the operator sends tool calls through Chat Completions, false
     * through Responses, null when the operator chose neither.
     */
    public function getToolsOnChatCompletionsOverride(): ?bool
    {
        return match ($this->toolRouteOverride) {
            self::TOOL_ROUTE_CHAT_COMPLETIONS => true,
            self::TOOL_ROUTE_RESPONSES        => false,
            default                           => null,
        };
    }

    public function getReasoningDiscovered(): string
    {
        return $this->reasoningDiscovered;
    }

    /**
     * What the last run stated about reasoning, or null when none did.
     */
    public function getReasoningDiscoveredFlag(): ?bool
    {
        return $this->flagOf($this->reasoningDiscovered);
    }

    public function getReasoningEffortsDiscovered(): string
    {
        return $this->reasoningEffortsDiscovered;
    }

    /**
     * The discovered scale, lowest first. Empty unless the run that stated it
     * was the provider's own model API: a third-party catalogue never supplies
     * a scale, and one found in such a record is not read (ADR-275).
     *
     * @return list<ReasoningEffort>
     */
    public function getReasoningEffortsDiscoveredList(): array
    {
        return $this->getReasoningSourceEnum() === CapabilitySource::Discovery
            ? $this->effortsFromCsv($this->reasoningEffortsDiscovered)
            : [];
    }

    public function getReasoningSource(): string
    {
        return $this->reasoningSource;
    }

    public function getReasoningSourceEnum(): ?CapabilitySource
    {
        return CapabilitySource::tryFromStored($this->reasoningSource);
    }

    /**
     * When the discovered reasoning profile was recorded, or null when never.
     */
    public function getReasoningConfirmedDate(): ?DateTimeImmutable
    {
        if ($this->reasoningConfirmedAt <= 0) {
            return null;
        }

        return (new DateTimeImmutable())->setTimestamp($this->reasoningConfirmedAt);
    }

    /**
     * Record what a discovery run stated about reasoning (ADR-275).
     *
     * Only a run that stated something calls this; a run without data for the
     * model leaves the fields as they were, so "not measured" never turns
     * into "no". The operator fields are never touched. A
     * {@see CapabilitySource::ThirdPartyCatalog} answer carries no scale: the
     * catalogue's scale was measured to differ from what OpenAI accepts.
     *
     * @param list<ReasoningEffort> $efforts the stated scale; empty when none
     */
    public function recordReasoningDiscovery(
        bool $isReasoningModel,
        array $efforts,
        CapabilitySource $source,
        DateTimeImmutable $confirmedAt,
    ): void {
        $this->reasoningDiscovered        = $isReasoningModel ? self::REASONING_YES : self::REASONING_NO;
        $this->reasoningEffortsDiscovered = $source === CapabilitySource::Discovery ? $this->effortsToCsv($efforts) : '';
        $this->reasoningSource            = $source->value;
        $this->reasoningConfirmedAt       = $confirmedAt->getTimestamp();
    }

    private function normaliseFlag(string $value): string
    {
        return in_array($value, [self::REASONING_YES, self::REASONING_NO], true) ? $value : '';
    }

    private function flagOf(string $value): ?bool
    {
        return match ($value) {
            self::REASONING_YES => true,
            self::REASONING_NO  => false,
            default             => null,
        };
    }

    /**
     * @return list<ReasoningEffort> lowest first, without duplicates
     */
    private function effortsFromCsv(string $csv): array
    {
        $efforts = [];
        foreach (explode(',', $csv) as $token) {
            $effort = ReasoningEffort::tryFrom(strtolower(trim($token)));
            if ($effort instanceof ReasoningEffort) {
                $efforts[] = $effort;
            }
        }

        return $this->sortedEfforts($efforts);
    }

    /**
     * @param list<ReasoningEffort> $efforts
     */
    private function effortsToCsv(array $efforts): string
    {
        return implode(',', array_map(
            static fn (ReasoningEffort $effort): string => $effort->value,
            $this->sortedEfforts($efforts),
        ));
    }

    /**
     * @param list<ReasoningEffort> $efforts
     *
     * @return list<ReasoningEffort> lowest first, without duplicates
     */
    private function sortedEfforts(array $efforts): array
    {
        $unique = [];
        foreach ($efforts as $effort) {
            $unique[$effort->rank()] = $effort;
        }

        ksort($unique);

        return array_values($unique);
    }

    public function getDefaultTimeout(): int
    {
        return $this->defaultTimeout;
    }

    public function getCostInput(): float
    {
        return $this->costInput;
    }

    public function getCostOutput(): float
    {
        return $this->costOutput;
    }

    /**
     * Get input cost in dollars per 1M tokens.
     */
    public function getCostInputDollars(): float
    {
        return $this->costInput / 100;
    }

    /**
     * Get output cost in dollars per 1M tokens.
     */
    public function getCostOutputDollars(): float
    {
        return $this->costOutput / 100;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isActive(): bool
    {
        return $this->getIsActive();
    }

    public function getIsDefault(): bool
    {
        return $this->isDefault;
    }

    public function isDefault(): bool
    {
        return $this->getIsDefault();
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

    public function setProvider(?Provider $provider): void
    {
        $this->provider = $provider;
    }

    public function setModelId(string $modelId): void
    {
        $this->modelId = $modelId;
    }

    public function setContextLength(int $contextLength): void
    {
        $this->contextLength = max(0, $contextLength);
    }

    public function setMaxOutputTokens(int $maxOutputTokens): void
    {
        $this->maxOutputTokens = max(0, $maxOutputTokens);
    }

    /**
     * Set the embedding vector dimensionality (0 = unknown).
     */
    public function setDimensions(int $dimensions): void
    {
        $this->dimensions = max(0, $dimensions);
    }

    /**
     * @deprecated since 0.8.0 — use `setCapabilitySet()` with a typed
     *             `CapabilitySet` to ensure validation against the
     *             `ModelCapability` enum and deduplication. Kept for
     *             back-compat (TCA-driven persistence still hands us
     *             raw CSV strings).
     */
    public function setCapabilities(string $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    /**
     * Set capabilities from array.
     *
     * @param string[] $capabilities
     *
     * @deprecated since 0.8.0 — use `setCapabilitySet(CapabilitySet::fromArray(...))`
     *             instead. Kept for back-compat.
     */
    public function setCapabilitiesArray(array $capabilities): void
    {
        $this->capabilities = implode(',', array_map(trim(...), $capabilities));
    }

    /**
     * Set capabilities from a typed `CapabilitySet` value object (REC #6).
     *
     * Preferred setter — invariants on the DTO (deduplicated, only
     * known enum values) flow through to the persisted CSV. The
     * legacy `setCapabilities()` and `setCapabilitiesArray()` setters
     * are kept for back-compat and accept arbitrary strings.
     */
    public function setCapabilitySet(CapabilitySet $capabilitySet): void
    {
        $this->capabilities = $capabilitySet->toCsv();
    }

    public function setDefaultTimeout(int $defaultTimeout): void
    {
        $this->defaultTimeout = max(0, $defaultTimeout);
    }

    public function setCostInput(float $costInput): void
    {
        $this->costInput = $this->storablePrice($costInput);
    }

    public function setCostOutput(float $costOutput): void
    {
        $this->costOutput = $this->storablePrice($costOutput);
    }

    /**
     * Set input cost in dollars per 1M tokens.
     */
    public function setCostInputDollars(float $dollars): void
    {
        $this->costInput = $this->storablePrice($dollars * 100);
    }

    /**
     * Set output cost in dollars per 1M tokens.
     */
    public function setCostOutputDollars(float $dollars): void
    {
        $this->costOutput = $this->storablePrice($dollars * 100);
    }

    /**
     * Not negative, and rounded to the two decimals the column stores — the
     * precision TYPO3's backend form keeps for a decimal field — so a value
     * read back equals the value set, whichever way it was written. Two
     * decimals of a cent per million tokens resolve 0.0001 USD per million.
     */
    private function storablePrice(float $cents): float
    {
        return max(0.0, round($cents, 2));
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function setIsDefault(bool $isDefault): void
    {
        $this->isDefault = $isDefault;
    }

    public function setSorting(int $sorting): void
    {
        $this->sorting = $sorting;
    }

    // ========================================
    // Capability Methods
    // ========================================

    /**
     * Check if model has a specific capability.
     *
     * @deprecated since 0.8.0 — use `getCapabilitySet()->has($capability)`
     *             which accepts both the typed enum and the legacy
     *             string form, trims whitespace, and validates the
     *             string against `ModelCapability::tryFrom()`.
     */
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->getCapabilitiesArray(), true);
    }

    /**
     * Add a capability.
     *
     * @deprecated since 0.8.0 — use
     *             `setCapabilitySet(getCapabilitySet()->with($capability))`.
     */
    public function addCapability(string $capability): void
    {
        $caps = $this->getCapabilitiesArray();
        if (!in_array($capability, $caps, true)) {
            $caps[] = $capability;
            $this->setCapabilitiesArray($caps);
        }
    }

    /**
     * Remove a capability.
     *
     * @deprecated since 0.8.0 — use
     *             `setCapabilitySet(getCapabilitySet()->without($capability))`.
     */
    public function removeCapability(string $capability): void
    {
        $caps = array_filter(
            $this->getCapabilitiesArray(),
            static fn (string $cap): bool => $cap !== $capability,
        );
        $this->setCapabilitiesArray($caps);
    }

    public function supportsChat(): bool
    {
        return $this->hasCapability(ModelCapability::CHAT->value);
    }

    /**
     * Whether the model answers typed decision questions natively (ADR-211).
     */
    public function supportsDecision(): bool
    {
        return $this->hasCapability(ModelCapability::DECISION->value);
    }

    public function supportsCompletion(): bool
    {
        return $this->hasCapability(ModelCapability::COMPLETION->value);
    }

    public function supportsEmbeddings(): bool
    {
        return $this->hasCapability(ModelCapability::EMBEDDINGS->value);
    }

    public function supportsVision(): bool
    {
        return $this->hasCapability(ModelCapability::VISION->value);
    }

    public function supportsStreaming(): bool
    {
        return $this->hasCapability(ModelCapability::STREAMING->value);
    }

    public function supportsTools(): bool
    {
        return $this->hasCapability(ModelCapability::TOOLS->value);
    }

    public function supportsJsonMode(): bool
    {
        return $this->hasCapability(ModelCapability::JSON_MODE->value);
    }

    public function supportsAudio(): bool
    {
        return $this->hasCapability(ModelCapability::AUDIO->value);
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Get all available capabilities.
     *
     * @return array<string, string>
     */
    public static function getAllCapabilities(): array
    {
        return [
            ModelCapability::CHAT->value       => 'Chat',
            ModelCapability::COMPLETION->value => 'Completion',
            ModelCapability::EMBEDDINGS->value => 'Embeddings',
            ModelCapability::VISION->value     => 'Vision',
            ModelCapability::STREAMING->value  => 'Streaming',
            ModelCapability::TOOLS->value      => 'Tool Use',
            ModelCapability::JSON_MODE->value  => 'JSON Mode',
            ModelCapability::AUDIO->value      => 'Audio',
            ModelCapability::DECISION->value   => 'Decisions',
        ];
    }

    /**
     * Get display name including provider.
     */
    public function getDisplayName(): string
    {
        if ($this->provider instanceof Provider) {
            return sprintf('%s (%s)', $this->name, $this->provider->getName());
        }

        return $this->name;
    }

    /**
     * Get formatted context length (e.g., "128K").
     */
    public function getFormattedContextLength(): string
    {
        return match (true) {
            $this->contextLength === 0      => 'Unknown',
            $this->contextLength >= 1000000 => sprintf('%.1fM', $this->contextLength / 1000000),
            $this->contextLength >= 1000    => sprintf('%dK', (int) ($this->contextLength / 1000)),
            default                         => (string) $this->contextLength,
        };
    }

    /**
     * Estimate cost for given token usage.
     *
     * @return float Cost in dollars
     */
    public function estimateCost(int $inputTokens, int $outputTokens): float
    {
        $inputCost  = ($inputTokens / 1000000) * $this->getCostInputDollars();
        $outputCost = ($outputTokens / 1000000) * $this->getCostOutputDollars();

        return $inputCost + $outputCost;
    }

    /**
     * Check if model has pricing information.
     */
    public function hasPricing(): bool
    {
        return $this->costInput > 0 || $this->costOutput > 0;
    }
}
