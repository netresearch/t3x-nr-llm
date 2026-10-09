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
 * @api
 */
final readonly class SkillToolAllowList
{
    /**
     * @param list<string>|null $toolNames null = no effective skill declares (no skill-imposed restriction)
     */
    public function __construct(
        public ?array $toolNames,
    ) {}

    /**
     * This list held to an upper bound: the names both lists admit.
     *
     * A null side imposes nothing, so `null ∩ X = X`. The result can therefore
     * never admit a tool this list did not admit, which is what a resume needs —
     * a live list that resolves to null because its only declaring skill was
     * disabled while the run waited must not widen the run to every tool.
     */
    public function intersect(self $other): self
    {
        if ($this->toolNames === null) {
            return $other;
        }

        if ($other->toolNames === null) {
            return $this;
        }

        return new self(array_values(array_intersect($this->toolNames, $other->toolNames)));
    }

    /**
     * The stored form: wrapped, so "no restriction" (`toolNames` null) stays
     * distinguishable from "no list recorded" (no value at all) after JSON.
     *
     * @return array{toolNames: list<string>|null}
     */
    public function toStored(): array
    {
        return ['toolNames' => $this->toolNames];
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
     * grant.
     */
    public static function fromStored(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }

        if (array_key_exists('toolNames', $raw) && $raw['toolNames'] === null) {
            return new self(null);
        }

        $names = $raw['toolNames'] ?? null;
        if (!is_array($names)) {
            return new self([]);
        }

        return new self(array_values(array_filter($names, is_string(...))));
    }
}
