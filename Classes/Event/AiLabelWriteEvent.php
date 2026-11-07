<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Event;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Context for the optional AI Label bridge, never persisted or serialized.
 *
 * Separate from the public provenance event: its contract has no user or
 * generation claim. A known content-creation operation may make that claim;
 * a record merely having been created cannot.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class AiLabelWriteEvent
{
    public function __construct(
        public AfterAiRecordWrittenEvent $write,
        public BackendUserAuthentication $user,
        public bool $generated = false,
    ) {}
}
