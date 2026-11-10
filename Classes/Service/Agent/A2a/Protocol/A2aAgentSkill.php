<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * One `AgentSkill` of a card (proto `AgentSkill`).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aAgentSkill
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public array $tags,
    ) {}
}
