<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Analytics;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The markup the billing report adds to the estimated cost — the extension
 * setting `billing.markupPercent` (ADR-231), a whole percentage 0..1000.
 *
 * The report shows it; stored costs never change. The Install Tool form
 * validates the range, a hand-edited settings.php does not, so the value is
 * read defensively: empty or not a whole number reads 0, a number outside
 * the range is clamped. `adjusted` tells the page to say that the effective
 * value differs from the stored one.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class BillingMarkup
{
    public const MAX_PERCENT = 1000;

    private const SETTING = 'billing/markupPercent';

    private function __construct(
        public int $percent,
        public bool $adjusted,
    ) {}

    public static function fromConfiguredValue(mixed $raw): self
    {
        if ($raw === null || $raw === '') {
            return new self(0, false);
        }

        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && preg_match('/^\s*-?\d+\s*$/', $raw) === 1) {
            $value = (int) trim($raw);
        } else {
            return new self(0, true);
        }

        $clamped = max(0, min(self::MAX_PERCENT, $value));

        return new self($clamped, $clamped !== $value);
    }

    public static function fromExtensionConfiguration(ExtensionConfiguration $configuration): self
    {
        try {
            $raw = $configuration->get('nr_llm', self::SETTING);
        } catch (Throwable) {
            // Missing path: an installation that never saved the setting.
            $raw = null;
        }

        return self::fromConfiguredValue($raw);
    }

    /**
     * The markup on an amount in micro-dollars, rounded half up to one
     * micro-dollar (6 decimals, the precision of `estimated_cost`).
     */
    public function markupMicros(int $costMicros): int
    {
        return intdiv($costMicros * $this->percent + 50, 100);
    }
}
