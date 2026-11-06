<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\ViewHelpers\Format;

use Netresearch\NrLlm\Service\Formatting\CompactNumberFormatter;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * A large count in the backend user's locale, shortened: "17,5 Mio." for a
 * German reader, "17.5M" for an English one. Below a million it prints the
 * full number, as `nrllm:format.number` does.
 *
 * The short form drops digits, so a template shows the full value as well,
 * for hover and for screen readers:
 *
 * ```
 * <div title="{kpi.tokens -> nrllm:format.number()}">
 *     <span aria-hidden="true">{kpi.tokens -> nrllm:format.compactNumber()}</span>
 *     <span class="visually-hidden">{kpi.tokens -> nrllm:format.number()}</span>
 * </div>
 * ```
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class CompactNumberViewHelper extends AbstractViewHelper
{
    public function render(): string
    {
        $value = $this->renderChildren();

        return CompactNumberFormatter::forBackendUser()->format(is_numeric($value) ? (float) $value : 0.0);
    }
}
