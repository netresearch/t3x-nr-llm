<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\FieldControl;

/**
 * The table a record select chooses from: the control option `table`, else
 * the field's `foreign_table` - the same order core's addRecord uses.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ForeignTable
{
    /**
     * @param array<array-key, mixed> $data    FormEngine node data
     * @param array<array-key, mixed> $options the control's options
     */
    public static function of(array $data, array $options): string
    {
        if (is_string($options['table'] ?? null) && $options['table'] !== '') {
            return $options['table'];
        }

        $parameterArray = is_array($data['parameterArray'] ?? null) ? $data['parameterArray'] : [];
        $fieldConf      = is_array($parameterArray['fieldConf'] ?? null) ? $parameterArray['fieldConf'] : [];
        $config         = is_array($fieldConf['config'] ?? null) ? $fieldConf['config'] : [];
        $table          = $config['foreign_table'] ?? null;

        return is_string($table) ? $table : '';
    }

    /**
     * The options FormEngine hands a field control or wizard:
     * `renderData.fieldControlOptions` or `renderData.fieldWizardOptions`.
     *
     * @param array<array-key, mixed> $data FormEngine node data
     *
     * @return array<array-key, mixed>
     */
    public static function options(array $data, string $key): array
    {
        $renderData = is_array($data['renderData'] ?? null) ? $data['renderData'] : [];

        return is_array($renderData[$key] ?? null) ? $renderData[$key] : [];
    }
}
