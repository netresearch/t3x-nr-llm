<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

/**
 * A domain handler whose result is an editorial text for use outside TYPO3:
 * the run's answer is checked after the loop as it will be delivered
 * (ADR-302).
 *
 * The handler decides this on the server. A profile chooses the handler; a
 * client's message cannot switch the check off or on.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface A2aEndCheckedHandlerInterface extends A2aDomainHandlerInterface
{
    /**
     * The text the content artifact carries, from the answer as it is kept.
     * The end-of-run check judges exactly this text; the artifact carries
     * exactly this text.
     */
    public function deliverableText(string $keptAnswer): string;

    /**
     * Whether the run checks the submitted text instead of generating one:
     * no generator call, no tool call.
     */
    public function checksOnly(): bool;
}
