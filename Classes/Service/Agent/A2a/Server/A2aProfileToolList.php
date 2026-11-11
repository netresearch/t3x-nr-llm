<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;

/**
 * The tools a run of a publication profile may use (spec 051 A2A-SV4,
 * A2A-MT5): all of the profile's tools for a writing profile, otherwise
 * those that declare that they only read. A tool that declares no effect is
 * left out: the effect resolver reads it as a read for its retry guards, but
 * it may write (ADR-238). The one definition the acceptance grants from and
 * every resume checks against (A2A-MT4).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aProfileToolList
{
    public function __construct(
        private ToolEffectResolver $effects,
    ) {}

    /**
     * @return list<string>
     */
    public function allowedFor(A2aSkillProfile $profile): array
    {
        return $profile->mayWrite
            ? array_values($profile->tools)
            : array_values(array_filter($profile->tools, $this->effects->declaresRead(...)));
    }
}
