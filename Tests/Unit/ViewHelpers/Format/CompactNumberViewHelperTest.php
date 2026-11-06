<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\ViewHelpers\Format;

use Netresearch\NrLlm\Service\Formatting\CompactNumberFormatter;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\ViewHelpers\Format\CompactNumberViewHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\Locale;

#[CoversClass(CompactNumberViewHelper::class)]
#[CoversClass(CompactNumberFormatter::class)]
final class CompactNumberViewHelperTest extends AbstractUnitTestCase
{
    private mixed $previousLanguageService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousLanguageService = $GLOBALS['LANG'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['LANG'] = $this->previousLanguageService;
        parent::tearDown();
    }

    #[Test]
    public function rendersTheChildValueShortInTheBackendUsersLocale(): void
    {
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('getLocale')->willReturn(new Locale('de'));
        $GLOBALS['LANG'] = $languageService;

        self::assertSame("17,5\u{a0}Mio.", $this->render(17546410));
        self::assertSame('3.714', $this->render('3714'), 'A numeric string from the database counts as a number.');
    }

    #[Test]
    public function rendersZeroForAValueThatIsNoNumber(): void
    {
        unset($GLOBALS['LANG']);

        self::assertSame('0', $this->render('n/a'));
    }

    private function render(mixed $value): string
    {
        $viewHelper = new CompactNumberViewHelper();
        $viewHelper->setRenderChildrenClosure(static fn (): mixed => $value);

        return $viewHelper->render();
    }
}
