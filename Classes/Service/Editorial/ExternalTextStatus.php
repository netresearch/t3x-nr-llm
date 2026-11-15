<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Editorial;

/**
 * The outcome of a check of a delivered text (ADR-300).
 *
 * `passed` means checked against at least one rule and none broken; a text
 * checked against nothing is `not_checked`, never `passed`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum ExternalTextStatus: string
{
    case PASSED      = 'passed';
    case VIOLATION   = 'violation';
    case NOT_CHECKED = 'not_checked';
    case FAILED      = 'failed';
}
