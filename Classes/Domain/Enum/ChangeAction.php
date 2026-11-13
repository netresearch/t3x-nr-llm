<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What a DataHandler run did to one record during a tool call (ADR-292).
 *
 * The vocabulary of `sys_history`, reduced to what a reader of a change list
 * needs. A record that was created and then updated in the same call is
 * CREATED: the list states the first fact about it, not every step.
 *
 * @api
 */
enum ChangeAction: string
{
    case CREATED  = 'created';
    case UPDATED  = 'updated';
    case MOVED    = 'moved';
    case DELETED  = 'deleted';
    case RESTORED = 'restored';
}
