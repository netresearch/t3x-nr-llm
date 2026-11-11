<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What a configuration does with the rule-set judge (ADR-288).
 *
 * OFF does not call the judge. REPORT shows what the judge found as a hint and
 * delivers the text. BLOCK stops the text like a term list does, and also stops
 * it when the judge gives no usable answer.
 *
 * @api
 */
enum RuleJudgeMode: string
{
    case OFF    = 'off';
    case REPORT = 'report';
    case BLOCK  = 'block';

    /**
     * An unknown stored value is OFF: the judge sends the output to a second
     * model, which nobody may switch on by accident.
     */
    public static function fromStored(string $value): self
    {
        return self::tryFrom($value) ?? self::OFF;
    }
}
