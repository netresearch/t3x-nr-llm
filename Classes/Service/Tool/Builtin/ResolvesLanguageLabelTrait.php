<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Resolves TCA/FlexForm labels for LLM-facing output.
 *
 * TCA table titles and field labels are frequently `LLL:EXT:…` references. A
 * raw reference is noise to the model, so this resolves it through the acting
 * backend user's {@see LanguageService} (`$GLOBALS['LANG']`) when one is
 * present. Non-`LLL:` labels pass through unchanged; when no LanguageService
 * is available or the key resolves to nothing, the original label is returned
 * rather than an empty string, so the caller never loses information.
 */
trait ResolvesLanguageLabelTrait
{
    private function resolveLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '' || !str_starts_with($label, 'LLL:')) {
            return $label;
        }

        $lang = $GLOBALS['LANG'] ?? null;
        if ($lang instanceof LanguageService) {
            $resolved = trim($lang->sL($label));
            if ($resolved !== '') {
                return $resolved;
            }
        }

        return $label;
    }
}
