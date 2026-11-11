<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Agent;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;

/**
 * Guards what a DataHandler write may change on a remote agent record (spec
 * 051 A2A-C2). The table is `adminOnly`; this hook is the server-side rule
 * behind it.
 *
 * - The fields taken from the card belong to the import, which writes them
 *   directly, never through the DataHandler. A DataHandler write cannot set
 *   them, nor `card_changed` or `confirmed_interface`.
 * - A changed card is confirmed only explicitly, by an administrator in an
 *   interactive backend session (not the CLI user): the `card_confirm` select
 *   submits the digest of the card the form showed, and the confirmation
 *   counts only when it still matches the stored card. A card that changed in
 *   between stays unconfirmed, and the DataHandler log says so. `card_confirm`
 *   is never stored.
 * - Changing the card URL or the credential drops the imported card, so the
 *   record needs a new import, and a confirmation, before it is used. The
 *   credential counts as changed when its identifier changes or when a new
 *   secret value, or a clearing, is submitted for it: nr-vault keeps the
 *   identifier when a secret is replaced, so the submitted value is read from
 *   the DataHandler's original datamap, which nr-vault does not rewrite.
 * - An identifier of digits only is not stored: the commands read a number
 *   as a record uid, so `7` would name another agent than the one called `7`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aRemoteAgentSaveHook
{
    public const TABLE = 'tx_nrllm_a2a_agent';

    /** Written by the import or by this hook only. */
    private const IMPORT_FIELDS = [
        'card_json', 'card_fetched', 'card_etag', 'interface_url', 'interface_tenant',
        'card_capabilities', 'card_changed', 'confirmed_interface', 'import_error',
    ];

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int|string $id,
        array &$fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ($table !== self::TABLE) {
            return;
        }

        if (array_key_exists('identifier', $fieldArray) && preg_match('/^\d+$/', $this->text($fieldArray['identifier'])) === 1) {
            unset($fieldArray['identifier']);
            $this->refuse($dataHandler, is_numeric($id) ? (int) $id : 0, 'An A2A agent identifier needs a letter or an underscore: the commands read a number as a record uid. The identifier was not stored.');
        }

        $confirm = $this->text($fieldArray['card_confirm'] ?? null);
        foreach (self::IMPORT_FIELDS as $field) {
            unset($fieldArray[$field]);
        }

        if (array_key_exists('card_confirm', $fieldArray)) {
            $fieldArray['card_confirm'] = '';
        }

        if ($status !== 'update' || !is_numeric($id)) {
            return;
        }

        $row = BackendUtility::getRecord(self::TABLE, (int) $id);
        if (!is_array($row)) {
            return;
        }

        if ($this->changes($fieldArray, $row, 'card_url') || $this->credentialChanges($fieldArray, $row, $dataHandler, (int) $id)) {
            $fieldArray = [
                'card_json'           => '',
                'card_fetched'        => null,
                'card_etag'           => '',
                'interface_url'       => '',
                'interface_tenant'    => '',
                'card_capabilities'   => '',
                'card_changed'        => 0,
                'confirmed_interface' => '',
            ] + $fieldArray;

            return;
        }

        if ($confirm === '' || $this->text($row['card_changed'] ?? null) !== '1') {
            return;
        }

        if (!$this->isInteractiveAdmin($dataHandler->BE_USER)) {
            $this->refuse($dataHandler, (int) $id, 'Only an administrator in the backend can confirm an A2A agent card; nothing was confirmed.');

            return;
        }

        $interfaceUrl = $this->text($row['interface_url'] ?? null);
        $current      = A2aRemoteAgent::cardDigest($interfaceUrl, $this->text($row['interface_tenant'] ?? null), $this->text($row['card_capabilities'] ?? null));
        if (!hash_equals($current, $confirm)) {
            $this->refuse($dataHandler, (int) $id, 'The agent card changed after the form was opened; nothing was confirmed. Review the card again.');

            return;
        }

        $fieldArray['card_changed']        = 0;
        $fieldArray['confirmed_interface'] = $interfaceUrl;
    }

    /**
     * @param array<string, mixed>    $fieldArray
     * @param array<array-key, mixed> $row
     */
    private function changes(array $fieldArray, array $row, string $field): bool
    {
        return array_key_exists($field, $fieldArray)
            && $this->text($fieldArray[$field]) !== $this->text($row[$field] ?? null);
    }

    /**
     * @param array<string, mixed>    $fieldArray
     * @param array<array-key, mixed> $row
     */
    private function credentialChanges(array $fieldArray, array $row, DataHandler $dataHandler, int $uid): bool
    {
        if ($this->changes($fieldArray, $row, 'credential')) {
            return true;
        }

        $submitted = $dataHandler->datamap[self::TABLE][$uid]['credential'] ?? null;
        if (is_array($submitted)) {
            // nr-vault's form element: a value is a new secret, an empty value
            // with no checksum is a clearing; an empty value with the checksum
            // is an untouched field.
            $value = $this->text($submitted['value'] ?? $submitted[0] ?? null);

            return $value !== ''
                || ($this->text($submitted['_vault_checksum'] ?? null) === '' && $this->text($submitted['_vault_identifier'] ?? null) !== '');
        }

        // A plain string is stored by nr-vault as a new secret value.
        return $this->text($submitted) !== '' && $this->text($submitted) !== $this->text($row['credential'] ?? null);
    }

    private function refuse(DataHandler $dataHandler, int $uid, string $message): void
    {
        $dataHandler->log(
            self::TABLE,
            $uid,
            SystemLogDatabaseAction::UPDATE,
            null,
            SystemLogErrorClassification::USER_ERROR,
            $message,
            null,
            ['uid' => $uid],
        );
    }

    private function isInteractiveAdmin(mixed $user): bool
    {
        if (!$user instanceof BackendUserAuthentication || $user instanceof CommandLineUserAuthentication || !$user->isAdmin()) {
            return false;
        }

        return ($user->user['username'] ?? '') !== '_cli_';
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
