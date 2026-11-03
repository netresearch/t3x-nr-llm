<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\FieldControl;

use TYPO3\CMS\Backend\Form\FieldControl\AddRecord;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * Core's "create new" control next to a record select, rendered only when
 * the user may create a record of that table on the target page (ADR-255).
 *
 * Registered as renderType `nrllmAddRecord`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class AccessCheckedAddRecord extends AddRecord
{
    public function __construct(
        UriBuilder $uriBuilder,
        private readonly RecordControlAccess $access,
    ) {
        parent::__construct($uriBuilder);
    }

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $options = ForeignTable::options($this->data, 'fieldControlOptions');
        $table   = ForeignTable::of($this->data, $options);
        if ($table === '') {
            return [];
        }

        // resolvePid() may answer with a marker (###PAGE_TSCONFIG_ID###) that
        // the add wizard resolves later; check the page being edited then.
        $pid          = $this->resolvePid($options, $table);
        $effectivePid = $this->data['effectivePid'] ?? 0;
        $pid          = is_numeric($pid) ? (int) $pid : (is_numeric($effectivePid) ? (int) $effectivePid : 0);

        if (!$this->access->mayCreate($table, $pid)) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = AccessibleName::add(parent::render());

        return $result;
    }
}
