<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
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
     * Fails closed: a value of the entry that this class cannot read into a
     * definite target is refused, never skipped and never replaced by a stored
     * value while the key is present.
     *
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
        $verb   = $isNew ? 'create' : 'update';
        $uid    = $isNew ? 0 : (int) $id;
        $bad    = new ScopeRefusal($verb, $table, $uid, 0, ScopeRefusal::UNRESOLVABLE);

        // The numbers the entry carries about itself.
        $given  = $label !== '' && array_key_exists($label, $fields) ? $this->canonical($fields[$label]) : null;
        $origin = $link !== '' && array_key_exists($link, $fields) ? $this->canonical($fields[$link]) : null;
        if ($label !== '' && array_key_exists($label, $fields) && $given === null || $link !== '' && array_key_exists($link, $fields) && $origin === null) {
            return $bad;
        }

        $host = $this->host($checker, $table, $uid, $isNew, $fields);
        if ($host === false) {
            return $bad;
        }

        if (!$isNew) {
            $refusal = $checker->recordRefusal($scope, 'update', $table, $uid, $workspace);
            if ($refusal instanceof ScopeRefusal) {
                return $refusal;
            }

            if ($host !== null && $this->pointsAtAHost($table, $fields)) {
                $refusal = $checker->recordRefusal($scope, 'attach', $host->table, $host->uid, $workspace);
                if ($refusal instanceof ScopeRefusal) {
                    return $refusal;
                }
            }
        } else {
            $refusal = $this->newRowRefusal($scope, $checker, $table, $origin ?? 0, $host, $fields, $dataHandler, $workspace);
            if ($refusal instanceof ScopeRefusal) {
                return $refusal;
            }
        }

        // A new row without a language is stored in the default language, so the
        // effective value is 0 and is checked like any other.
        $language = $given ?? ($isNew && $label !== '' ? 0 : null);
        if ($language !== null && !$scope->admitsLanguage($language)) {
            return new ScopeRefusal($verb, $table, $uid, 0, ScopeRefusal::LANGUAGE_NOT_ALLOWED);
        }

        if (($origin ?? 0) > 0) {
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
     * source, under the source's parent), and a child row, which is judged on
     * the record it belongs to. Either may be in scope by a record alone. Such a
     * row lies where that record lies; placed on any other page it is judged
     * on that page.
     *
     * @param array<array-key, mixed> $fields
     */
    private function newRowRefusal(AssignmentScope $scope, AssignmentScopeChecker $checker, string $table, int $origin, ?RecordReference $host, array $fields, DataHandler $dataHandler, int $workspace): ?ScopeRefusal
    {
        $pid = $this->pidOf($fields['pid'] ?? null, $table, $checker, $dataHandler, $workspace);
        if ($origin > 0 || $host instanceof RecordReference) {
            if (!$scope->admitsTable($table)) {
                return new ScopeRefusal('create', $table, 0, 0, ScopeRefusal::TABLE_NOT_ALLOWED);
            }

            $refusal = $origin > 0
                ? $checker->recordRefusal($scope, 'translate', $table, $origin, $workspace, false)
                : $checker->recordRefusal($scope, 'attach', $host->table, $host->uid, $workspace);
            if ($refusal instanceof ScopeRefusal) {
                return $refusal;
            }

            $expected = $origin > 0 ? $checker->pidOfRecord($table, $origin, $workspace) : $checker->pidOfRecord($host->table, $host->uid, $workspace);
            if ($pid !== null && $pid === $expected) {
                return null;
            }
        }

        return $pid === null
            ? new ScopeRefusal('create', $table, 0, 0, ScopeRefusal::UNRESOLVABLE)
            : $checker->destinationRefusal($scope, 'create', $table, $pid, $workspace);
    }

    /**
     * The record a child row belongs to, from the columns its parents' TCA names
     * as the foreign field and the foreign table field. Null when the row is no
     * child or names no parent, false when a pointer it carries cannot be read.
     * A pointer the entry does not carry is read from the stored row, never one
     * it carries; a placeholder is the parent of a new record and is judged
     * there.
     *
     * @param array<array-key, mixed> $fields
     */
    private function host(AssignmentScopeChecker $checker, string $table, int $uid, bool $isNew, array $fields): RecordReference|false|null
    {
        $relation = ChildRelations::of($table);
        if ($relation === null) {
            return null;
        }

        $fieldGiven = array_key_exists($relation['field'], $fields);
        $tableGiven = $relation['tableField'] !== null && array_key_exists($relation['tableField'], $fields);

        $hostUid = null;
        if ($fieldGiven) {
            $value = $fields[$relation['field']];
            if (is_string($value) && preg_match('/\ANEW[A-Za-z0-9_]*\z/', $value) === 1) {
                return null;
            }

            $hostUid = $this->canonical($value);
            if ($hostUid === null) {
                return false;
            }
        } elseif (!$isNew) {
            $stored  = $checker->storedValue($table, $uid, $relation['field']);
            $hostUid = $stored === null ? null : $this->canonical($stored);
        }

        $hostTable = null;
        if ($relation['tableField'] === null) {
            $hostTable = count($relation['parents']) === 1 ? $relation['parents'][0] : null;
        } elseif ($tableGiven) {
            $value     = $fields[$relation['tableField']];
            $hostTable = is_string($value) && $this->isTable($value) ? $value : null;
            if ($hostTable === null && $value !== '' && $value !== null) {
                return false;
            }
        } elseif (!$isNew) {
            $stored    = $checker->storedValue($table, $uid, $relation['tableField']);
            $hostTable = $stored !== null && $this->isTable($stored) ? $stored : null;
        }

        if (($fieldGiven || $tableGiven) && ($hostUid === null || $hostUid < 1 || $hostTable === null)) {
            return $hostUid === 0 && $hostTable === null ? null : false;
        }

        return $hostUid !== null && $hostUid > 0 && $hostTable !== null ? new RecordReference($hostTable, $hostUid) : null;
    }

    /**
     * Whether the entry carries a pointer to the record it belongs to, so that
     * an update repeats it.
     *
     * @param array<array-key, mixed> $fields
     */
    private function pointsAtAHost(string $table, array $fields): bool
    {
        $relation = ChildRelations::of($table);

        return $relation !== null && (array_key_exists($relation['field'], $fields) || $relation['tableField'] !== null && array_key_exists($relation['tableField'], $fields));
    }

    /**
     * A number as DataHandler reads it from a field: an integer, or a string of
     * digits without a sign or a leading zero; an empty value is 0. Null for
     * anything else.
     */
    private function canonical(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return is_string($value) && preg_match('/\A(0|[1-9][0-9]{0,18})\z/', $value) === 1 ? (int) $value : null;
    }

    private function isTable(string $name): bool
    {
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];

        return isset($tca[$name]);
    }

    /**
     * The existing records an inline or file field names. Listing an existing
     * record in an inline relation re-parents it with a query of the relation
     * handler that no datamap entry announces, so each one has to be in scope;
     * new children are datamap entries of their own and are checked there.
     * Every item has to be a placeholder of a new record, a canonical uid, or
     * `table_uid` split at the last underscore as core splits it; anything else
     * is refused. Group and select relations store a reference on the record
     * itself.
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

            if ($value === null || $value === '') {
                continue;
            }

            $items = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : "\0");
            foreach ($items as $item) {
                $unreadable = new ScopeRefusal('update', $child, 0, 0, ScopeRefusal::UNRESOLVABLE);
                if (!is_scalar($item)) {
                    return $unreadable;
                }

                $item = trim((string) $item);
                if ($item === '' || preg_match('/\ANEW[A-Za-z0-9_]*\z/', $item) === 1) {
                    continue;
                }

                $from = $child;
                $uid  = $this->canonical($item);
                if (($uid ?? 0) < 1) {
                    $cut  = strrpos($item, '_');
                    $from = $cut === false ? '' : substr($item, 0, $cut);
                    $uid  = $cut === false ? null : $this->canonical(substr($item, $cut + 1));
                    if ($from === '' || !$this->isTable($from) || ($uid ?? 0) < 1) {
                        return $unreadable;
                    }
                }

                $refusal = $checker->recordRefusal($scope, 'update', $from, (int) $uid, $workspace);
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
        if (!$refusal instanceof ScopeRefusal && ($operation === 'copy' ? !$this->ignoresLocalization($value) : in_array($operation, ['delete', 'move', 'discard'], true))) {
            $refusal = $checker->translationsRefusal($scope, $operation, $table, $id, $workspace);
        }

        if (!$refusal instanceof ScopeRefusal && $operation === 'copy' && $table === 'pages' && !$this->ignoresLocalization($value)) {
            $refusal = $checker->pageLanguagesRefusal($scope, $operation, $id, $workspace);
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

    /**
     * Whether a copy leaves the translations behind: core reads
     * `ignoreLocalization` from the target array of the command.
     */
    private function ignoresLocalization(mixed $value): bool
    {
        if (is_array($value) && ($value['action'] ?? null) === 'paste') {
            $value = $value['target'] ?? null;
        }

        return is_array($value) && (bool) ($value['ignoreLocalization'] ?? false);
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
