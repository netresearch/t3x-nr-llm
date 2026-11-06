<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What an error hint may send to a configuration's provider (ADR-258): the
 * data-class ladder of the configuration's trust zone, read once, so the
 * hint itself and the module's list of configurations give the same answer.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum ExceptionHintAdmission: string
{
    /**
     * Class, component, frames and the masked message.
     */
    case FULL = 'full';

    /**
     * Class, component and frames; the message is withheld.
     */
    case WITHOUT_MESSAGE = 'without_message';

    /**
     * Nothing: no model is asked.
     */
    case NONE = 'none';
}
