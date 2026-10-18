<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Prompt;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;

/**
 * The mark that lets a snippet reach a caller's own system message (ADR-214
 * item 8).
 *
 * What reaches a system message is the block
 * {@see PromptSnippetComposer::composeSections()} emits for the snippet: its
 * name as a label, then its text. Both are editable by non-administrators
 * (ADR-169), so the mark is a digest of that block, not a flag on the record:
 * an edit of either takes the snippet out of the caller's system message until
 * an administrator marks the new block.
 *
 * The digest also covers the snippet's tags, which decide the configurations
 * that select it: re-tagging a marked snippet routes it to other
 * configurations, so it takes the snippet out until it is marked again.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class SnippetCallerMark
{
    private const VERSION = '2:';

    public function __construct(
        private PromptSnippetComposer $composer = new PromptSnippetComposer(),
    ) {}

    /**
     * The block this snippet contributes, exactly as composition writes it.
     */
    public function blockOf(PromptSnippet $snippet): string
    {
        return $this->composer->composeSections([$snippet->getName() => $snippet]);
    }

    /**
     * The tags the mark is bound to: normalised, deduplicated and sorted, so
     * a change of case, spacing or order is not a re-tagging.
     *
     * @return list<string>
     */
    public function tagsOf(PromptSnippet $snippet): array
    {
        $tags = array_values(array_unique($snippet->getTagList()));
        sort($tags);

        return $tags;
    }

    /**
     * The digest of the snippet's current block and tags; '' when the block
     * is empty, which nothing can mark.
     */
    public function digestOf(PromptSnippet $snippet): string
    {
        $block = $this->blockOf($snippet);
        if ($block === '') {
            return '';
        }

        return self::VERSION . hash('sha256', json_encode(['tags' => $this->tagsOf($snippet), 'block' => $block], JSON_THROW_ON_ERROR));
    }

    /**
     * Whether an administrator marked the snippet's CURRENT block.
     */
    public function isMarked(PromptSnippet $snippet): bool
    {
        $stored  = $snippet->getCallerSystemDigest();
        $current = $this->digestOf($snippet);

        return $stored !== '' && $current !== '' && hash_equals($stored, $current);
    }
}
