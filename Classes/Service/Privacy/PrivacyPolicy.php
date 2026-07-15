<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Privacy;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Reads the central privacy settings from the `nr_llm` extension configuration
 * and applies them at the content sinks (ADR-064).
 *
 * Defaults are safe: metadata-only content and a 30-day retention window when a
 * setting is unset or invalid, matching the extension's historical behaviour.
 */
final readonly class PrivacyPolicy implements PrivacyPolicyInterface
{
    private const DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private ContentRedactor $redactor,
    ) {}

    public function level(): PrivacyLevel
    {
        $level = $this->privacyConfig()['level'] ?? null;

        return is_string($level)
            ? (PrivacyLevel::tryFromString($level) ?? PrivacyLevel::METADATA)
            : PrivacyLevel::METADATA;
    }

    public function retentionDays(): int
    {
        $configured = $this->privacyConfig()['retentionDays'] ?? null;
        $days       = is_numeric($configured) ? (int)$configured : 0;

        // Clamp to a sane floor: an unset / zero / negative window must not
        // purge everything immediately, so fall back to the default.
        return $days >= 1 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    public function filterContent(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        return match ($this->level()) {
            PrivacyLevel::NONE, PrivacyLevel::METADATA => null,
            PrivacyLevel::REDACTED => $this->redactor->redact($content),
            PrivacyLevel::FULL => $content,
        };
    }

    /**
     * The `privacy` sub-array of the extension configuration, or an empty array
     * when the setting is unreadable or absent. Reads defensively, mirroring
     * how TelemetryMiddleware reads its own block.
     *
     * @return array<array-key, mixed>
     */
    private function privacyConfig(): array
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return [];
        }

        $privacy = $config['privacy'] ?? null;

        return is_array($privacy) ? $privacy : [];
    }
}
