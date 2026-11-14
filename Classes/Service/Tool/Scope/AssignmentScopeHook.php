<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * The second layer of the scope of an assignment (ADR-295): while the tool loop
 * has a call armed with a scope, no DataHandler run may write outside it.
 *
 * The first layer reads what a tool says it will touch. This one reads what
 * the DataHandler is asked to write, including the runs a hook of the
 * installation starts and the child records core generates for an inline
 * relation, so it catches a write the first layer was never told about.
 *
 * Datamap entries are vetoed one by one in `processDatamap_preProcessFieldArray`,
 * which sees the generated entries too and may skip the record, a veto core
 * documents. Commands are vetoed in `processCmdmap_beforeStart`, which may remove
 * a command before any of them runs. A veto is recorded on the guard; the loop
 * turns it into the error the model reads. Inert while nothing is armed.
 *
 * @internal
 */
final class AssignmentScopeHook
{
    private const OPERATIONS = [
        'move'                      => 'move',
        'copy'                      => 'copy',
        'localize'                  => 'translate',
        'copyToLanguage'            => 'translate',
        'inlineLocalizeSynchronize' => 'translate',
        'delete'                    => 'delete',
        'undelete'                  => 'restore',
        'discard'                   => 'discard',
        'version'                   => 'version',
    ];

    /**
     * @param mixed $incomingFieldArray set to null to skip the record
     */
    public function processDatamap_preProcessFieldArray(mixed &$incomingFieldArray, string $table, int|string $id, DataHandler $dataHandler): void
    {
        $scope   = AssignmentScopeGuard::scope();
        $checker = AssignmentScopeGuard::checker();
        if (!$scope instanceof AssignmentScope || !$checker instanceof AssignmentScopeChecker || !is_array($incomingFieldArray)) {
            return;
        }

        $refusal = $this->datamapRefusal($scope, $checker, $table, $id, $incomingFieldArray, $dataHandler, $this->workspaceOf($dataHandler));
        if (!$refusal instanceof ScopeRefusal) {
            return;
        }

        $this->refuse($refusal, $dataHandler);
        $incomingFieldArray = null;
    }

    public function processCmdmap_beforeStart(DataHandler $dataHandler): void
    {
        $scope   = AssignmentScopeGuard::scope();
        $checker = AssignmentScopeGuard::checker();
        if (!$scope instanceof AssignmentScope || !$checker instanceof AssignmentScopeChecker) {
            return;
        }

        $workspace = $this->workspaceOf($dataHandler);
        foreach ($dataHandler->cmdmap as $table => $records) {
            foreach ($records as $id => $commands) {
                if (!is_array($commands)) {
                    continue;
                }

                foreach ($commands as $command => $value) {
                    $refusal = $this->commandRefusal($scope, $checker, $dataHandler, (string) $table, (int) $id, (string) $command, $value, $workspace);
                    if (!$refusal instanceof ScopeRefusal) {
                        continue;
                    }

                    $this->refuse($refusal, $dataHandler);
                    unset($dataHandler->cmdmap[$table][$id][$command]);
                }

                if (($dataHandler->cmdmap[$table][$id] ?? []) === []) {
                    unset($dataHandler->cmdmap[$table][$id]);
                }
            }

            if (($dataHandler->cmdmap[$table] ?? []) === []) {
                unset($dataHandler->cmdmap[$table]);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    private function datamapRefusal(AssignmentScope $scope, AssignmentScopeChecker $checker, string $table, int|string $id, array $fields, DataHandler $dataHandler, int $workspace): ?ScopeRefusal
    {
        $tca    = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $schema = is_array($tca[$table] ?? null) ? $tca[$table] : [];
        $ctrl   = is_array($schema['ctrl'] ?? null) ? $schema['ctrl'] : [];
        $label  = is_string($ctrl['languageField'] ?? null) ? $ctrl['languageField'] : '';
        $link   = is_string($ctrl['transOrigPointerField'] ?? null) ? $ctrl['transOrigPointerField'] : '';
        $isNew  = !MathUtility::canBeInterpretedAsInteger($id);

        if (!$isNew) {
            $refusal = $checker->recordRefusal($scope, 'update', $table, (int) $id, $workspace);
            if ($refusal instanceof ScopeRefusal) {
                return $refusal;
            }
        } else {
            $refusal = $this->newRowRefusal($scope, $checker, $table, $link, $fields, $dataHandler, $workspace);
            if ($refusal instanceof ScopeRefusal) {
                return $refusal;
            }
        }

        // A new row without a language is stored in the default language, so the
        // effective value is 0 and is checked like any other.
        $given = $label !== '' && isset($fields[$label]) && is_numeric($fields[$label]) ? (int) $fields[$label] : null;
        if (!$isNew && $table === 'sys_file_reference' && (isset($fields['tablenames']) || isset($fields['uid_foreign']))) {
            // The record a file reference is attached to is part of the write; an
            // update that repeats only one of the two pointers keeps the other.
            $hostTable = is_string($fields['tablenames'] ?? null) ? $fields['tablenames'] : $checker->storedValue('sys_file_reference', (int) $id, 'tablenames');
            $hostUid   = is_numeric($fields['uid_foreign'] ?? null) ? (int) $fields['uid_foreign'] : $this->number($checker->storedValue('sys_file_reference', (int) $id, 'uid_foreign'));
            $refusal   = $hostTable !== null && $hostTable !== '' && $hostUid > 0 ? $checker->recordRefusal($scope, 'attach', $hostTable, $hostUid, $workspace) : new ScopeRefusal('attach', $table, (int) $id, 0, ScopeRefusal::UNRESOLVABLE);
            if ($refusal instanceof ScopeRefusal) {
                return $refusal;
            }
        }

        $language = $given ?? ($isNew && $label !== '' ? 0 : null);
        if ($language !== null && !$scope->admitsLanguage($language)) {
            return new ScopeRefusal($isNew ? 'create' : 'update', $table, $isNew ? 0 : (int) $id, 0, ScopeRefusal::LANGUAGE_NOT_ALLOWED);
        }

        $origin = $link !== '' && is_numeric($fields[$link] ?? null) ? (int) $fields[$link] : 0;
        if ($origin > 0) {
            $refusal = $checker->recordRefusal($scope, 'translate', $table, $origin, $workspace, false);
            if ($refusal instanceof ScopeRefusal) {
                return $refusal;
            }
        }

        return $this->childRefusal($scope, $checker, $table, $schema, $fields, $workspace);
    }

    /**
     * Whether a new row may be created. A row is judged on its page, with two
     * exceptions that lie on no page of their own: a translation, which is
     * judged on the record it translates (a page overlay lies beside its
     * source, under the source's parent), and a file reference, which is judged
     * on the record it is attached to. Either may be in scope by a record
     * alone, with no page of the scope above it.
     *
     * @param array<array-key, mixed> $fields
     */
    private function newRowRefusal(AssignmentScope $scope, AssignmentScopeChecker $checker, string $table, string $link, array $fields, DataHandler $dataHandler, int $workspace): ?ScopeRefusal
    {
        $origin = $link !== '' && is_numeric($fields[$link] ?? null) ? (int) $fields[$link] : 0;
        $host   = $table === 'sys_file_reference' && is_string($fields['tablenames'] ?? null) && is_numeric($fields['uid_foreign'] ?? null) ? (int) $fields['uid_foreign'] : 0;

        $pid = $this->pidOf($fields['pid'] ?? null, $table, $checker, $dataHandler, $workspace);
        if ($origin > 0 || $host > 0) {
            if (!$scope->admitsTable($table)) {
                return new ScopeRefusal('create', $table, 0, 0, ScopeRefusal::TABLE_NOT_ALLOWED);
            }

            $refusal = $origin > 0
                ? $checker->recordRefusal($scope, 'translate', $table, $origin, $workspace, false)
                : $checker->recordRefusal($scope, 'attach', (string) $fields['tablenames'], $host, $workspace);
            if ($refusal instanceof ScopeRefusal) {
                return $refusal;
            }

            // The row lies where the record it belongs to lies, or on a page the
            // scope holds: it is never placed anywhere else because its origin is
            // in scope.
            $expected = $origin > 0 ? $checker->pidOfRecord($table, $origin, $workspace) : $checker->pidOfRecord((string) $fields['tablenames'], $host, $workspace);
            if ($pid !== null && $pid === $expected) {
                return null;
            }
        }

        return $pid === null
            ? new ScopeRefusal('create', $table, 0, 0, ScopeRefusal::UNRESOLVABLE)
            : $checker->destinationRefusal($scope, 'create', $table, $pid, $workspace);
    }

    /**
     * The existing records an inline or file field names. Listing an existing record in
     * an inline relation re-parents it and writes it with a query of the
     * relation handler, which no datamap entry announces, so each one has to be
     * in scope. New children are datamap entries of their own and are checked
     * there. Group and select relations store a reference on the record
     * itself and change no other row.
     *
     * @param array<array-key, mixed> $schema the TCA of the table
     * @param array<array-key, mixed> $fields
     */
    private function childRefusal(AssignmentScope $scope, AssignmentScopeChecker $checker, string $table, array $schema, array $fields, int $workspace): ?ScopeRefusal
    {
        $columns = is_array($schema['columns'] ?? null) ? $schema['columns'] : [];
        foreach ($fields as $name => $value) {
            $column = is_array($columns[$name] ?? null) ? $columns[$name] : [];
            $config = is_array($column['config'] ?? null) ? $column['config'] : [];
            // The `file` type is an inline relation to sys_file_reference.
            $child = ($config['type'] ?? null) === 'file' ? 'sys_file_reference' : ($config['foreign_table'] ?? null);
            if (!in_array($config['type'] ?? null, ['inline', 'file'], true) || !is_string($child)) {
                continue;
            }

            $items = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');
            foreach ($items as $item) {
                $item = is_scalar($item) ? trim((string) $item) : '';
                $from = $child;
                // Core also reads `table_uid`.
                if (preg_match('/\A([A-Za-z0-9_]+)_([0-9]+)\z/', $item, $named) === 1) {
                    $from = $named[1];
                    $item = $named[2];
                }

                if ($item === '' || !MathUtility::canBeInterpretedAsInteger($item) || (int) $item < 1) {
                    continue;
                }

                $refusal = $checker->recordRefusal($scope, 'update', $from, (int) $item, $workspace);
                if ($refusal instanceof ScopeRefusal) {
                    return $refusal;
                }
            }
        }

        return null;
    }

    private function commandRefusal(AssignmentScope $scope, AssignmentScopeChecker $checker, DataHandler $dataHandler, string $table, int $id, string $command, mixed $value, int $workspace): ?ScopeRefusal
    {
        $operation = self::OPERATIONS[$command] ?? null;
        if ($operation === null || $id < 1) {
            return new ScopeRefusal('command', $table === '' ? 'unknown' : $table, max(0, $id), 0, ScopeRefusal::UNRESOLVABLE);
        }

        if ($operation === 'translate') {
            $refusal = $checker->recordRefusal($scope, $operation, $table, $id, $workspace, false);
            if ($refusal instanceof ScopeRefusal) {
                return $refusal;
            }

            return in_array($command, ['localize', 'copyToLanguage'], true) && is_numeric($value) && !$scope->admitsLanguage((int) $value)
                ? new ScopeRefusal($operation, $table, $id, 0, ScopeRefusal::LANGUAGE_NOT_ALLOWED)
                : null;
        }

        $refusal = $checker->recordRefusal($scope, $operation, $table, $id, $workspace);
        if (!$refusal instanceof ScopeRefusal && in_array($operation, ['delete', 'move', 'discard'], true)) {
            $refusal = $checker->translationsRefusal($scope, $operation, $table, $id, $workspace);
        }

        if ($refusal instanceof ScopeRefusal) {
            return $refusal;
        }

        if ($table === 'pages' && ($operation === 'delete' || $operation === 'copy' && $this->copiesSubpages($dataHandler))) {
            $subtree = $checker->subtreeRefusal($scope, $operation, $id, $workspace);
            if ($subtree instanceof ScopeRefusal) {
                return $subtree;
            }
        }

        if ($operation !== 'move' && $operation !== 'copy') {
            return null;
        }

        $pid = $this->pidOf($this->target($value, $operation), $table, $checker, null, $workspace);
        if ($pid === null) {
            return new ScopeRefusal($operation, $table, $id, 0, ScopeRefusal::UNRESOLVABLE);
        }

        // A moved page takes its subpages along: the deepest of them has to be
        // in scope at the new position.
        return $operation === 'move' && $table === 'pages'
            ? $checker->movePageRefusal($scope, $operation, $id, $pid, $workspace)
            : $checker->destinationRefusal($scope, $operation, $table, $pid, $workspace);
    }

    /**
     * Whether a copy of a page takes its subpages along: the acting user's
     * `copyLevels` (TYPO3 14) or the DataHandler's `copyTree` (13). A copy
     * that stops at the page is judged on the page.
     */
    private function copiesSubpages(DataHandler $dataHandler): bool
    {
        $uc = $dataHandler->BE_USER->uc;

        // `copyTree` is a property of TYPO3 13 and gone in 14: read it by name.
        return $this->number($uc['copyLevels'] ?? 0) > 0 || $this->number(get_object_vars($dataHandler)['copyTree'] ?? 0) > 0;
    }

    private function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The target of a move or copy as core reads it: a plain value, the
     * `target` of an array, or the `target` of a paste command.
     */
    private function target(mixed $value, string $operation): mixed
    {
        if (is_array($value) && ($value['action'] ?? null) === 'paste') {
            $value = $value['target'] ?? null;
        }

        // Core reads the target of a move as an integer, which is 1 for an array.
        if ($operation === 'move' && is_array($value)) {
            return 1;
        }

        return is_array($value) ? ($value['target'] ?? null) : $value;
    }

    /**
     * The page a new or moved row lands on, from the `pid` the DataHandler is
     * given: a page uid, the negative uid of the record it goes after, or a
     * placeholder of a record created earlier in the same run. Null when it
     * cannot be told, which is a refusal.
     */
    private function pidOf(mixed $value, string $table, AssignmentScopeChecker $checker, ?DataHandler $dataHandler, int $workspace): ?int
    {
        if (is_string($value) && $dataHandler instanceof DataHandler && str_contains($value, 'NEW')) {
            $after = str_starts_with($value, '-');
            $real  = $dataHandler->substNEWwithIDs[ltrim($value, '-')] ?? null;
            if ($real === null) {
                return null;
            }

            return $after ? $checker->pidOfRecord($table, $this->number($real), $workspace) : $this->number($real);
        }

        if (!is_int($value) && !(is_string($value) && MathUtility::canBeInterpretedAsInteger($value))) {
            return null;
        }

        $number = (int) $value;

        return $number < 0 ? $checker->pidOfRecord($table, -$number, $workspace) : $number;
    }

    private function refuse(ScopeRefusal $refusal, DataHandler $dataHandler): void
    {
        AssignmentScopeGuard::refuse($refusal);
        $dataHandler->errorLog[] = sprintf('[nr_llm] Refused by the scope of the assignment (%s, %s): %s', $refusal->operation, $refusal->reason, $refusal->subject());
    }

    private function workspaceOf(DataHandler $dataHandler): int
    {
        return max(0, $this->number($dataHandler->BE_USER->workspace ?? 0));
    }
}
