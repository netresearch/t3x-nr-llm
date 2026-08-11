<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Language;

use RuntimeException;
use SimpleXMLElement;

/**
 * Reads the shipped XLIFF catalogues so a test can assert that a `LLL:` key a
 * PHP declaration hands to Fluid actually resolves — in English AND in German.
 *
 * A missing key is invisible at runtime: `f:translate` renders an empty string
 * and the module simply shows nothing where the label was. The declarations
 * introduced by ADR-152 are the first ones where the key lives in PHP rather
 * than in the template beside its `default`, so nothing but a test connects the
 * two files.
 *
 * Not a TestCase — a plain helper, loaded by class name only.
 */
final class LabelCatalogue
{
    private const RESOURCES = __DIR__ . '/../../../Resources/Private/Language/';

    private const PREFIX = 'LLL:EXT:nr_llm/Resources/Private/Language/';

    /** @var array<string, array<string, string>> file => id => text */
    private static array $cache = [];

    /**
     * The English `<source>` of a full `LLL:` key, or null when the catalogue
     * does not carry the id.
     */
    public static function source(string $llKey): ?string
    {
        [$file, $id] = self::split($llKey);

        return self::unitsOf($file, 'source')[$id] ?? null;
    }

    /**
     * The German `<target>` of a full `LLL:` key, or null when the German
     * catalogue does not carry the id (or carries it untranslated).
     */
    public static function target(string $llKey): ?string
    {
        [$file, $id] = self::split($llKey);

        return self::unitsOf('de.' . $file, 'target')[$id] ?? null;
    }

    /**
     * @return array{string, string} file basename, trans-unit id
     */
    private static function split(string $llKey): array
    {
        if (!str_starts_with($llKey, self::PREFIX)) {
            throw new RuntimeException("Not a key of this extension's language files: " . $llKey, 1786406403);
        }

        $rest      = substr($llKey, strlen(self::PREFIX));
        $separator = strrpos($rest, ':');
        if ($separator === false) {
            throw new RuntimeException('Key carries no trans-unit id: ' . $llKey, 1786406404);
        }

        return [substr($rest, 0, $separator), substr($rest, $separator + 1)];
    }

    /**
     * @return array<string, string>
     */
    private static function unitsOf(string $file, string $element): array
    {
        $cacheKey = $file . '#' . $element;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $path = self::RESOURCES . $file;
        if (!is_file($path)) {
            throw new RuntimeException('No such language file: ' . $path, 1786406405);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unreadable language file: ' . $path, 1786406406);
        }

        $xml   = new SimpleXMLElement($contents);
        $units = [];
        foreach ($xml->xpath('//*[local-name()="trans-unit"]') ?? [] as $unit) {
            $id = (string)($unit['id'] ?? '');
            /** @var SimpleXMLElement|null $value */
            $value = $unit->{$element}[0] ?? null;
            if ($id !== '' && $value instanceof SimpleXMLElement) {
                $units[$id] = trim((string)$value);
            }
        }

        self::$cache[$cacheKey] = $units;

        return $units;
    }
}
