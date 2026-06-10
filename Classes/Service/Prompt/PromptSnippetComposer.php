<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Prompt;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;

/**
 * Composes prompt snippets into labeled prompt sections.
 *
 * Consuming extensions resolve snippets (e.g. via
 * PromptSnippetRepository::findActiveByTag()) and pass them here to
 * build the snippet-backed part of their prompt.
 */
final class PromptSnippetComposer
{
    /**
     * Compose labeled prompt sections from snippets.
     *
     * Each non-null snippet with non-empty text becomes a block of the
     * form "LABEL:\n<snippet text>"; blocks are joined by a blank line.
     * Null entries and snippets with empty text are skipped. Returns an
     * empty string when there is nothing to compose.
     *
     * @param array<string, PromptSnippet|null> $sectionsByLabel ordered map of section label to snippet
     */
    public function composeSections(array $sectionsByLabel): string
    {
        $blocks = [];
        foreach ($sectionsByLabel as $label => $snippet) {
            if ($snippet === null) {
                continue;
            }

            $text = trim($snippet->getSnippet());
            if ($text === '') {
                continue;
            }

            $blocks[] = $label . ":\n" . $text;
        }

        return implode("\n\n", $blocks);
    }
}
