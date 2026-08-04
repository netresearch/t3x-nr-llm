<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * Marks a tool whose behaviour lives outside this codebase.
 *
 * A builtin's data class, effect and permission handling are properties of code
 * we own, read and test. A remote tool's are not: what it returns, what it
 * changes and whom it answers to are decided by a server we only talk to.
 *
 * Three rules follow, and each is enforced where it belongs rather than left to
 * the implementation's good behaviour:
 *
 * - The data class comes from an operator declaration, because there is no code
 *   to derive it from. {@see ToolDataClassInterface} says the class is "a
 *   property of the CODE and deliberately not configurable"; that holds while
 *   code is the better source, which for a remote tool it is not.
 * - The trust-zone ceiling is enforced even where an install runs the gate in
 *   observe mode. Observe exists so an UPGRADE does not silently start dropping
 *   tools that already worked (ADR-115). No remote tool worked before, so there
 *   is nothing to preserve, and an upgraded install must not end up more
 *   permissive than a fresh one.
 * - The effect is a write unless the operator states otherwise — the inverse of
 *   the builtin default, which is read-only because every builtin reads.
 *
 * The marker carries no methods: it is a statement about provenance, and every
 * consumer asks it as such.
 */
interface RemoteToolInterface {}
