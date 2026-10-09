<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Hook;

use Netresearch\NrLlm\Domain\Model\Skill;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;

/**
 * Who may enable or disable a skill through the DataHandler, and the mark
 * that records it (ADR-214 item 3).
 *
 * Only an administrator changes the enable flag here. A disable is then the
 * administrator's decision and is marked 'admin', so the skill drops out of
 * the runs it is attached to; an enable clears the mark, including the
 * 'sync' mark only a re-enable may clear. Anyone else — an editor with the
 * field granted, and a write without an interactive backend user (the CLI
 * and the scheduler run as `_cli_`) — has the flag removed from the write
 * and is told why: otherwise toggling a skill the sync disabled would turn
 * the sync's mark into an administrator's and lift the restriction. The
 * mark itself is never written through the DataHandler; the sync writes it
 * through the repository, which does not pass here.
 *
 * Runs after the field checks, on the field array that is about to be
 * written. Registered under `processDatamapClass` in `ext_localconf.php`.
 */
final class SkillDisabledByHook
{
    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        if ($table !== 'tx_nrllm_skill') {
            return;
        }

        unset($fieldArray['disabled_by']);
        if (!array_key_exists('enabled', $fieldArray)) {
            return;
        }

        if (!$this->isInteractiveAdmin($dataHandler->BE_USER)) {
            unset($fieldArray['enabled']);
            $dataHandler->log(
                $table,
                is_numeric($id) ? (int)$id : 0,
                SystemLogDatabaseAction::UPDATE,
                null,
                SystemLogErrorClassification::USER_ERROR,
                'Only an administrator may enable or disable skill {uid}; the change was not saved.',
                null,
                ['uid' => $id],
            );

            return;
        }

        $enabled                   = $fieldArray['enabled'];
        $fieldArray['disabled_by'] = is_numeric($enabled) && (int)$enabled === 1 ? '' : Skill::DISABLED_BY_ADMIN;
    }

    private function isInteractiveAdmin(mixed $user): bool
    {
        if (!$user instanceof BackendUserAuthentication || !$user->isAdmin()) {
            return false;
        }

        return ($user->user['username'] ?? '') !== '_cli_';
    }
}
