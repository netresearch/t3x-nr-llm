<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ChangeCoverage;

/**
 * The records the DataHandler runs of one tool call wrote, as far as the
 * capture saw them (ADR-292).
 *
 * Bounded: at most {@see self::LIMIT} changes are kept, and `omitted` counts
 * the rest, so a call that copies a large page tree cannot flood the run's
 * event stream. A consumer that needs every row reads `sys_history`.
 *
 * The set states its coverage and never completeness: see
 * {@see ChangeCoverage}. An empty set is no statement that nothing was written.
 *
 * @api
 */
final readonly class ObservedChangeSet
{
    public const LIMIT = 50;

    /** @var list<ObservedChange> */
    public array $changes;

    /** What was left out: the `$omitted` argument and every change past the limit. */
    public int $omitted;

    /** @var list<ScopeRefusal> the writes the scope of the assignment refused in this call (ADR-295), at most {@see self::LIMIT} */
    public array $refused;

    /**
     * @param list<ObservedChange> $changes
     * @param list<ScopeRefusal>   $refused the writes the scope of the assignment refused (ADR-295)
     */
    public function __construct(
        array $changes = [],
        public ChangeCoverage $coverage = ChangeCoverage::DATAHANDLER,
        int $omitted = 0,
        array $refused = [],
    ) {
        $this->refused = array_slice(array_values(array_filter($refused, static fn (mixed $refusal): bool => $refusal instanceof ScopeRefusal)), 0, self::LIMIT);
        $this->changes = array_slice($changes, 0, self::LIMIT);
        $this->omitted = max(0, $omitted) + max(0, count($changes) - self::LIMIT);
    }

    /**
     * @return array{changes: list<array<string, mixed>>, changeCount: int, changeCoverage: string, changesOmitted: int}
     */
    public function toArray(): array
    {
        return [
            'changes'        => array_map(static fn (ObservedChange $change): array => $change->toArray(), $this->changes),
            'changeCount'    => count($this->changes),
            'changeCoverage' => $this->coverage->value,
            'changesOmitted' => $this->omitted,
        ] + ($this->refused === [] ? [] : ['refused' => array_map(static fn (ScopeRefusal $refusal): array => $refusal->toArray(), $this->refused)]);
    }

    /**
     * The set a persisted `tool_changes` event payload describes, or null when
     * the payload carries none. The way for a consumer of
     * {@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface::events()}
     * to read the list without parsing the array itself; an unreadable entry
     * is skipped and the rest kept, and an unknown coverage reads as
     * {@see ChangeCoverage::NOT_CAPTURED}.
     *
     * @param array<mixed> $payload
     */
    public static function tryFromEventPayload(array $payload): ?self
    {
        if (!is_array($payload['changes'] ?? null)) {
            return null;
        }

        $changes = [];
        foreach ($payload['changes'] as $entry) {
            $change = is_array($entry) ? ObservedChange::tryFromArray($entry) : null;
            if ($change instanceof ObservedChange) {
                $changes[] = $change;
            }
        }

        $coverage = is_string($payload['changeCoverage'] ?? null) ? ChangeCoverage::tryFrom($payload['changeCoverage']) : null;

        $refused = [];
        foreach (is_array($payload['refused'] ?? null) ? $payload['refused'] : [] as $entry) {
            $refusal = is_array($entry) ? ScopeRefusal::tryFromArray($entry) : null;
            if ($refusal instanceof ScopeRefusal) {
                $refused[] = $refusal;
            }
        }

        return new self(
            $changes,
            $coverage ?? ChangeCoverage::NOT_CAPTURED,
            is_int($payload['changesOmitted'] ?? null) ? max(0, $payload['changesOmitted']) : 0,
            $refused,
        );
    }
}
