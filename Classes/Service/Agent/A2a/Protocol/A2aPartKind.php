<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * The member of the `Part.content` oneof, by its JSON name.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum A2aPartKind: string
{
    case TEXT = 'text';
    case RAW  = 'raw';
    case URL  = 'url';
    case DATA = 'data';
}
