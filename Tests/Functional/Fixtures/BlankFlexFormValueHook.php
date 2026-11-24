<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Fixtures;

/**
 * A DataHandler hook that takes the submitted `opened` value out of a
 * FlexForm without a word (ADR-326).
 */
final class BlankFlexFormValueHook
{
    /**
     * @param array<string, mixed> $fields
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, array &$fields): void
    {
        if ($table === 'tt_content' && is_string($fields['pi_flexform'] ?? null)) {
            $fields['pi_flexform'] = str_replace('>taken away<', '><', $fields['pi_flexform']);
        }
    }
}
