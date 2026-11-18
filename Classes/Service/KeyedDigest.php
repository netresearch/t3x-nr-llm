<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * The keyed digest of ADR-298, apart from what it is a digest of: `v1:` and
 * the hex HMAC-SHA256 of a value's canonical form, keyed with the
 * installation's encryption key joined with a purpose string. The canonical
 * form is documented on {@see Tool\InvocationDigests}; a run's content digests
 * (ADR-323) use it with a purpose of their own, so a digest of one purpose
 * never equals one of another. An empty encryption key gives no digest, never
 * an unkeyed one.
 *
 * @internal
 */
final class KeyedDigest
{
    private const VERSION = 'v1:';

    /** Floats without a fraction below this magnitude are written as integers. */
    private const EXACT_INTEGER_LIMIT = 9007199254740992.0;

    /** The deepest nesting JSON encodes (json_encode's default depth). */
    private const MAX_DEPTH = 512;

    /**
     * The `v1:` digest of a value for a purpose, or null when the value has no
     * canonical form or the installation has no encryption key.
     */
    public static function of(string $purpose, mixed $value): ?string
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $system        = is_array($configuration) && is_array($configuration['SYS'] ?? null) ? $configuration['SYS'] : [];
        $key           = $system['encryptionKey'] ?? '';
        if (!is_string($key) || $key === '') {
            return null;
        }

        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            $json = json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        } finally {
            ini_set('serialize_precision', $precision === false ? '-1' : $precision);
        }

        return self::VERSION . hash_hmac('sha256', $json, $key . $purpose);
    }

    /**
     * The value in canonical form, version 1 (see the class docblock).
     *
     * @throws InvalidArgumentException for a value that has no canonical form
     */
    private static function canonical(mixed $value, int $depth = 0): mixed
    {
        // JSON nests 512 levels at most; deeper, or cyclic, has no canonical
        // form and gets no digest instead of recursing without end.
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('A value nested this deep has no canonical form.', 1794060005);
        }

        if (is_float($value)) {
            return is_finite($value) && floor($value) === $value && abs($value) < self::EXACT_INTEGER_LIMIT ? (int) $value : $value;
        }

        if (is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('A value of this type has no canonical form.', 1794060003);
        }

        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $items  = [];
        foreach ($value as $key => $item) {
            $items[$key] = self::canonical($item, $depth + 1);
        }

        if ($isList) {
            return $items;
        }

        ksort($items, SORT_STRING);
        $object = new stdClass();
        foreach ($items as $key => $item) {
            // A property name cannot start with NUL; such a key has no
            // canonical form here, so the value gets no digest.
            if (str_starts_with((string) $key, "\0")) {
                throw new InvalidArgumentException('A key starting with NUL has no canonical form.', 1794060004);
            }

            $object->{(string) $key} = $item;
        }

        return $object;
    }
}
