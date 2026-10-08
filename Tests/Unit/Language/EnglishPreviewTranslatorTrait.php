<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Language;

use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * An {@see ApprovalPreviewTranslator} for unit tests that answers with the
 * catalogue's English source texts, so a card reads as an acting user without
 * a language reads it (ADR-213) — or with its German ones — without a TYPO3
 * container.
 */
trait EnglishPreviewTranslatorTrait
{
    private function englishTranslator(): ApprovalPreviewTranslator
    {
        return $this->catalogueTranslator(false);
    }

    /**
     * The same with the German catalogue's texts, as for an acting user whose
     * `lang` is `de`.
     */
    private function germanTranslator(): ApprovalPreviewTranslator
    {
        return $this->catalogueTranslator(true);
    }

    private function catalogueTranslator(bool $german): ApprovalPreviewTranslator
    {
        $language = self::createStub(LanguageService::class);
        $language->method('sL')->willReturnCallback(
            static fn(string $key): string => ($german ? LabelCatalogue::target($key) : LabelCatalogue::source($key)) ?? '',
        );
        $factory = self::createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($language);

        return new ApprovalPreviewTranslator($factory);
    }
}
