<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\ViewHelpers\Format;

use Netresearch\NrLlm\Service\Formatting\LocalizedNumberFormatter;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * A count or a decimal in the backend user's locale (NEXT-170): "5.138.706"
 * for a German reader, "5,138,706" for an English one.
 *
 * `f:format.number` always prints `.` and `,`, whatever the reader's language.
 *
 * ```
 * {kpi.tokens -> nrllm:format.number()}
 * {p.score -> nrllm:format.number(decimals: 2)}
 * ```
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class NumberViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('decimals', 'int', 'Digits after the decimal separator', false, 0);
    }

    public function render(): string
    {
        $value = $this->renderChildren();

        return LocalizedNumberFormatter::forBackendUser()->number(
            is_numeric($value) ? (float) $value : 0.0,
            is_numeric($this->arguments['decimals']) ? (int) $this->arguments['decimals'] : 0,
        );
    }
}
