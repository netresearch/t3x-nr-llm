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
 * An amount in USD in the backend user's locale (NEXT-170): "11,40 $" for a
 * German reader, "$11.40" for an English one. Prints the dollar sign itself;
 * the template adds only the "~" of an estimate.
 *
 * ```
 * ~{kpi.cost -> nrllm:format.usd()}
 * ~{estimate.costLow -> nrllm:format.usd(decimals: 4)}
 * ```
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class UsdViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('decimals', 'int', 'Digits after the decimal separator', false, 2);
    }

    public function render(): string
    {
        $value = $this->renderChildren();

        return LocalizedNumberFormatter::forBackendUser()->usd(
            is_numeric($value) ? (float) $value : 0.0,
            is_numeric($this->arguments['decimals']) ? (int) $this->arguments['decimals'] : 2,
        );
    }
}
