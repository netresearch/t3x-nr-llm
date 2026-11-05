<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The skill-derived tool allow-list of one run (ADR-038 item 5).
 *
 * The union of the `allowed-tools` declarations of every effective skill the
 * run carries — the configuration's attachments and the run's forced skills —
 * resolved once when the run starts and stored with it, so a resume can hold
 * the run to it (ADR-165 re-gates a resumed run).
 *
 * `$toolNames` null means no effective skill declares anything: the skill axis
 * imposes no restriction. A list, including `[]`, is the declared union. The
 * object itself stands for "this run resolved its list"; a caller with no run
 * passes no object at all, which is why "unrestricted" cannot be spelled as a
 * missing argument.
 *
 * The configuration's `allowed_tool_groups` gate is not part of this list. It
 * is a gate of its own and is applied live on top, like the other gates.
 *
 * `$processBound` marks the list of a run that invokes a process skill (holds
 * a process pin). In such a run the skill load tool gets no exemption: it is
 * offered only where the vouched declaration lists it (ADR-214 item 5).
 *
 * `$excludedToolNames` are the tools the configuration excluded when the run
 * started (ADR-261). They ride this list because it is what a run stores and
 * a resume intersects: the gate refuses them on top of the configuration's
 * live exclusions, so one removed while the run waits does not give the tool
 * back. They do not restrict `$toolNames`, which stays the skill axis alone.
 *
 * @api
 */
final readonly class SkillToolAllowList
{
    /**
     * @param list<string>|null $toolNames         null = no effective skill declares (no skill-imposed restriction)
     * @param bool              $processBound      the run invokes a process skill; the load tool is then held to the list too
     * @param list<string>      $excludedToolNames the tools the configuration excluded at run start (ADR-261)
     */
    public function __construct(
        public ?array $toolNames,
        public bool $processBound = false,
        public array $excludedToolNames = [],
    ) {}

    /**
     * This list with one more skill declaration in its union (ADR-038 item 5):
     * what the list would be had that skill been among those it was resolved
     * over.
     *
     * A null list held no declaration, so the result is the declaration
     * itself: a declaring skill restricts the run to what it declares. Used
     * for a process pin, whose declaration comes from its approval snapshot
     * rather than from a record the resolver reads (ADR-214 item 5, ADR-228),
     * so the result is process-bound: the run holds a process.
     *
     * @param list<string> $toolNames
     */
    public function withDeclaration(array $toolNames): self
    {
        return new self(array_values(array_unique([...$this->toolNames ?? [], ...$toolNames])), true, $this->excludedToolNames);
    }

    /**
     * This list held to an upper bound: the names both lists admit.
     *
     * A null side imposes nothing, so `null ∩ X = X`. The result can therefore
     * never admit a tool this list did not admit, which is what a resume needs —
     * a live list that resolves to null because its only declaring skill was
     * disabled while the run waited must not widen the run to every tool.
     * Either side's process binding holds for the result, and so does either
     * side's exclusion (ADR-261).
     */
    public function intersect(self $other): self
    {
        $processBound = $this->processBound || $other->processBound;
        $excluded     = array_values(array_unique([...$this->excludedToolNames, ...$other->excludedToolNames]));
        if ($this->toolNames === null) {
            return new self($other->toolNames, $processBound, $excluded);
        }

        if ($other->toolNames === null) {
            return new self($this->toolNames, $processBound, $excluded);
        }

        return new self(array_values(array_intersect($this->toolNames, $other->toolNames)), $processBound, $excluded);
    }

    /**
     * The stored form: wrapped, so "no restriction" (`toolNames` null) stays
     * distinguishable from "no list recorded" (no value at all) after JSON.
     *
     * The process binding and the exclusions are written only when set, so
     * the stored form of every other run is unchanged.
     *
     * @return array{toolNames: list<string>|null, processBound?: true, excludedToolNames?: list<string>}
     */
    public function toStored(): array
    {
        $stored = ['toolNames' => $this->toolNames];
        if ($this->processBound) {
            $stored['processBound'] = true;
        }

        if ($this->excludedToolNames !== []) {
            $stored['excludedToolNames'] = $this->excludedToolNames;
        }

        return $stored;
    }

    /**
     * A list read back from {@see self::toStored()}, or null when none was
     * recorded.
     *
     * A value that is not an array is a record written before the list was
     * stored: null, and the caller resolves the live list as it did then. A
     * recorded list keeps `[]` (no tools) apart from null (no restriction) —
     * collapsing `[]` to null would widen a run that may call nothing to every
     * tool. A recorded value whose `toolNames` is neither a list nor null
     * degrades to `[]`, the narrow side: the field is written only by this
     * extension, so a malformed one was tampered with or truncated and must not
     * grant. A recorded `processBound` that is not `false` keeps the binding,
     * the narrow side; a record written before the field existed has none.
     * Recorded exclusions keep their string entries; a value that is not a
     * list cannot say which tools it excluded, so the result admits no tool
     * at all, the skill load tool included: it is marked process-bound, which
     * removes that tool's exemption. A record written before exclusions were
     * stored carries none.
     */
    public static function fromStored(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        $processBound = array_key_exists('processBound', $raw) && $raw['processBound'] !== false;
        // Absent means a record from before the field; present, it must be a
        // list. An explicit null or a map was not written by toStored().
        $excluded = array_key_exists('excludedToolNames', $raw) ? $raw['excludedToolNames'] : [];
        if (!is_array($excluded) || !array_is_list($excluded)) {
            // Bound as well, so not even the skill load tool keeps its
            // exemption (ADR-261).
            return new self([], true);
        }

        $excluded = array_values(array_filter($excluded, is_string(...)));
        if (array_key_exists('toolNames', $raw) && $raw['toolNames'] === null) {
            return new self(null, $processBound, $excluded);
        }

        $names = $raw['toolNames'] ?? null;
        if (!is_array($names)) {
            return new self([], $processBound, $excluded);
        }

        return new self(array_values(array_filter($names, is_string(...))), $processBound, $excluded);
    }
}
