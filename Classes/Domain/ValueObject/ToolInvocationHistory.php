<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * Runtime observations, held independently of the model transcript.
 *
 * An entry may carry two keyed digests (ADR-298): `arguments`, of the call's
 * final arguments, and `state`, of the record state an `ok` call observed.
 * Both are optional and omitted, never stored as null, when there is none; a
 * present one has the form {@see ObservedState::DIGEST_PATTERN}, and a stored
 * history with any other value is read as unreadable and incomplete.
 *
 * @api
 */
final readonly class ToolInvocationHistory
{
    /**
     * @param list<array{tool: string, outcome: string, target: array{kind: string, identifier: string}|null, arguments?: string, state?: string}> $entries
     */
    public function __construct(
        public array $entries = [],
        public bool $complete = true,
    ) {}

    /**
     * @param ?string $arguments the digest of the call's final arguments, null for none
     * @param ?string $state     the digest of the record state the call observed, null for none
     *
     * @throws InvalidArgumentException when a digest is not of the version 1 form
     */
    public function append(
        string $tool,
        string $outcome,
        ?ToolInvocationTarget $target,
        ?string $arguments = null,
        ?string $state = null,
    ): self {
        $entry = [
            'tool'    => $tool,
            'outcome' => $outcome,
            'target'  => $target instanceof ToolInvocationTarget ? [
                'kind'       => $target->kind,
                'identifier' => $target->identifier,
            ] : null,
        ];
        foreach (['arguments' => $arguments, 'state' => $state] as $key => $digest) {
            if ($digest === null) {
                continue;
            }

            if (!self::isDigest($digest)) {
                throw new InvalidArgumentException(sprintf('The %s digest of a history entry must be of the form v1: and 64 hex digits.', $key), 1794060002);
            }

            $entry[$key] = $digest;
        }

        return new self([...$this->entries, $entry], $this->complete);
    }

    /**
     * @return array{entries: list<array{tool: string, outcome: string, target: array{kind: string, identifier: string}|null, arguments?: string, state?: string}>, complete: bool}
     */
    public function toStored(): array
    {
        return ['entries' => $this->entries, 'complete' => $this->complete];
    }

    public static function fromStored(mixed $raw): self
    {
        if (!is_array($raw) || !is_bool($raw['complete'] ?? null) || !is_array($raw['entries'] ?? null) || !array_is_list($raw['entries'])) {
            return new self([], false);
        }

        $entries = [];
        foreach ($raw['entries'] as $rawEntry) {
            $entry = self::entryFromStored($rawEntry);
            if ($entry === null) {
                return new self([], false);
            }

            $entries[] = $entry;
        }

        return new self($entries, $raw['complete']);
    }

    /**
     * @return array{tool: string, outcome: string, target: array{kind: string, identifier: string}|null, arguments?: string, state?: string}|null
     */
    private static function entryFromStored(mixed $entry): ?array
    {
        if (!is_array($entry) || !is_string($entry['tool'] ?? null) || $entry['tool'] === '' || !in_array(
            $entry['outcome'] ?? null,
            ['ok', 'failed', 'cancelled', 'denied'],
            true,
        ) || !array_key_exists('target', $entry)) {
            return null;
        }

        $target = $entry['target'];
        if ($target !== null && (!is_array($target) || !is_string($target['kind'] ?? null) || trim($target['kind']) === '' || !is_string($target['identifier'] ?? null) || trim($target['identifier']) === '')) {
            return null;
        }

        $read = [
            'tool'    => $entry['tool'],
            'outcome' => $entry['outcome'],
            'target'  => $target === null ? null : ['kind' => $target['kind'], 'identifier' => $target['identifier']],
        ];

        // A digest is optional; a present one of any other form, an unknown
        // version included, makes the entry unreadable (ADR-298).
        foreach (['arguments', 'state'] as $key) {
            if (!array_key_exists($key, $entry)) {
                continue;
            }

            $digest = $entry[$key];
            if (!is_string($digest) || !self::isDigest($digest)) {
                return null;
            }

            $read[$key] = $digest;
        }

        return $read;
    }

    private static function isDigest(string $digest): bool
    {
        return preg_match(ObservedState::DIGEST_PATTERN, $digest) === 1;
    }
}
