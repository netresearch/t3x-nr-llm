<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Shared read-access policy for the record-reading tools (ADR-042).
 *
 * Centralises three fail-closed gates so {@see Builtin\SearchRecordsTool},
 * {@see Builtin\GetPageContentTool} and {@see Builtin\ReadRecordsTool} apply
 * one consistent model instead of three copies:
 *
 * - a **sensitive-table denylist** that holds for EVERY user including
 *   admins — credentials/audit tables (`be_users`, `sys_log`, ...) have
 *   dedicated redacting tools, and the nr_llm configuration tables carry
 *   vault key references that must never egress to a provider;
 * - the acting user's **`tables_select` right** plus the TCA `adminOnly`
 *   flag for non-admins (admins pass, mirroring the backend);
 * - a **sensitive-field denylist** (credential-ish column names) that is
 *   dropped from every selected/filtered/searched field list — again for
 *   every user, because tool output egresses to the external provider.
 */
final readonly class TableReadAccessService
{
    /**
     * Tables no tool may read regardless of user rights: credential and
     * audit tables (dedicated tools expose redacted views where needed).
     */
    private const SENSITIVE_TABLES = [
        'be_users',
        'be_groups',
        'fe_users',
        'fe_groups',
        'sys_log',
        'sys_history',
        'sys_refindex',
        'sys_http_report',
        'sys_lockedrecords',
        // The FAL storage `configuration` FlexForm carries remote-driver
        // credentials (S3/FTP/WebDAV keys) and the server base path; storages are
        // exposed only through the FAL tools' redacted view (ADR-049).
        'sys_file_storage',
    ];

    /**
     * Table-name prefixes treated like {@see self::SENSITIVE_TABLES} — the
     * nr_llm tables store provider endpoints and vault key references, and the
     * nr_vault tables ARE the secret store (identifiers, ACLs, ciphertext).
     */
    private const SENSITIVE_TABLE_PREFIXES = ['tx_nrllm', 'tx_nrvault'];

    /**
     * Credential-ish field-name segments whose values must never egress.
     * Matched per underscore-separated segment so `api_key` and
     * `identifier_hash` hit while `author` does not. The optional trailing
     * digit run also catches confirm-style suffixes (`token2`, `secret2`)
     * without listing each one.
     */
    private const SENSITIVE_FIELD_PATTERN
        = '/(^|_)(password|passwd|pwd|secret|token|salt|hash|credential|key|mfa|dsn|authorization)(\d+)?($|_)/i';

    /**
     * Unambiguous credential nouns matched boundary-free, catching concatenated
     * or camelCase forms the segment pattern misses (`apikey`, `accessToken`,
     * `password2`, `clientSecret`). Only words that never legitimately appear
     * inside a non-secret column name are listed here — deliberately excluding
     * `secret`/`token` as bare substrings, which would flag `secretary`/`tokenizer`;
     * their separated forms stay covered by {@see self::SENSITIVE_FIELD_PATTERN}.
     */
    private const SENSITIVE_FIELD_SUBSTRING_PATTERN
        = '/(password|passphrase|apikey|apitoken|accesstoken|authtoken|refreshtoken|credential|privatekey|clientsecret)/i';

    /**
     * Whether the acting user may read rows of the given table. Fail-closed:
     * no user, unknown table, denylisted table, or (for non-admins) a table
     * outside the user's `tables_select` rights or flagged `adminOnly` all
     * yield false.
     */
    public function canReadTable(?BackendUserAuthentication $user, string $table): bool
    {
        if ($user === null || $table === '') {
            return false;
        }

        if ($this->isSensitiveTable($table)) {
            return false;
        }

        $allTca = $GLOBALS['TCA'] ?? null;
        if (!is_array($allTca)) {
            return false;
        }
        $tca = $allTca[$table] ?? null;
        if (!is_array($tca)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $ctrl = is_array($tca['ctrl'] ?? null) ? $tca['ctrl'] : [];
        if (($ctrl['adminOnly'] ?? false) === true) {
            return false;
        }

        return $user->check('tables_select', $table);
    }

    /**
     * Whether the table is denylisted for every user (see class docblock).
     */
    public function isSensitiveTable(string $table): bool
    {
        if (in_array($table, self::SENSITIVE_TABLES, true)) {
            return true;
        }

        foreach (self::SENSITIVE_TABLE_PREFIXES as $prefix) {
            if (str_starts_with($table, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a column name looks credential-bearing. Such fields are dropped
     * from selects, filters and search-field lists for every user.
     */
    public function isSensitiveField(string $field): bool
    {
        return preg_match(self::SENSITIVE_FIELD_PATTERN, $field) === 1
            || preg_match(self::SENSITIVE_FIELD_SUBSTRING_PATTERN, $field) === 1;
    }

    /**
     * Drop sensitive names from a field list (see {@see isSensitiveField()}).
     *
     * @param list<string> $fields
     *
     * @return list<string>
     */
    public function filterSensitiveFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn(string $field): bool => !$this->isSensitiveField($field),
        ));
    }
}
