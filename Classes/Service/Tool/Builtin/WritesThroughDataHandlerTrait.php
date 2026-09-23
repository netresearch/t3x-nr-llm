<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The MECHANICS the writing tools share — and deliberately nothing else
 * (ADR-135).
 *
 * {@see UpdatePageMetadataTool} and {@see SetFileAlternativeTextTool} run the
 * same errands around their write: assert the environment the DataHandler
 * declares, refuse a draft workspace, bound the DataHandler's complaints, narrow
 * a table's TCA columns out of `$GLOBALS`, and cut a value down to one readable
 * preview line. None of that decides anything about the record: the two guards
 * describe the PROCESS performing the write, and the rest is formatting.
 *
 * What is NOT here, on purpose:
 *
 * - **The neutral refusal string.** Each tool shares it with the READ tool of
 *   the same records (`Page not found or not permitted.` /
 *   `Asset not found or not permitted.`), so a refusal never confirms that a uid
 *   exists. One shared string would break that pairing.
 * - **The authorisation.** Page permissions and language access on one side,
 *   the storage allow-list and file mounts on the other.
 * - **`isEnabledByDefault()`, `requiresAdmin()`, `getGroup()`, `getEffect()`.**
 *   They are identical today and must still be DECLARED per tool: a third
 *   writer may well be admin-only or non-idempotent, and a trait that answered
 *   for it would make that a silent inheritance rather than a decision.
 * - **The read-back.** Both tools verify their write, but what "it took" means
 *   is theirs: a map of fields against a re-read row versus one column of one
 *   record, each with its own message.
 * - **The row lookup.** The query tail is the same; which restrictions apply —
 *   a deleted page is gone, a `sys_file` has no enable columns at all — is a
 *   decision about what counts as existing.
 *
 * The consuming class must provide `self::toStr()` (via
 * {@see \Netresearch\NrLlm\Utility\SafeCastTrait}).
 */
trait WritesThroughDataHandlerTrait
{
    /** How many DataHandler complaints are echoed back, and how long each may be. */
    private const MAX_ERRORS = 5;

    private const MAX_ERROR_LENGTH = 200;

    /**
     * How much of a value the approval preview shows. A `text` column holds
     * thousands of characters, and a card that pastes two of them per field is
     * unreadable — the approver needs to see WHICH text is being replaced, not
     * the whole of both.
     */
    private const PREVIEW_EXCERPT_LENGTH = 120;

    /**
     * Refuse when the process lacks the backend environment the DataHandler
     * declares, naming which piece is missing — or null when it is complete.
     *
     * The DataHandler declares `$GLOBALS['TCA']` and `$GLOBALS['LANG']` as its
     * prerequisites and `start()` sets only its OWN `$BE_USER`, so a foreign
     * hook running inside the write still reads the ambient one. On a
     * request-bound run all three exist; in a bare worker process they do not.
     *
     * The tool refuses instead of populating them: establishing an ambient
     * backend user is exactly what ADR-083 removed from this runtime, and a tool
     * that sets globals it does not own would set them for every hook and every
     * later request in the same process (ADR-135).
     *
     * @param non-empty-string $table the table whose TCA proves that a TCA is loaded at all
     */
    private function refuseWithoutBackendEnvironment(string $table): ?ToolResult
    {
        $missing = [];
        if ($this->tcaColumnsFor($table) === null) {
            $missing[] = 'TCA';
        }

        if (!(($GLOBALS['LANG'] ?? null) instanceof LanguageService)) {
            $missing[] = 'language service';
        }

        if (!(($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication)) {
            $missing[] = 'backend user';
        }

        if ($missing === []) {
            return null;
        }

        return ToolResult::error(sprintf(
            'Refused: writing needs a full backend environment, and this process has no %s. '
            . 'Run this tool from a backend request rather than a bare worker process.',
            implode(' and no ', $missing),
        ));
    }

    /**
     * Refuse everything but the live workspace, or null in workspace 0.
     *
     * A draft write belongs to the workspace publishing machinery, which carries
     * its own review semantics; a writing tool does not silently join them.
     */
    private function refuseOutsideLiveWorkspace(BackendUserAuthentication $user): ?ToolResult
    {
        if ($user->workspace === 0) {
            return null;
        }

        return ToolResult::error(
            'Refused: this tool only edits the live workspace. Switch out of the draft workspace and retry.',
        );
    }

    /**
     * Surface the DataHandler's complaints as an error, or null when it made
     * none.
     *
     * An empty `errorLog` is not proof that anything landed — that is what each
     * tool's own read-back is for — but a NON-empty one must never be reported
     * as success.
     */
    private function refuseOnDataHandlerErrors(DataHandler $dataHandler): ?ToolResult
    {
        if ($dataHandler->errorLog === []) {
            return null;
        }

        return ToolResult::error(sprintf(
            'The update was refused by TYPO3: %s',
            $this->summariseErrors($dataHandler->errorLog),
        ));
    }

    /**
     * The constraints that keep a query to LIVE rows of a workspace-aware
     * table: `t3ver_wsid`, `t3ver_oid` and `t3ver_state` all 0. Empty for a
     * table without `versioningWS`, which has no such columns.
     *
     * Every writer runs in the live workspace only, and the DataHandler does
     * not refuse a live-workspace write to a workspace VERSION row — it treats
     * any uid it is handed as the record to change. A version row found by uid
     * is therefore another workspace's draft, which a writer must neither
     * change, copy, count nor show on an approval card. Read from the live TCA
     * rather than assumed, so a table an installation makes workspace-aware is
     * covered too.
     *
     * @param string $alias the table alias in the query, '' for none
     *
     * @return list<string>
     */
    private function liveVersionConstraints(QueryBuilder $queryBuilder, string $table, string $alias = ''): array
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[$table] ?? null) ? ($tca[$table]['ctrl'] ?? null) : null;
        if (!is_array($ctrl) || !(bool)($ctrl['versioningWS'] ?? false)) {
            return [];
        }

        $prefix      = $alias === '' ? '' : $alias . '.';
        $constraints = [];
        foreach (['t3ver_wsid', 't3ver_oid', 't3ver_state'] as $column) {
            $constraints[] = $queryBuilder->expr()->eq(
                $prefix . $column,
                $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
            );
        }

        return $constraints;
    }

    /**
     * The columns among `$columns` the user may not write because the TCA
     * marks them `exclude` and the user holds no `non_exclude_fields` grant
     * — the question the DataHandler asks before it drops such a column in
     * silence, through the same method. `exclude` is read as core's schema
     * reads it, as a boolean cast, so an extension's integer `1` counts. The
     * one implementation every writer asks it through.
     *
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private function columnsTheUserMayNotSet(BackendUserAuthentication $user, string $table, array $columns): array
    {
        if ($user->isAdmin()) {
            return [];
        }

        $tcaColumns = $this->tcaColumnsFor($table) ?? [];

        $ungranted = [];
        foreach ($columns as $column) {
            $definition = $tcaColumns[$column] ?? null;
            if (is_array($definition) && (bool)($definition['exclude'] ?? false)
                && !$user->check('non_exclude_fields', $table . ':' . $column)
            ) {
                $ungranted[] = $column;
            }
        }

        return $ungranted;
    }

    /**
     * A table's column definitions from the live TCA, or null when no TCA is
     * loaded. Narrowed step by step because `$GLOBALS` is untyped.
     *
     * @return array<array-key, mixed>|null
     */
    private function tcaColumnsFor(string $table): ?array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !is_array($tca[$table] ?? null)) {
            return null;
        }

        $columns = $tca[$table]['columns'] ?? null;

        return is_array($columns) ? $columns : null;
    }

    /**
     * A value as it appears on the approval card: quoted, or the explicit
     * `(empty)` marker — an empty pair of quotes reads like a rendering bug, and
     * "this field is currently empty" is information the approver needs.
     */
    private function quoted(string $value): string
    {
        return $value === '' ? '(empty)' : '"' . $this->excerpt($value) . '"';
    }

    /**
     * How an approval card shows one field's change, bound to the WHOLE value.
     *
     * Two short values are shown in full, `"old" → "new"`. Where either is
     * longer than the excerpt, or the two differ only in what the excerpt
     * flattens (whitespace), showing two excerpts would hide the change: an
     * appended link past the cut reads as "no change". So the line shows the
     * section that differs — the common start and end stripped, with the
     * character it starts at — and the length and a short hash of both whole
     * values. The hash is what makes the approval bind the whole value: a
     * change anywhere in it changes the line, and ADR-184 compares the lines
     * when the run resumes.
     */
    private function beforeAfter(string $old, string $new): string
    {
        if ($old === $new) {
            return sprintf('unchanged (%s)', $this->quoted($new));
        }

        $fitsWhole = mb_strlen($old) <= self::PREVIEW_EXCERPT_LENGTH && mb_strlen($new) <= self::PREVIEW_EXCERPT_LENGTH;
        if ($fitsWhole && $this->quoted($old) !== $this->quoted($new)) {
            return sprintf('%s → %s', $this->quoted($old), $this->quoted($new));
        }

        // Split once: character-by-character mb_substr() is quadratic on a
        // body text of thousands of characters.
        $oldCharacters = mb_str_split($old);
        $newCharacters = mb_str_split($new);
        $oldLength     = count($oldCharacters);
        $newLength     = count($newCharacters);
        $shorter       = min($oldLength, $newLength);

        $prefix = 0;
        while ($prefix < $shorter && $oldCharacters[$prefix] === $newCharacters[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while ($suffix < $shorter - $prefix
            && $oldCharacters[$oldLength - $suffix - 1] === $newCharacters[$newLength - $suffix - 1]
        ) {
            $suffix++;
        }

        return sprintf(
            'changed from character %d: %s → %s (before: %d characters, %s; after: %d characters, %s)',
            $prefix + 1,
            $this->section(implode('', array_slice($oldCharacters, $prefix, $oldLength - $prefix - $suffix))),
            $this->section(implode('', array_slice($newCharacters, $prefix, $newLength - $prefix - $suffix))),
            $oldLength,
            $this->shortHash($old),
            $newLength,
            $this->shortHash($new),
        );
    }

    /**
     * The differing section of a value as the card shows it. Its whitespace
     * is made visible rather than collapsed: a change that IS whitespace must
     * not read as nothing.
     */
    private function section(string $part): string
    {
        if ($part === '') {
            return '(nothing)';
        }

        $visible = strtr($part, ["\r" => '\\r', "\n" => '\\n', "\t" => '\\t']);

        return '"' . (mb_strlen($visible) > self::PREVIEW_EXCERPT_LENGTH
            ? mb_substr($visible, 0, self::PREVIEW_EXCERPT_LENGTH) . '…'
            : $visible) . '"';
    }

    /**
     * The first twelve hex digits of a value's SHA-256.
     */
    private function shortHash(string $value): string
    {
        return 'sha256:' . substr(hash('sha256', $value), 0, 12);
    }

    /**
     * One line's worth of a value: whitespace collapsed (a `text` column carries
     * newlines, and a preview line must stay one line) and truncated.
     */
    private function excerpt(string $value): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($flat) > self::PREVIEW_EXCERPT_LENGTH
            ? mb_substr($flat, 0, self::PREVIEW_EXCERPT_LENGTH) . '…'
            : $flat;
    }

    /**
     * The DataHandler's complaints, bounded in count and length. They are only
     * ever shown to a caller that already passed the tool's own permission
     * check, so they cannot disclose the existence of a record the caller may
     * not see.
     *
     * @param array<array-key, mixed> $errorLog
     */
    private function summariseErrors(array $errorLog): string
    {
        $messages = [];
        foreach (array_slice($errorLog, 0, self::MAX_ERRORS) as $entry) {
            $text = trim(self::toStr($entry));
            if ($text === '') {
                continue;
            }

            $messages[] = mb_substr($text, 0, self::MAX_ERROR_LENGTH);
        }

        return $messages === [] ? 'the record was not written.' : implode('; ', $messages);
    }
}
