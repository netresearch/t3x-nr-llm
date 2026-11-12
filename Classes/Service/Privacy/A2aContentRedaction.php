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
 *   `mediaType` when it is a media type; a `data` part becomes a text part
 *   with its JSON, redacted;
 *   a `raw` or `url` part becomes a text part that says a file was removed.
 *   `filename` and `metadata` are dropped from every part.
 * - `metadata` is dropped wherever it occurs.
 * - The free-text `name` and `description` of an artifact are redacted.
 * - A string is kept as it is only under a structural member (ids, role,
 *   state, timestamp, `kind`, `mediaType`, extension URIs), and there only in
 *   the member's form: an id pattern, an enum value, a timestamp, a media
 *   type, an http(s) URI without query. A string of another form, a string
 *   under any other member, one this version does not know included, and
 *   anything nested under a structural member are redacted.
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

    /** An identifier: a UUID, a ULID or another token without spaces or markup. */
    private const ID = '/^[A-Za-z0-9._:-]{1,128}$/';

    /** A media type (RFC 6838), with optional token parameters. */
    private const MEDIA_TYPE = '#^[A-Za-z0-9!\#$&^_.+-]{1,127}/[A-Za-z0-9!\#$&^_.+-]{1,127}(?:\s*;\s*[A-Za-z0-9!\#$&^_.+-]+=[A-Za-z0-9!\#$&^_.+-]+)*$#';

    private const EXTENSION_URI = '#^https?://[A-Za-z0-9.-]+(?::\d{1,5})?(?:/[A-Za-z0-9._~/-]*)?$#';

    /**
     * The values of `A2aRole` and `A2aTaskState`, written out because the
     * privacy core may not depend on the A2A module (ADR-090); a unit test
     * keeps them in step with the enums.
     */
    private const ROLES = ['ROLE_UNSPECIFIED', 'ROLE_USER', 'ROLE_AGENT'];

    private const STATES = [
        'TASK_STATE_UNSPECIFIED', 'TASK_STATE_SUBMITTED', 'TASK_STATE_WORKING', 'TASK_STATE_COMPLETED',
        'TASK_STATE_FAILED', 'TASK_STATE_CANCELED', 'TASK_STATE_INPUT_REQUIRED', 'TASK_STATE_REJECTED',
        'TASK_STATE_AUTH_REQUIRED',
    ];

    /** The form of `A2aTimestamp`: UTC, up to nine fraction digits. */
    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/';

    /** The `kind` discriminators of A2A 0.3 objects and parts. */
    private const KINDS = ['message', 'task', 'status-update', 'artifact-update', 'text', 'file', 'data'];

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
        if (is_string($key) && in_array($key, self::STRUCTURAL, true)) {
            return self::structural($key, $item, $redactText);
        }

        if (is_string($item)) {
            return $redactText($item);
        }

        return self::redacted($item, $redactText);
    }

    /**
     * A structural member keeps its value only in the form the protocol gives
     * it; a string of any other form is redacted like content, and anything
     * nested is walked like content, so a sender cannot hide text behind a
     * structural name.
     *
     * @param Closure(string): string $redactText
     */
    private static function structural(string $key, mixed $item, Closure $redactText): mixed
    {
        if (is_int($item) || is_bool($item)) {
            return $item;
        }

        if (is_string($item)) {
            return self::isStructuralValue($key, $item) ? $item : $redactText($item);
        }

        // `referenceTaskIds` and `extensions` are lists of such values.
        if (is_array($item) && array_is_list($item) && array_filter($item, is_string(...)) === $item) {
            return array_map(
                static fn (string $value): string => self::isStructuralValue($key, $value) ? $value : $redactText($value),
                $item,
            );
        }

        return self::redacted($item, $redactText);
    }

    /**
     * Whether a string has the form the protocol gives the member: an id, a
     * value of the member's enum, a timestamp, a media type or an extension URI.
     */
    private static function isStructuralValue(string $key, string $value): bool
    {
        return match ($key) {
            'role'       => in_array($value, self::ROLES, true),
            'state'      => in_array($value, self::STATES, true),
            'kind'       => in_array($value, self::KINDS, true),
            'timestamp'  => preg_match(self::TIMESTAMP, $value) === 1,
            'mediaType'  => self::isMediaType($value),
            'extensions' => self::isExtensionUri($value),
            default      => preg_match(self::ID, $value) === 1,
        };
    }

    private static function isMediaType(string $value): bool
    {
        return strlen($value) <= 255 && preg_match(self::MEDIA_TYPE, $value) === 1;
    }

    /**
     * An http(s) URI that names an extension: no credentials, query or
     * fragment, and a path of plain characters.
     */
    private static function isExtensionUri(string $value): bool
    {
        return strlen($value) <= 512 && preg_match(self::EXTENSION_URI, $value) === 1;
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
            if (is_string($part['mediaType'] ?? null) && self::isMediaType($part['mediaType'])) {
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
