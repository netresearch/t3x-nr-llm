<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Whether an AI write brought a record into being or changed an existing one
 * (ADR-187).
 *
 * Deliberately not {@see ToolEffect}, which answers a different question. That
 * enum classifies a write by whether repeating it is safe, because its reader
 * is the at-least-once queue (ADR-111). This one classifies a write by what it
 * did to the record, because its reader is a consumer deciding what to say
 * about that record — a label, an audit line, a badge. The two axes cross:
 * `create_page_draft` is a non-idempotent CREATED, `attach_file_to_content_element`
 * is a non-idempotent CREATED, and `move_content_element` is an idempotent
 * UPDATED. Neither enum can be derived from the other.
 *
 * Two cases and no third: a deletion would need one, and no builtin deletes.
 * The case is added when the first deleting writer exists, not before — a value
 * nothing emits is a value nothing can be tested against.
 *
 * @api
 */
enum WriteKind: string
{
    /**
     * The record did not exist before this call. Its uid was minted by the
     * write.
     */
    case CREATED = 'created';

    /**
     * The record existed and this call changed it. Its uid was supplied to the
     * write, not produced by it.
     */
    case UPDATED = 'updated';
}
