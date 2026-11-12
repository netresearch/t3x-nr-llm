<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Privacy;

use Closure;

/**
 * What the `redacted` answer level keeps of an A2A content structure (a
 * message, an artifact, a history, an event payload), by allow-list
 * (spec 051 A2A-R13, ADR-282). The one rule the task store and the privacy
 * guard both apply, at bind, at completion and at every later tightening.
 *
 * - A part keeps only text: a text part its text, redacted, and its
 *   `mediaType`; a `data` part becomes a text part with its JSON, redacted;
 *   a `raw` or `url` part becomes a text part that says a file was removed.
 *   `filename` and `metadata` are dropped from every part.
 * - `metadata` is dropped wherever it occurs.
 * - The free-text `name` and `description` of an artifact are redacted.
 * - A string is kept as it is only under a structural member (ids, role,
 *   state, timestamp, `kind`, `mediaType`, extension URIs), and there only as
 *   a scalar or a list of strings; a string under any other member, one this
 *   version does not know included, or nested under a structural member, is
 *   redacted.
 *   Other scalars are kept, and lists and objects are walked.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aContentRedaction
{
    public const FILE_REMOVED = '[A file was removed under the privacy policy of this installation.]';

    /** The members whose string values carry no content. */
    private const STRUCTURAL = [
        'id', 'messageId', 'taskId', 'contextId', 'artifactId', 'referenceTaskIds',
        'role', 'state', 'timestamp', 'kind', 'mediaType', 'extensions',
    ];

    /**
     * @param Closure(string): string $redactText the level's text redaction
     */
    public static function redacted(mixed $value, Closure $redactText): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $kept = [];
        foreach ($value as $key => $item) {
            if ($key === 'metadata') {
                continue;
            }

            if ($key === 'parts' && is_array($item)) {
                $kept[$key] = array_map(static fn (mixed $part): mixed => self::part($part, $redactText), $item);

                continue;
            }

            $kept[$key] = self::member($key, $item, $redactText);
        }

        return $kept;
    }

    /**
     * @param Closure(string): string $redactText
     */
    private static function member(int|string $key, mixed $item, Closure $redactText): mixed
    {
        if (in_array($key, self::STRUCTURAL, true) && self::isPlainIdentifier($item)) {
            // A structural member, or a list of them (`referenceTaskIds`,
            // `extensions`). Anything else under such a key is walked like
            // content: a sender cannot hide text behind a structural name.
            return $item;
        }

        if (is_string($item)) {
            return $redactText($item);
        }

        return self::redacted($item, $redactText);
    }

    /**
     * A scalar, or a list of strings: what a structural member legitimately holds.
     */
    private static function isPlainIdentifier(mixed $item): bool
    {
        if (is_string($item) || is_int($item) || is_bool($item)) {
            return true;
        }

        return is_array($item) && array_is_list($item) && array_filter($item, is_string(...)) === $item;
    }

    /**
     * @param Closure(string): string $redactText
     */
    private static function part(mixed $part, Closure $redactText): mixed
    {
        if (!is_array($part)) {
            return $part;
        }

        if (array_key_exists('text', $part)) {
            $kept = ['text' => $redactText(is_string($part['text']) ? $part['text'] : '')];
            if (is_string($part['mediaType'] ?? null)) {
                $kept['mediaType'] = $part['mediaType'];
            }

            return $kept;
        }

        if (array_key_exists('data', $part)) {
            $json = json_encode($part['data'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return ['text' => $redactText(is_string($json) ? $json : '')];
        }

        // raw, url, or a part of a kind this version does not know.
        return ['text' => self::FILE_REMOVED];
    }
}
