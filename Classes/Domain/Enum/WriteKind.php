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
 * is a non-idempotent CREATED, and `update_page_metadata` is an idempotent
 * UPDATED. Neither enum can be derived from the other.
 *
 * The third case arrived with the first deleting writer, `delete_record`
 * (ADR-198), as this docblock said it would: a value nothing emits is a value
 * nothing can be tested against.
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

    /**
     * The record existed and this call deleted it, and its uid names what was
     * removed. In live the table declares a `delete` column, so the row is
     * still there, flagged `deleted = 1`. In a draft workspace the live row
     * is untouched until the workspace is published: the deletion is a delete
     * placeholder there, and a record that existed only in that workspace is
     * discarded — its row is gone, and the uid names nothing any more
     * (ADR-274).
     */
    case DELETED = 'deleted';

    /**
     * The call wrote no AI text: the record was moved, published or copied, an
     * update changed only settings such as a layout, or a translation draft
     * still holds the source text (ADR-239).
     */
    case TEXT_UNCHANGED = 'text_unchanged';
}
