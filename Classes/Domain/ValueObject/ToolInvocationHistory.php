<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * Runtime observations, held independently of the model transcript.
 *
 * @api
 */
final readonly class ToolInvocationHistory
{
    /**
     * @param list<array{tool: string, outcome: string, target: array{kind: string, identifier: string}|null}> $entries
     */
    public function __construct(
        public array $entries = [],
        public bool $complete = true,
    ) {}

    public function append(
        string $tool,
        string $outcome,
        ?ToolInvocationTarget $target,
    ): self {
        return new self(
            [
                ...$this->entries,
                [
                    'tool' => $tool,
                    'outcome' => $outcome,
                    'target' => $target instanceof ToolInvocationTarget ? [
                        'kind' => $target->kind,
                        'identifier' => $target->identifier,
                    ] : null,
                ],
            ],
            $this->complete,
        );
    }

    /**
     * @return array{entries: list<array{tool: string, outcome: string, target: array{kind: string, identifier: string}|null}>, complete: bool}
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
     * @return array{tool: string, outcome: string, target: array{kind: string, identifier: string}|null}|null
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

        return [
            'tool' => $entry['tool'],
            'outcome' => $entry['outcome'],
            'target' => $target === null ? null : ['kind' => $target['kind'], 'identifier' => $target['identifier']],
        ];
    }
}
