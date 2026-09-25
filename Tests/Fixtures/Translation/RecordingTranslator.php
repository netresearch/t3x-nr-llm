<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Translation;

use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorResult;
use Throwable;

/**
 * A translator that records every call and answers "[<target>] <text>".
 *
 * Deterministic on purpose: a test can tell a translated field from a copied
 * one by the prefix, and count how often the translator was actually reached —
 * which is the only way to see a cache (ADR-209).
 */
final class RecordingTranslator implements TranslatorInterface
{
    /** @var list<array{text: string, target: string, source: ?string, options: array<string, mixed>}> */
    public array $calls = [];

    /** Thrown by the next translate() call, once. */
    public ?Throwable $failNext = null;

    public function __construct(
        private readonly string $identifier,
        private readonly string $name,
        private readonly bool $available = true,
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public static function getPriority(): int
    {
        return 0;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): TranslatorResult
    {
        $this->calls[] = ['text' => $text, 'target' => $targetLanguage, 'source' => $sourceLanguage, 'options' => $options];

        if ($this->failNext instanceof Throwable) {
            $failure        = $this->failNext;
            $this->failNext = null;

            throw $failure;
        }

        return new TranslatorResult(
            sprintf('[%s] %s', $targetLanguage, $text),
            $sourceLanguage ?? 'en',
            $targetLanguage,
            $this->identifier,
        );
    }

    public function translateBatch(array $texts, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): array
    {
        return array_map(
            fn(string $text): TranslatorResult => $this->translate($text, $targetLanguage, $sourceLanguage, $options),
            array_values($texts),
        );
    }

    public function getSupportedLanguages(): array
    {
        return ['en', 'de'];
    }

    public function detectLanguage(string $text): string
    {
        return 'en';
    }

    public function supportsLanguagePair(string $sourceLanguage, string $targetLanguage): bool
    {
        return true;
    }
}
