<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Hook;

use Netresearch\NrLlm\Domain\Model\Skill;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Records who disabled a skill when its enable flag changes through the
 * DataHandler (ADR-214 item 3): a disable there is an administrator's
 * decision, and a re-enable clears the mark the sync left. Runs after the
 * field checks, so it applies to exactly the enable flag that is written.
 *
 * Registered under `processDatamapClass` in `ext_localconf.php`.
 */
final class SkillDisabledByHook
{
    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        if ($table !== 'tx_nrllm_skill' || !array_key_exists('enabled', $fieldArray)) {
            return;
        }

        $enabled                   = $fieldArray['enabled'];
        $fieldArray['disabled_by'] = is_numeric($enabled) && (int)$enabled === 1 ? '' : Skill::DISABLED_BY_ADMIN;
    }
}
