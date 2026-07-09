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
        return trim((string)preg_replace('/\s+/', ' ', strip_tags($text)));
    }
}
