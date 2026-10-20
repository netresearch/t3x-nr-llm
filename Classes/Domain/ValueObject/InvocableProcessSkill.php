<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * One process skill an actor may start on a configuration, as the
 * slash-command menu lists it (ADR-214 item 10, ADR-228).
 *
 * The name and description are those of the approved version a start would
 * compose, not of the record, which a later edit or sync may have changed.
 * Every listed skill's current version is an approved instruction, so its
 * description is text an administrator reviewed; the consumer still escapes
 * it as text.
 *
 * @api
 */
final readonly class InvocableProcessSkill
{
    public function __construct(
        public int $uid,
        public string $identifier,
        public string $name,
        public string $description,
    ) {}

    /**
     * @return array{uid: int, identifier: string, name: string, description: string}
     */
    public function toArray(): array
    {
        return ['uid' => $this->uid, 'identifier' => $this->identifier, 'name' => $this->name, 'description' => $this->description];
    }
}
