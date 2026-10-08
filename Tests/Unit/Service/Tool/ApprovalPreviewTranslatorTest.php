<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Which TCA labels the card treats as unresolved references (ADR-213). Core's
 * `sL()` hands a literal back unchanged, and on TYPO3 14 an unresolvable
 * domain reference too; only the latter may fall back to the column name.
 */
#[CoversClass(ApprovalPreviewTranslator::class)]
final class ApprovalPreviewTranslatorTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function labelsHandedBackUnchanged(): array
    {
        return [
            'a literal with a colon and no dot before it' => ['16:9', '16:9'],
            'a literal with spaces'                       => ['Format 16:9', 'Format 16:9'],
            'a plain literal'                             => ['Pinned headline label', 'Pinned headline label'],
            'a literal time'                              => ['10:30', '10:30'],
            'an unresolved domain reference'              => ['frontend.db.tt_content:header', ''],
            'an unresolved LLL reference'                 => ['LLL:EXT:nothing/Resources/Private/Language/locallang.xlf:missing', ''],
        ];
    }

    #[Test]
    #[DataProvider('labelsHandedBackUnchanged')]
    public function onlyAReferenceThatStaysUnresolvedCountsAsNoLabel(string $label, string $expected): void
    {
        self::assertSame($expected, $this->translatorEchoingLabels()->label($this->user(), $label));
    }

    #[Test]
    public function aColumnWithoutALayoutOrItemReadsAsItsNumber(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['colPos']['config']['items'] = [['label' => 'Normal', 'value' => 0]];

        try {
            $translator = $this->translatorEchoingLabels();
            self::assertSame('Normal', $translator->contentColumnLabel($this->user(), 1, 0));
            self::assertSame('100', $translator->contentColumnLabel($this->user(), 1, 100));
        } finally {
            unset($GLOBALS['TCA']);
        }
    }

    private function translatorEchoingLabels(): ApprovalPreviewTranslator
    {
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(static fn(string $label): string => $label);
        $factory = self::createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($languageService);

        return new ApprovalPreviewTranslator($factory);
    }

    private function user(): BackendUserAuthentication
    {
        $user       = new BackendUserAuthentication();
        $user->user = ['uid' => 1, 'lang' => 'de'];

        return $user;
    }
}
