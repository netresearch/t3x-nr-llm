<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Hook;

use Netresearch\NrLlm\Domain\Model\Skill;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;

/**
 * Who may switch a skill off or on through the DataHandler, and the mark
 * that records it (ADR-214 item 3).
 *
 * A skill attached to a configuration or a task restricts its runs until an
 * administrator takes it out. Three fields take a skill out of those runs:
 * the enable flag, the hidden flag (Extbase does not load a hidden skill
 * through an attachment) and the orphan flag the sync sets. So:
 *
 * - Only an interactive administrator changes `enabled` or `hidden` on an
 *   existing skill. An administrator's disable is marked 'admin', so the
 *   skill drops out; an enable clears the mark, including the 'sync' mark
 *   only a re-enable may clear. Anyone else — an editor granted the fields,
 *   and a write without an interactive backend user (the CLI and the
 *   scheduler run as `_cli_`) — has the change removed and is told why.
 * - `orphaned` and `disabled_by` belong to the sync: they are removed from
 *   every DataHandler write. The sync writes them through the repository,
 *   which does not pass here.
 * - A new record, a copy included, is not attached anywhere yet. It may be
 *   stored disabled or hidden by anyone; only an administrator may store it
 *   enabled. Stored disabled, it is marked 'sync' — "not enabled by an
 *   administrator" — so a copy that inherits an attachment (a copied page
 *   holding a configuration and its skill) keeps restricting until an
 *   administrator enables it. A new row an administrator stores with the
 *   'admin' mark — a copy of a skill an administrator disabled — keeps it.
 *   A copy of an orphan stays an orphan.
 *
 * Runs after the field checks, on the field array that is about to be
 * written; core has already removed the fields a save leaves unchanged.
 * Registered under `processDatamapClass` in `ext_localconf.php`.
 */
final class SkillDisabledByHook
{
    /**
     * Remove a non-administrator's change of `enabled` or `hidden` before
     * the DataHandler compares and records it, so the record history does
     * not show a change that was never written. The field-array check below
     * stays as the backstop.
     *
     * @param array<string, mixed> $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(array &$incomingFieldArray, string $table, string|int $id, DataHandler $dataHandler): void
    {
        if ($table !== 'tx_nrllm_skill' || !is_numeric($id) || $this->isInteractiveAdmin($dataHandler->BE_USER)) {
            return;
        }

        $current = BackendUtility::getRecord('tx_nrllm_skill', (int)$id, 'enabled,hidden');
        if (!is_array($current)) {
            return;
        }

        $refused = false;
        foreach (['enabled', 'hidden'] as $field) {
            if (array_key_exists($field, $incomingFieldArray) && $this->isOn($incomingFieldArray[$field]) !== $this->isOn($current[$field] ?? 0)) {
                unset($incomingFieldArray[$field]);
                $refused = true;
            }
        }

        if ($refused) {
            $this->refuse($dataHandler, $id, 'Only an administrator may enable, disable, hide or unhide skill {uid}; the change was not saved.');
        }
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        if ($table !== 'tx_nrllm_skill') {
            return;
        }

        $incomingMark   = $fieldArray['disabled_by'] ?? null;
        $incomingOrphan = $status === 'new' && $this->isOn($fieldArray['orphaned'] ?? 0);
        unset($fieldArray['disabled_by'], $fieldArray['orphaned']);
        if ($incomingOrphan) {
            // A copy of an orphan stays an orphan: setting the flag only
            // tightens.
            $fieldArray['orphaned'] = 1;
        }

        $admin = $this->isInteractiveAdmin($dataHandler->BE_USER);

        if ($status === 'new') {
            $enable = $this->isOn($fieldArray['enabled'] ?? 0);
            if ($enable && !$admin) {
                $fieldArray['enabled'] = 0;
                $this->refuse($dataHandler, $id, 'Only an administrator may enable skill {uid}; it was stored disabled.');
            }

            // An administrator's copy of a skill an administrator disabled
            // keeps that decision; every other new disabled row restricts.
            $fieldArray['disabled_by'] = match (true) {
                $this->isOn($fieldArray['enabled'] ?? 0)                => '',
                $admin && $incomingMark === Skill::DISABLED_BY_ADMIN => Skill::DISABLED_BY_ADMIN,
                default                                                 => Skill::DISABLED_BY_SYNC,
            };

            return;
        }

        $changed = array_intersect_key($fieldArray, ['enabled' => true, 'hidden' => true]);
        if ($changed === []) {
            return;
        }

        if (!$admin) {
            unset($fieldArray['enabled'], $fieldArray['hidden']);
            $this->refuse($dataHandler, $id, 'Only an administrator may enable, disable, hide or unhide skill {uid}; the change was not saved.');

            return;
        }

        if (array_key_exists('enabled', $fieldArray)) {
            $fieldArray['disabled_by'] = $this->isOn($fieldArray['enabled']) ? '' : Skill::DISABLED_BY_ADMIN;
        }
    }

    private function refuse(DataHandler $dataHandler, string|int $id, string $message): void
    {
        $dataHandler->log(
            'tx_nrllm_skill',
            is_numeric($id) ? (int)$id : 0,
            SystemLogDatabaseAction::UPDATE,
            null,
            SystemLogErrorClassification::USER_ERROR,
            $message,
            null,
            ['uid' => $id],
        );
    }

    private function isOn(mixed $value): bool
    {
        return is_numeric($value) && (int)$value === 1;
    }

    private function isInteractiveAdmin(mixed $user): bool
    {
        if (!$user instanceof BackendUserAuthentication || $user instanceof CommandLineUserAuthentication || !$user->isAdmin()) {
            return false;
        }

        return ($user->user['username'] ?? '') !== '_cli_';
    }
}
