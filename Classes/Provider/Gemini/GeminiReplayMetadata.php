<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Gemini;

use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;

/** Provider-owned generateContent replay on the existing carrier (ADR-222).
 * @internal
 */
final class GeminiReplayMetadata
{
    public const TYPE = 'nrllm_gemini_generate_content';

    private const INVALID_MESSAGE = 'Gemini replay state is malformed or ambiguous.';

    /**
     * Foreign untagged OpenAI items retain their existing meaning.
     * A recognized Gemini capsule must occupy its whole turn.
     *
     * @param array<array-key, mixed>|null $items
     *
     * @return list<array<string, mixed>>|null
     */
    public static function nativeParts(?array $items): ?array
    {
        $owned = null;
        foreach ($items ?? [] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === self::TYPE) {
                $owned = $item;
            }
        }

        if ($owned === null) {
            return null;
        }

        $parts = $owned['parts'] ?? null;
        if (count($items ?? []) !== 1 || !is_array($parts) || !array_is_list($parts)) {
            throw new ProviderConfigurationException(
                self::INVALID_MESSAGE,
                1791619001,
            );
        }

        foreach ($parts as $part) {
            if (!is_array($part)) {
                throw new ProviderConfigurationException(
                    self::INVALID_MESSAGE,
                    1791619001,
                );
            }
        }

        self::bindingEntries($owned);

        /** @var list<array<string, mixed>> $parts */
        return $parts;
    }

    /**
     * @param array<int, mixed> $parts
     */
    public static function hasSignature(array $parts): bool
    {
        foreach ($parts as $part) {
            if (is_array($part) && array_key_exists('thoughtSignature', $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep valid native arrays in their original iteration order.
     * Malformed non-array parts retain the response parser's skip behavior.
     *
     * @param array<int, mixed>                                 $parts
     * @param list<array{tool_call_id:string,native_id:string}> $bindings
     *
     * @return list<array<string, mixed>>
     */
    public static function itemsFromParts(
        array $parts,
        array $bindings = [],
    ): array {
        /** @var list<array<string, mixed>> $nativeParts */
        $nativeParts = array_values(array_filter($parts, is_array(...)));
        $capsule     = ['type' => self::TYPE, 'parts' => $nativeParts];
        if ($bindings !== []) {
            $capsule['tool_call_bindings'] = $bindings;
        }

        return [$capsule];
    }

    /**
     * An absent ID is the legacy protocol; present invalid IDs are refused.
     * Called only after the existing parser accepts a function name.
     *
     * @param array<array-key,mixed> $function
     */
    public static function nativeId(array $function): ?string
    {
        if (!array_key_exists('id', $function)) {
            return null;
        }

        $id = $function['id'];
        if (!is_string($id) || $id === '') {
            throw self::invalid();
        }

        return $id;
    }

    /**
     * Resolve only this turn's owned associations. Foreign items cannot bind.
     *
     * @param array<array-key,mixed>|null $items
     * @param list<array<string,mixed>>   $genericCalls
     *
     * @return array<string,string>
     */
    public static function nativeIdsForCalls(
        ?array $items,
        array $genericCalls,
    ): array {
        $parts = self::nativeParts($items);
        if ($parts === null) {
            return [];
        }

        $capsule = array_values($items ?? [])[0];
        if (!is_array($capsule)) {
            throw self::invalid();
        }

        $bindings = self::bindingEntries($capsule);
        if ($genericCalls === []) {
            if ($bindings !== null && $bindings !== []) {
                throw self::invalid();
            }

            return [];
        }

        $nativeCalls = [];
        $populated   = [];
        foreach ($parts as $part) {
            $function = $part['functionCall'] ?? null;
            if (!is_array($function)) {
                continue;
            }

            $name = self::stringValue($function['name'] ?? null);
            if ($name === '') {
                continue;
            }

            $id   = self::nativeId($function);
            $call = [
                'name' => $name,
                'args' => is_array($function['args'] ?? null) ? $function['args'] : [],
            ];
            $nativeCalls[] = $call;
            if ($id !== null) {
                if (array_key_exists($id, $populated)) {
                    throw self::invalid();
                }

                $populated[$id] = $call + ['nativeId' => $id];
            }
        }

        if ($populated === []) {
            if ($bindings !== null && $bindings !== []) {
                throw self::invalid();
            }

            return [];
        }

        $generic = [];
        foreach ($genericCalls as $call) {
            $function  = is_array($call['function'] ?? null) ? $call['function'] : [];
            $arguments = $function['arguments'] ?? [];
            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true);
            }

            $generic[] = [
                'id'   => self::stringValue($call['id'] ?? null),
                'name' => self::stringValue($function['name'] ?? null),
                'args' => is_array($arguments) ? $arguments : [],
            ];
        }

        $resolved = [];
        if ($bindings !== null) {
            if (count($bindings) !== count($populated)) {
                throw self::invalid();
            }

            foreach ($bindings as $binding) {
                $native  = $populated[$binding['native_id']] ?? null;
                $matches = array_values(
                    array_filter(
                        $generic,
                        static fn (
                            array $call,
                        ): bool => $call['id'] === $binding['tool_call_id'],
                    ),
                );
                if ($native === null || count($matches) !== 1 || !self::sameCall($native, $matches[0])) {
                    throw self::invalid();
                }

                $resolved[$binding['tool_call_id']] = $binding['native_id'];
            }

            return $resolved;
        }

        // Legacy recovery needs a unique decoded signature on both sides.
        foreach ($populated as $native) {
            $genericMatches = array_values(
                array_filter(
                    $generic,
                    static fn (
                        array $call,
                    ): bool => self::sameCall($native, $call),
                ),
            );
            $nativeMatches = array_filter(
                $nativeCalls,
                static fn (array $call): bool => self::sameCall($native, $call),
            );
            if (count($genericMatches) !== 1 || count($nativeMatches) !== 1 || $genericMatches[0]['id'] === '') {
                throw self::invalid();
            }

            $publicId = $genericMatches[0]['id'];
            if (array_key_exists($publicId, $resolved)) {
                throw self::invalid();
            }

            $resolved[$publicId] = $native['nativeId'];
        }

        return $resolved;
    }

    /**
     * Present malformed fields are never treated as absent legacy metadata.
     *
     * @param array<array-key,mixed> $capsule
     *
     * @return list<array{tool_call_id:string,native_id:string}>|null
     */
    private static function bindingEntries(array $capsule): ?array
    {
        if (!array_key_exists('tool_call_bindings', $capsule)) {
            return null;
        }

        $entries = $capsule['tool_call_bindings'];
        if (!is_array($entries) || !array_is_list($entries)) {
            throw self::invalid();
        }

        $public    = [];
        $native    = [];
        $validated = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || count($entry) !== 2 || !is_string($entry['tool_call_id'] ?? null) || $entry['tool_call_id'] === '' || !is_string($entry['native_id'] ?? null) || $entry['native_id'] === '') {
                throw self::invalid();
            }

            if (isset($public[$entry['tool_call_id']]) || isset($native[$entry['native_id']])) {
                throw self::invalid();
            }

            $public[$entry['tool_call_id']] = true;
            $native[$entry['native_id']]    = true;
            $validated[]                    = [
                'tool_call_id' => $entry['tool_call_id'],
                'native_id'    => $entry['native_id'],
            ];
        }

        return $validated;
    }

    /** @param array{name:string,args:array<array-key,mixed>} $left
     * @param array{name:string,args:array<array-key,mixed>} $right */
    private static function sameCall(array $left, array $right): bool
    {
        return $left['name'] === $right['name'] && self::sameDecodedValue($left['args'], $right['args']);
    }

    /**
     * Compare decoded maps strictly except reversible JSON-number representation.
     */
    private static function sameDecodedValue(mixed $left, mixed $right): bool
    {
        if (is_int($left) && is_float($right)) {
            return self::sameNumericValue($left, $right);
        }

        if (is_float($left) && is_int($right)) {
            return self::sameNumericValue($right, $left);
        }

        if (!is_array($left) || !is_array($right)) {
            return $left === $right;
        }

        if (count($left) !== count($right)) {
            return false;
        }

        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right) || !self::sameDecodedValue($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    private static function invalid(): ProviderConfigurationException
    {
        return new ProviderConfigurationException(self::INVALID_MESSAGE, 1791619001);
    }

    /**
     * Neither conversion may round to a different integer.
     */
    private static function sameNumericValue(int $integer, float $float): bool
    {
        return is_finite($float) && (float) $integer === $float && (int) $float === $integer;
    }
}
