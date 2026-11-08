<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

/**
 * A DataHandler hook that strips `assets` from every incoming `tt_content`
 * row, before the DataHandler evaluates it.
 *
 * It stands in for whatever drops the element's side of a file relation in an
 * installation without an error, so a test of `replace_file_reference`'s
 * read-back can make it fail: the element then keeps its old list and
 * counter while the reference rows change. The take-back in a draft
 * workspace discards the element's version through a cmdmap of its own, which
 * this hook does not touch.
 *
 * Registered per test under
 * `$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']`
 * and removed again afterwards. Stateless.
 */
final class DropsTheElementFileFieldHook
{
    /**
     * @param array<string, mixed> $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(array &$incomingFieldArray, string $table): void
    {
        if ($table !== 'tt_content') {
            return;
        }

        unset($incomingFieldArray['assets']);
    }
}
