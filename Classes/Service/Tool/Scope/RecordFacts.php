<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;

/**
 * What the scope check needs to know about one stored row (ADR-295).
 *
 * @internal
 */
final readonly class RecordFacts
{
    /**
     * @param int                  $liveUid       the uid of the live row; a workspace version is its live row
     * @param int                  $pid           the page the row is on, or for a page its parent
     * @param int                  $language      sys_language_uid of the row, 0 where the table has none
     * @param int|null             $translationOf the default-language row this row translates
     * @param RecordReference|null $owner         the record a row belongs to outside the page tree (file metadata: its file)
     */
    public function __construct(
        public int $liveUid,
        public int $pid,
        public int $language = 0,
        public ?int $translationOf = null,
        public ?RecordReference $owner = null,
    ) {}
}
