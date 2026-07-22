<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * The rendering shape of a {@see \Netresearch\NrLlm\Domain\ValueObject\ToolArtifact}.
 *
 * Deliberately the SMALLEST closed set whose every case has a v1 emitter plus a
 * fallback — NOT a semantic taxonomy (no `page-tree`/`records` cases) and NOT a
 * free-form string:
 * - TABLE: {columns: list<string>, rows: list<list<string>>}. Emitted by
 *   {@see \Netresearch\NrLlm\Service\Tool\Builtin\ReadRecordsTool}.
 * - TEXT: a plain string payload {text: string}. The fallback for an unknown
 *   shape and the carrier for the fail-closed "artifacts omitted" marker.
 *
 * Additive by design: TREE, LIST, KEY_VALUE, LINK, CODE land later as a new
 * enum case plus one JS branch — no consumer migration, no persisted-data
 * migration. TREE specifically is a documented follow-up (a page-tree emitter),
 * intentionally NOT shipped in v1 so no enum case exists without a committed
 * producer.
 */
enum ArtifactType: string
{
    case TABLE = 'table';
    case TEXT  = 'text';
}
