<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixture;

use Netresearch\NrLlm\Domain\Enum\PrivacyDataCategory;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter;

/**
 * A privacy policy pinned to one level and one retention window, for tests that
 * need a predictable policy without touching the extension configuration.
 */
final readonly class FixedPrivacyPolicy implements PrivacyPolicyInterface
{
    public function __construct(
        private PrivacyLevel $level = PrivacyLevel::FULL,
        private int $retentionDays = 30,
        private ContentRedactor $redactor = new ContentRedactor(),
    ) {}

    /**
     * A run-step filter backed by a policy at the given level — the shorthand
     * most call sites want.
     */
    public static function filterAt(PrivacyLevel $level): RunStepPrivacyFilter
    {
        $redactor = new ContentRedactor();

        return new RunStepPrivacyFilter(new self($level, 30, $redactor), $redactor);
    }

    public function level(): PrivacyLevel
    {
        return $this->level;
    }

    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    public function retentionDaysFor(PrivacyDataCategory $category): int
    {
        return $this->retentionDays;
    }

    public function filterContent(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }

        return match ($this->level) {
            PrivacyLevel::NONE, PrivacyLevel::METADATA => null,
            PrivacyLevel::REDACTED => $this->redactor->redact($content),
            PrivacyLevel::FULL => $content,
        };
    }
}
