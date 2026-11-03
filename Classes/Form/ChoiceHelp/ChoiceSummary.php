<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\ChoiceHelp;

/**
 * What the text under a record select says about one option (ADR-255).
 *
 * Both parts are plain text as stored; the renderer escapes them.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ChoiceSummary
{
    /**
     * @param list<string> $facts
     */
    public function __construct(
        public string $description,
        public array $facts,
    ) {}
}
