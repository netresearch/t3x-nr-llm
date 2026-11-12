<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aDomainHandlerRegistry;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelResolver;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;

/**
 * Guards what a DataHandler write may store on a client or a publication
 * profile of the A2A server role (spec 051 A2A-SV4, A2A-SEC3, ADR-280,
 * ADR-282). The tables are `adminOnly`; this hook is the server-side rule
 * behind it.
 *
 * Client:
 * - The token's prefix, hash and issue time are written by the token
 *   command only; a DataHandler write cannot set them.
 * - The technical backend user must exist, be enabled, have a uid above 0
 *   and not be an administrator; otherwise the field is not stored and the
 *   record cannot be enabled.
 *
 * Profile:
 * - `public` is stored only when the profile's LLM configuration keeps a
 *   result (its answer level is neither `metadata` nor `none`).
 * - The handler must be one registered here.
 * - `may_write` stays 0 in stage 3 (A2A-MT5 is stage 4).
 * - Every tool of a profile that may not write declares that it only reads;
 *   otherwise the tool list is not stored. The run would leave such a tool
 *   out anyway; the refusal says so where the list is entered.
 *
 * A refusal is written to the DataHandler log, which the backend shows.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aServerRecordSaveHook
{
    public const CLIENT_TABLE = 'tx_nrllm_a2a_client';

    public const PROFILE_TABLE = 'tx_nrllm_a2a_profile';

    private const TOKEN_FIELDS = ['token_prefix', 'token_hash', 'token_issued'];

    public function __construct(
        private A2aDomainHandlerRegistry $handlers,
        private RunPrivacyLevelResolver $privacy,
        private ConnectionPool $connectionPool,
        private ToolEffectResolver $effects,
    ) {}

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
        if ($table === self::CLIENT_TABLE) {
            $this->client($status, $id, $fieldArray, $dataHandler);
        }

        if ($table === self::PROFILE_TABLE) {
            $this->profile($status, $id, $fieldArray, $dataHandler);
        }
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    private function client(string $status, int|string $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        foreach (self::TOKEN_FIELDS as $field) {
            unset($fieldArray[$field]);
        }

        $row  = $status === 'update' && is_numeric($id) ? (BackendUtility::getRecord(self::CLIENT_TABLE, (int) $id) ?? []) : [];
        $user = array_key_exists('be_user', $fieldArray) ? $fieldArray['be_user'] : ($row['be_user'] ?? 0);
        if ($this->usableBackendUser(is_numeric($user) ? (int) $user : 0)) {
            return;
        }

        unset($fieldArray['be_user']);
        $fieldArray['enabled'] = 0;
        $this->refuse($dataHandler, self::CLIENT_TABLE, $id, 'The technical backend user of an A2A client must exist, be enabled and not be an administrator; the client stays disabled.');
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    private function profile(string $status, int|string $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        unset($fieldArray['may_write']);

        $row = $status === 'update' && is_numeric($id) ? (BackendUtility::getRecord(self::PROFILE_TABLE, (int) $id) ?? []) : [];

        $this->checkHandler($id, $fieldArray, $dataHandler);
        $this->checkTools($id, $fieldArray, $dataHandler);

        $public = array_key_exists('public', $fieldArray) ? $fieldArray['public'] : ($row['public'] ?? 0);
        if (!is_numeric($public) || (int) $public !== 1) {
            return;
        }

        $configuration = array_key_exists('configuration_uid', $fieldArray) ? $fieldArray['configuration_uid'] : ($row['configuration_uid'] ?? 0);
        $level         = $this->privacy->forConfigurationAnswer(is_numeric($configuration) ? (int) $configuration : 0);
        if ($level->persistsContent()) {
            return;
        }

        $fieldArray['public'] = 0;
        $this->refuse($dataHandler, self::PROFILE_TABLE, $id, 'The LLM configuration of this profile keeps no result under the privacy policy (its answer level is metadata or none), so the profile cannot be public (ADR-282).');
    }

    /**
     * A domain handler that is not registered is not stored.
     *
     * @param array<string, mixed> $fieldArray
     */
    private function checkHandler(int|string $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        if (!array_key_exists('handler', $fieldArray)) {
            return;
        }

        $handler = is_string($fieldArray['handler']) ? $fieldArray['handler'] : '';
        if ($handler !== '' && !in_array($handler, $this->handlers->identifiers(), true)) {
            unset($fieldArray['handler']);
            $this->refuse($dataHandler, self::PROFILE_TABLE, $id, 'The domain handler is not registered in this installation; it was not stored.');
        }
    }

    /**
     * A tool list with a tool that does not declare a read is not stored.
     *
     * @param array<string, mixed> $fieldArray
     */
    private function checkTools(int|string $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        if (!array_key_exists('tools', $fieldArray)) {
            return;
        }

        $undeclared = array_values(array_filter(
            A2aSkillProfile::fromRow(['tools' => is_string($fieldArray['tools']) ? $fieldArray['tools'] : ''])->tools,
            fn (string $tool): bool => !$this->effects->declaresRead($tool),
        ));
        if ($undeclared !== []) {
            unset($fieldArray['tools']);
            $this->refuse($dataHandler, self::PROFILE_TABLE, $id, sprintf('A profile that may not write offers only tools that declare that they only read; %s do not. The tool list was not stored.', implode(', ', $undeclared)));
        }
    }

    private function usableBackendUser(int $uid): bool
    {
        if ($uid < 1) {
            return false;
        }

        $builder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $builder->getRestrictions()->removeAll();
        $row = $builder->select('admin', 'disable', 'deleted')->from('be_users')
            ->where($builder->expr()->eq('uid', $builder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return false;
        }

        foreach (['admin', 'disable', 'deleted'] as $flag) {
            if (!is_numeric($row[$flag] ?? null) || (int) $row[$flag] !== 0) {
                return false;
            }
        }

        return true;
    }

    private function refuse(DataHandler $dataHandler, string $table, int|string $id, string $message): void
    {
        $dataHandler->log($table, is_numeric($id) ? (int) $id : 0, SystemLogDatabaseAction::UPDATE, null, SystemLogErrorClassification::USER_ERROR, $message, null, ['uid' => $id]);
    }
}
