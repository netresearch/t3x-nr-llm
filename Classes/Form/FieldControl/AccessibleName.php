<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\FieldControl;

/**
 * Gives a field control's link an accessible name (ADR-255).
 *
 * Core renders a field control as an <a> holding only an icon whose title
 * carries the text; the link itself has no name, so a screen reader
 * announces "link" and nothing else. The translated title as aria-label on
 * the link fixes that for the nr-llm controls.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class AccessibleName
{
    /**
     * @param array<array-key, mixed> $control a field control result
     *
     * @return array<array-key, mixed>
     */
    public static function add(array $control): array
    {
        $title = $control['title'] ?? null;
        if (!is_string($title) || $title === '' || !is_array($control['linkAttributes'] ?? null)) {
            return $control;
        }

        $languageService = $GLOBALS['LANG'] ?? null;
        $label           = is_object($languageService) && method_exists($languageService, 'sL') ? $languageService->sL($title) : $title;
        if (!is_string($label) || $label === '') {
            $label = $title;
        }

        $control['linkAttributes']['aria-label'] = $label;

        return $control;
    }
}
