<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ApprovalPreviewHeadings;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A successful preview opens with one of the published headings (ADR-213),
 * the line a consumer may reuse as card title or button text — checked through
 * the public {@see ApprovalPreviewHeadings} a consumer calls.
 */
trait AssertsPreviewHeadingTrait
{
    /**
     * @param list<string> $lines a successful preview in `$language`
     */
    private static function assertStartsWithHeading(array $lines, string $language): void
    {
        self::assertNotSame([], $lines);
        $languageService = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create($language);

        self::assertTrue(
            ApprovalPreviewHeadings::isHeading($lines[0], $languageService),
            'The preview does not open with a heading: ' . $lines[0],
        );
    }
}
