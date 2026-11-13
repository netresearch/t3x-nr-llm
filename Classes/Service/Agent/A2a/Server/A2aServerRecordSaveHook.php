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
 *   command only; a DataHandler write cannot set them. The same holds for the
 *   `self_service` flag (ADR-294): only the editor's own module writes a
 *   self-service record, so no form turns a technical-user record into one or
 *   the reverse.
 * - The technical backend user must exist, be enabled, have a uid above 0
 *   and not be an administrator; otherwise the field is not stored and the
 *   record cannot be enabled.
 *
 * Profile:
 * - `public` is stored only when the profile's LLM configuration keeps a
 *   result (its answer level is neither `metadata` nor `none`).
 * - The handler must be one registered here.
 * - `may_write` is stored only while no client that may call the profile is
 *   pinned to live (A2A-MT5): a writing profile writes drafts only.
 * - Every tool of a profile that may not write declares that it only reads;
 *   otherwise the tool list is not stored. The run would leave such a tool
 *   out anyway; the refusal says so where the list is entered.
 *
 * Client:
 * - A client pinned to live (workspace 0) cannot be allowed a writing
 *   profile; the profile list is then not stored (A2A-MT5).
 *
 * A refusal is written to the DataHandler log, which the backend shows.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aServerRecordSaveHook
{
    public const CLIENT_TABLE = 'tx_nrllm_a2a_client';

    public const PROFILE_TABLE = 'tx_nrllm_a2a_profile';

    private const TOKEN_FIELDS = ['token_prefix', 'token_hash', 'token_issued', 'self_service'];

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

        $row = $status === 'update' && is_numeric($id) ? (BackendUtility::getRecord(self::CLIENT_TABLE, (int) $id) ?? []) : [];
        $this->clientWorkspace($id, $fieldArray, $row, $dataHandler);

        $user = array_key_exists('be_user', $fieldArray) ? $fieldArray['be_user'] : ($row['be_user'] ?? 0);
        if ($this->usableBackendUser(is_numeric($user) ? (int) $user : 0)) {
            return;
        }

        unset($fieldArray['be_user']);
        $fieldArray['enabled'] = 0;
        $this->refuse($dataHandler, self::CLIENT_TABLE, $id, 'The technical backend user of an A2A client must exist, be enabled and not be an administrator; the client stays disabled.');
    }

    /**
     * A2A-MT5 on the client: live (workspace 0) and a writing profile do not
     * go together.
     *
     * @param array<string, mixed>    $fieldArray
     * @param array<array-key, mixed> $row
     */
    private function clientWorkspace(int|string $id, array &$fieldArray, array $row, DataHandler $dataHandler): void
    {
        $workspace = array_key_exists('workspace', $fieldArray) ? $fieldArray['workspace'] : ($row['workspace'] ?? 0);
        $profiles  = array_key_exists('profiles', $fieldArray) ? $fieldArray['profiles'] : ($row['profiles'] ?? '');
        if (is_numeric($workspace) && (int) $workspace > 0) {
            return;
        }

        $uids = array_values(array_filter(array_map(intval(...), explode(',', is_scalar($profiles) ? (string) $profiles : '')), static fn (int $uid): bool => $uid > 0));
        if ($uids === [] || $this->countWritingProfiles($uids) === 0) {
            return;
        }

        unset($fieldArray['profiles'], $fieldArray['workspace']);
        $this->refuse($dataHandler, self::CLIENT_TABLE, $id, 'A client pinned to live (workspace 0) cannot call a writing profile: a writing profile writes drafts only. Pin a draft workspace first; the profiles and the workspace were not stored.');
    }

    /**
     * @param list<int> $uids
     */
    private function countWritingProfiles(array $uids): int
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::PROFILE_TABLE);
        $builder->getRestrictions()->removeAll();
        $count = $builder->count('uid')->from(self::PROFILE_TABLE)
            ->where(
                $builder->expr()->in('uid', $builder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
                $builder->expr()->eq('may_write', 1),
                $builder->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Whether a client pinned to live may call the profile.
     */
    private function liveClientMayCall(int $profileUid): bool
    {
        $builder = $this->connectionPool->getQueryBuilderForTable(self::CLIENT_TABLE);
        $builder->getRestrictions()->removeAll();
        $rows = $builder->select('profiles')->from(self::CLIENT_TABLE)
            ->where($builder->expr()->eq('workspace', 0), $builder->expr()->eq('deleted', 0))
            ->executeQuery()
            ->fetchFirstColumn();
        foreach ($rows as $profiles) {
            if (in_array($profileUid, array_map(intval(...), explode(',', is_scalar($profiles) ? (string) $profiles : '')), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    private function profile(string $status, int|string $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        $row = $status === 'update' && is_numeric($id) ? (BackendUtility::getRecord(self::PROFILE_TABLE, (int) $id) ?? []) : [];

        $this->checkMayWrite($id, $fieldArray, $dataHandler);
        $this->checkHandler($id, $fieldArray, $dataHandler);

        // A writing profile offers its tools as they are (A2A-MT5); the check
        // is for a profile that stays or becomes one that may not write.
        $writes = array_key_exists('may_write', $fieldArray) ? $fieldArray['may_write'] : ($row['may_write'] ?? 0);
        if (!is_numeric($writes) || (int) $writes !== 1) {
            $this->checkTools($id, $fieldArray, $dataHandler);
        }

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
     * A2A-MT5 on the profile: a profile a live client may call cannot write.
     *
     * @param array<string, mixed> $fieldArray
     */
    private function checkMayWrite(int|string $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        $mayWrite = $fieldArray['may_write'] ?? null;
        if (is_numeric($mayWrite) && (int) $mayWrite === 1 && is_numeric($id) && $this->liveClientMayCall((int) $id)) {
            unset($fieldArray['may_write']);
            $this->refuse($dataHandler, self::PROFILE_TABLE, $id, 'A client pinned to live (workspace 0) may call this profile, so it cannot write: a writing profile writes drafts only. "May write" was not stored.');
        }
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
