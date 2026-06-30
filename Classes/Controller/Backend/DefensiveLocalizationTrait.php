<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Throwable;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Defensive localization helper for the backend module's controllers.
 *
 * The standalone AJAX routes and docheader builders emit user-facing strings
 * (error messages, button titles, status suffixes) that are translated. Outside
 * a full TYPO3 request — most notably in an isolated unit-test context — the
 * language service is not bootstrapped and {@see LocalizationUtility::translate()}
 * throws. This helper mirrors the defensive pattern in
 * {@see RequiresBackendAdminTrait}: translate when a language service is
 * available, otherwise return the English fallback so the surface always carries
 * a human-readable string.
 */
trait DefensiveLocalizationTrait
{
    /**
     * Translate the given XLIFF key, returning $fallback when translation is
     * unavailable (missing key or no language service in the current context).
     */
    private function localize(string $key, string $fallback): string
    {
        try {
            return LocalizationUtility::translate($key, 'NrLlm') ?? $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }
}
