<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

/**
 * Builds the query-centred plain-text excerpt shown per evidence source
 * (ADR-049) — the same shape SearchRecordsTool uses: tags stripped,
 * whitespace collapsed, ellipses on truncation.
 */
final readonly class ExcerptBuilder
{
    public const DEFAULT_LENGTH = 160;

    /**
     * The '<' of non-inline tags only: a space inserted there keeps adjacent
     * text nodes ("<td>Price</td><td>100</td>") separated after strip_tags
     * without splitting words joined by inline markup ("cyber<b>security</b>").
     */
    private const NON_INLINE_TAG_PATTERN = '/<(?!\/?(?:a|abbr|b|bdi|bdo|cite|code|data|dfn|em|i|kbd|mark|q|s|samp|small|span|strong|sub|sup|time|u|var|wbr)\b)/i';

    /**
     * Plain-text excerpt centred on the first query match; falls back to
     * the text head when the query does not occur literally.
     */
    public static function around(string $text, string $query, int $length = self::DEFAULT_LENGTH): string
    {
        $plain = self::plain($text);
        if ($plain === '') {
            return '';
        }

        $position = $query === '' ? false : mb_stripos($plain, $query);
        $start = $position === false ? 0 : max(0, $position - (int)($length / 2));
        $excerpt = mb_substr($plain, $start, $length);

        return ($start > 0 ? '…' : '')
            . $excerpt
            . (mb_strlen($plain) > $start + $length ? '…' : '');
    }

    public static function plain(string $text): string
    {
        // Space before each non-inline tag so adjacent text nodes stay
        // separated after strip_tags; the collapse removes the extra spaces.
        $spaced = (string)preg_replace(self::NON_INLINE_TAG_PATTERN, ' <', $text);

        return trim((string)preg_replace('/\s+/', ' ', strip_tags($spaced)));
    }
}
