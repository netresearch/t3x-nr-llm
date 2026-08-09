<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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
