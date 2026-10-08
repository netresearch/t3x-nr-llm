<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Tells a consumer whether a preview line is a card heading (ADR-213).
 *
 * The first line of a successful built-in preview names the action ("Seite
 * löschen", "Move content element"). The first line of a refused call is the
 * refusal, and other first lines may be summaries; neither is a heading. A card
 * that wants to reuse the heading (as its title or button text) checks the line
 * against these texts, resolved in the language the lines were written in:
 * the run's acting backend user's.
 *
 * A heading takes no placeholder, so a line is a heading exactly when it equals
 * one of the resolved texts.
 *
 * @api
 */
final class ApprovalPreviewHeadings
{
    private function __construct() {}

    /**
     * The `LLL:` references of every heading text, for resolving with
     * `LanguageService::sL()`.
     *
     * @return list<non-empty-string>
     */
    public static function labelReferences(): array
    {
        $references = [];
        foreach (ApprovalPreviewLabel::cases() as $label) {
            if ($label->isHeading()) {
                $references[] = $label->reference();
            }
        }

        return $references;
    }

    /**
     * Whether `$line` is a heading in the language `$languageService` speaks.
     * Pass the language of the run's acting user, the one the lines are in.
     */
    public static function isHeading(string $line, LanguageService $languageService): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }

        foreach (self::labelReferences() as $reference) {
            if (trim($languageService->sL($reference)) === $line) {
                return true;
            }
        }

        return false;
    }
}
