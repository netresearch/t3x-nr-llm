<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Fixtures;

/**
 * A DataHandler hook that blanks the subheader of every new
 * `tx_childrenfixture_item` row without a word, as a foreign hook may: the
 * case the read-back of a draft's child records exists for (ADR-324).
 */
final class BlankChildSubheaderHook
{
    /**
     * @param array<string, mixed> $fields
     */
    public function processDatamap_preProcessFieldArray(array &$fields, string $table): void
    {
        if ($table === 'tx_childrenfixture_item' && array_key_exists('subheader', $fields)) {
            $fields['subheader'] = '';
        }
    }
}
