<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Journal;

/**
 * What {@see A2aSendJournal::begin()} found: the intent, and whether this call
 * created it. Only a caller that created the intent may send it (A2A-C5).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSendBegin
{
    public function __construct(
        public A2aSendIntent $intent,
        public bool $created,
    ) {}
}
