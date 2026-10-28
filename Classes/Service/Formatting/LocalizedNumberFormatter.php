<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Formatting;

use IntlException;
use NumberFormatter;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;
use ValueError;

/**
 * Numbers and USD amounts as a backend user reads them: separators, and the
 * position of the dollar sign, from the user's locale (NEXT-170).
 *
 * The one formatter behind the AI Tasks budget panel, its JSON refresh, the
 * Analytics module and the other backend views that print counts or costs, so
 * a page and the AJAX answer that updates it say the same.
 *
 * ICU does the formatting, as `\TYPO3\CMS\Core\Localization\DateFormatter` does
 * for dates; `ext-intl` is required by typo3/cms-core 13.4 and 14.3 alike.
 * Amounts stay in USD, the currency budgets and model prices are stored in:
 * English reads "$11.40", German "11,40 $".
 *
 * The number of decimals is fixed per call (both minimum and maximum), and
 * halves round away from zero as `number_format()` does; ICU's default would
 * round them to even.
 *
 * Newable: no DI. {@see self::forBackendUser()} reads the locale of the
 * logged-in backend user.
 *
 * @api
 */
final readonly class LocalizedNumberFormatter
{
    /**
     * What a locale ICU cannot use falls back to — the formatting nr-llm
     * printed before it followed the user's locale. "C" maps here too, as in
     * core's DateFormatter.
     */
    public const FALLBACK_LOCALE = 'en-US';

    public string $locale;

    public function __construct(string $locale)
    {
        $this->locale = in_array($locale, ['', 'C', 'default'], true) ? self::FALLBACK_LOCALE : $locale;
    }

    /**
     * The formatter for the backend user of the current request; the fallback
     * locale when there is none (CLI, scheduler) or it has no locale set.
     */
    public static function forBackendUser(): self
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $locale          = $languageService instanceof LanguageService ? $languageService->getLocale() : null;

        return new self($locale instanceof Locale ? (string) $locale : self::FALLBACK_LOCALE);
    }

    public function number(float|int $value, int $decimals = 0): string
    {
        $formatted = $this->formatter(NumberFormatter::DECIMAL, $decimals)->format($value);

        return $formatted === false ? number_format((float) $value, $decimals) : $formatted;
    }

    /**
     * An amount in USD with the dollar sign where the locale puts a currency
     * sign.
     */
    public function usd(float $value, int $decimals = 2): string
    {
        $formatted = $this->formatter(NumberFormatter::CURRENCY, $decimals)->formatCurrency($value, 'USD');

        return $formatted === false ? '$' . number_format($value, $decimals) : $formatted;
    }

    private function formatter(int $style, int $decimals): NumberFormatter
    {
        try {
            $formatter = new NumberFormatter($this->locale, $style);
        } catch (ValueError|IntlException) {
            $formatter = null;
        }

        // PHP 8.4 and later throw for a locale ICU cannot use; 8.2 and 8.3
        // quietly use ICU's default locale instead (en_US_POSIX in the CI
        // images). Both read as "not the language asked for".
        if (!$formatter instanceof NumberFormatter || !$this->resolves($formatter)) {
            $formatter = new NumberFormatter(self::FALLBACK_LOCALE, $style);
        }

        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::ROUNDING_MODE, NumberFormatter::ROUND_HALFUP);

        return $formatter;
    }

    /**
     * Whether ICU found data for the language the locale names.
     */
    private function resolves(NumberFormatter $formatter): bool
    {
        $valid = $formatter->getLocale(\Locale::VALID_LOCALE);
        if (!is_string($valid) || $valid === '' || $valid === 'root') {
            return false;
        }

        return \Locale::getPrimaryLanguage($valid) === \Locale::getPrimaryLanguage($this->locale);
    }
}
