<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Result object for translation requests.
 */
final readonly class TranslationResult
{
    /**
     * @param array<int, string>|null   $alternatives
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public string $translation,
        public string $sourceLanguage,
        public string $targetLanguage,
        public float $confidence,
        public UsageStatistics $usage,
        public ?array $alternatives = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Get the translated text.
     */
    public function getText(): string
    {
        return $this->translation;
    }

    /**
     * Check if confidence score meets threshold.
     */
    public function isConfident(float $threshold = 0.7): bool
    {
        return $this->confidence >= $threshold;
    }

    /**
     * Get alternative translations if available.
     *
     * @return array<int, string>
     */
    public function getAlternatives(): array
    {
        return $this->alternatives ?? [];
    }

    /**
     * Check if alternative translations were provided.
     */
    public function hasAlternatives(): bool
    {
        return !empty($this->alternatives);
    }
}
