<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;

/**
 * Opt-in declaration of what kind of data a tool returns (ADR-094).
 *
 * A tool implements this when its group's default understates it — a tool in
 * the `system` group that returns environment variables is not merely
 * diagnostic. The value is a property of the CODE and is deliberately not
 * configurable: an administrator must not be able to relabel a tool to widen
 * the egress gate.
 *
 * Kept separate from {@see ToolInterface} for now so all 41 builtins get a
 * class without 41 edits; promoting it onto the tool contract is a later,
 * announced breaking change.
 */
interface ToolDataClassInterface
{
    public function getDataClass(): ToolDataClass;
}
