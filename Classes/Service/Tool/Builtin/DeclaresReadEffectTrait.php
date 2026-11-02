<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;

/**
 * The statement of a tool that changes nothing (ADR-111, ADR-238).
 *
 * The retry and audit guards read a missing declaration as a read. A term list
 * cannot: what a third-party tool without a declaration does with its arguments
 * is unknown, so there it counts as writing. A tool that only reads says so.
 */
trait DeclaresReadEffectTrait
{
    public function getEffect(): ToolEffect
    {
        return ToolEffect::READ_ONLY;
    }
}
