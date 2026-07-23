<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;

/**
 * Opt-in declaration of a tool's side effect (ADR-111).
 *
 * A tool implements this only when it writes: the runtime treats any tool that
 * does NOT implement it as {@see ToolEffect::READ_ONLY} (fail-closed default),
 * which is correct for every builtin shipped today. A write tool MUST declare
 * itself so the at-least-once queue can guarantee a durable audit step for it
 * and withhold auto-retry from a non-idempotent one.
 *
 * The value is a property of the CODE and is deliberately not configurable: an
 * administrator must not be able to relabel a write as a read to dodge the
 * audit and retry guarantees. Kept separate from {@see ToolInterface} — like
 * {@see ToolDataClassInterface} — so the read-only builtins need no change and
 * promoting it onto the tool contract stays a later, announced breaking change.
 */
interface ToolEffectInterface
{
    public function getEffect(): ToolEffect;
}
