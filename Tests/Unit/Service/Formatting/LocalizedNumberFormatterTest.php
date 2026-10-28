<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Formatting;

use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;

#[CoversClass(LocalizedNumberFormatter::class)]
final class LocalizedNumberFormatterTest extends AbstractUnitTestCase
{
    private mixed $previousLanguageService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousLanguageService = $GLOBALS['LANG'] ?? null;
        unset($GLOBALS['LANG']);
    }

    protected function tearDown(): void
    {
        $GLOBALS['LANG'] = $this->previousLanguageService;
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, float|int, int, string}>
     */
    public static function numberCases(): iterable
    {
        yield 'German count' => ['de', 5138706, 0, '5.138.706'];
        yield 'English count' => ['en', 5138706, 0, '5,138,706'];
        yield 'German decimals' => ['de', 1234.5, 2, '1.234,50'];
        yield 'English decimals' => ['en', 1234.5, 2, '1,234.50'];
        // The minimum: a fixed number of decimals, trailing zeros included.
        yield 'German four decimals keep their zeros' => ['de', 0.03, 4, '0,0300'];
        yield 'English four decimals keep their zeros' => ['en', 0.03, 4, '0.0300'];
        // The maximum: no decimals means none, ICU would print up to three.
        yield 'no decimals rounds a fraction away' => ['en', 1234567.6, 0, '1,234,568'];
        // Halves round away from zero, as number_format() does; ICU's default rounds to even.
        yield 'a half rounds up' => ['en', 0.125, 2, '0.13'];
        yield 'a negative half rounds away from zero' => ['de', -0.125, 2, '-0,13'];
        yield 'German with region' => ['de-DE', 5138706, 0, '5.138.706'];
    }

    #[Test]
    #[DataProvider('numberCases')]
    public function numberFollowsTheLocale(string $locale, float|int $value, int $decimals, string $expected): void
    {
        self::assertSame($expected, (new LocalizedNumberFormatter($locale))->number($value, $decimals));
    }

    /**
     * @return iterable<string, array{string, float, int, string}>
     */
    public static function usdCases(): iterable
    {
        // German puts the sign after the amount, separated by a no-break space.
        yield 'German amount' => ['de', 11.4, 2, "11,40\u{a0}\$"];
        yield 'English amount' => ['en', 11.4, 2, '$11.40'];
        yield 'German budget' => ['de', 1234.5, 2, "1.234,50\u{a0}\$"];
        yield 'German run estimate' => ['de', 0.00113, 4, "0,0011\u{a0}\$"];
        yield 'English run estimate' => ['en', 0.0113, 4, '$0.0113'];
        yield 'four decimals keep their zeros' => ['en', 0.03, 4, '$0.0300'];
        yield 'a half cent rounds up' => ['en', 0.125, 2, '$0.13'];
    }

    #[Test]
    #[DataProvider('usdCases')]
    public function usdPrintsDollarsTheWayTheLocaleWritesAnAmount(string $locale, float $value, int $decimals, string $expected): void
    {
        self::assertSame($expected, (new LocalizedNumberFormatter($locale))->usd($value, $decimals));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableLocales(): iterable
    {
        yield 'empty' => [''];
        yield 'POSIX C' => ['C'];
        yield 'TYPO3 default' => ['default'];
        yield 'unknown to ICU' => ['xx'];
    }

    #[Test]
    #[DataProvider('unusableLocales')]
    public function aLocaleIcuCannotUseFallsBackToUsEnglish(string $locale): void
    {
        $formatter = new LocalizedNumberFormatter($locale);

        self::assertSame('5,138,706', $formatter->number(5138706));
        self::assertSame('$11.40', $formatter->usd(11.4));
    }

    #[Test]
    public function forBackendUserReadsTheLocaleOfTheLoggedInUser(): void
    {
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('getLocale')->willReturn(new Locale('de'));
        $GLOBALS['LANG'] = $languageService;

        $formatter = LocalizedNumberFormatter::forBackendUser();

        self::assertSame('de', $formatter->locale);
        self::assertSame('5.138.706', $formatter->number(5138706));
    }

    #[Test]
    public function forBackendUserWithoutABackendUserFallsBackToUsEnglish(): void
    {
        self::assertSame(LocalizedNumberFormatter::FALLBACK_LOCALE, LocalizedNumberFormatter::forBackendUser()->locale);
    }

    #[Test]
    public function forBackendUserWithoutALocaleFallsBackToUsEnglish(): void
    {
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('getLocale')->willReturn(null);
        $GLOBALS['LANG'] = $languageService;

        self::assertSame(LocalizedNumberFormatter::FALLBACK_LOCALE, LocalizedNumberFormatter::forBackendUser()->locale);
    }
}
