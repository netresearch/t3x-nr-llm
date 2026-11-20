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
use Throwable;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use WeakMap;

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
 * Core's data-map processor adds an entry for every translation of a record a
 * run updates, with the fields core synchronises from the record
 * (`l10n_mode` `exclude`, `allowLanguageSynchronization`) and `l10n_state`,
 * whether the run changed them or not. What the run submitted is kept in
 * `processDatamap_beforeStart`, which core calls before that processor, so
 * such an entry is told apart from one the run made. An update that names a
 * synchronised field reaches the translations, and is judged with them; an
 * entry core added for an update that names none is skipped where the scope
 * refuses it, without a refusal: the run asked for nothing of it.
 *
 * A discard, a publish and the other `version` actions are judged again for the
 * effect check (#1442), while the rows can still be read: every discard core
 * carries out, cascades included, in `processCmdmap_discardAction`, and every
 * `version` command in `processCmdmap_preProcess`, right before it runs. Both
 * the live row, at its live position, and the draft, at its position in the
 * workspace, have to lie in the scope; what does not, or cannot be read, is
 * noted on the guard and rolls the call back.
 *
 * @internal
 */
final class AssignmentScopeHook
{
    /** The `version` actions core and the workspaces extension know. */
    private const VERSION_ACTIONS = ['new', 'swap', 'publish', 'setStage', 'clearWSID', 'flush'];

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
     * The datamap of each DataHandler run as it was submitted, before core's
     * data-map processor added to it.
     *
     * @var WeakMap<DataHandler, array<array-key, mixed>>|null
     */
    private static ?WeakMap $submitted = null;

    public function processDatamap_beforeStart(DataHandler $dataHandler): void
    {
        if (!AssignmentScopeGuard::scope() instanceof AssignmentScope) {
            return;
        }

        self::$submitted ??= new WeakMap();
        self::$submitted[$dataHandler] = $dataHandler->datamap;
    }

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

        $workspace = $this->workspaceOf($dataHandler);
        $refusal   = $this->datamapRefusal($scope, $checker, $table, $id, $incomingFieldArray, $dataHandler, $workspace);
        if (!$refusal instanceof ScopeRefusal) {
            return;
        }

        if (!$this->isAddedByCoreForAnUnsynchronisedUpdate($checker, $table, $id, $incomingFieldArray, $dataHandler, $workspace)) {
            $this->refuse($refusal, $dataHandler);
        }

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
     * A `version` command, right before core carries it out: a publish puts the
     * draft where the live row is, a new version and a stage change write the
     * draft, and an action that is not known is judged as unreadable. A discard
     * (`clearWSID`, `flush`) is judged where core discards.
     */
    public function processCmdmap_preProcess(mixed $command, mixed $table, mixed $id, mixed $value, DataHandler $dataHandler): void
    {
        $scope   = AssignmentScopeGuard::scope();
        $checker = AssignmentScopeGuard::checker();
        if ($command !== 'version' || !$scope instanceof AssignmentScope || !$checker instanceof AssignmentScopeChecker || !is_string($table)) {
            return;
        }

        $workspace = $this->workspaceOf($dataHandler);
        $action    = is_array($value) ? ($value['action'] ?? null) : null;
        if (!in_array($action, self::VERSION_ACTIONS, true)) {
            AssignmentScopeGuard::observe(new ScopeRefusal('effect_version', $table, is_int($id) ? max(0, $id) : 0, 0, ScopeRefusal::UNRESOLVABLE), $workspace);

            return;
        }

        if ($action === 'clearWSID' || $action === 'flush') {
            return;
        }

        $uids = $action === 'setStage' ? GeneralUtility::intExplode(',', is_scalar($id) ? (string) $id : '', true) : [is_numeric($id) ? (int) $id : 0];
        if (in_array($action, ['swap', 'publish'], true) && is_array($value) && is_numeric($value['swapWith'] ?? null)) {
            $uids[] = (int) $value['swapWith'];
        }

        foreach ($uids as $uid) {
            $this->observeDraftAndLive($scope, $checker, $action === 'swap' || $action === 'publish' ? 'effect_publish' : 'effect_version', $table, $uid, null, $workspace);
        }
    }

    /**
     * Every discard core carries out, before it hard-deletes the draft: the
     * one a command asks for, the one a delete of a draft turns into, and the
     * translations, children, subpages and records core discards along with
     * it. Core calls this before its own checks, so a discard core then does
     * not make is judged too.
     *
     * @param array<string, mixed> $record the row core discards, live or a version
     */
    public function processCmdmap_discardAction(mixed $table, mixed $uid, mixed $record): void
    {
        $scope   = AssignmentScopeGuard::scope();
        $checker = AssignmentScopeGuard::checker();
        if (!$scope instanceof AssignmentScope || !$checker instanceof AssignmentScopeChecker || !is_string($table) || !is_array($record) || !$this->versioned($table)) {
            return;
        }

        $this->observeDraftAndLive($scope, $checker, 'effect_discard', $table, is_numeric($uid) ? (int) $uid : 0, $record, AssignmentScopeGuard::workspace());
    }

    /**
     * Judges a row of a versioned table as a discard or publish touches it: its
     * live row at the live position, and the draft at its position in the
     * workspace (the row's own, or the call's for a live row). A draft that has
     * no live row, a new record, is judged where the draft lies. A row that
     * does not exist is no change; a read that fails is unreadable.
     *
     * @param array<array-key, mixed>|null $row the stored row when the caller has it
     */
    private function observeDraftAndLive(AssignmentScope $scope, AssignmentScopeChecker $checker, string $operation, string $table, int $uid, ?array $row, int $workspace): void
    {
        try {
            if ($uid < 1) {
                AssignmentScopeGuard::observe(new ScopeRefusal($operation, $table, 0, 0, ScopeRefusal::UNRESOLVABLE), $workspace);

                return;
            }

            if (!$this->versioned($table)) {
                $wsid = 0;
                $oid  = 0;
            } elseif ($row !== null) {
                $wsid = $this->number($row['t3ver_wsid'] ?? 0);
                $oid  = $this->number($row['t3ver_oid'] ?? 0);
            } else {
                $stored = $checker->storedValue($table, $uid, 't3ver_wsid');
                if ($stored === null) {
                    // No row: the command changes nothing.
                    return;
                }

                $wsid = $this->number($stored);
                $oid  = $this->number($checker->storedValue($table, $uid, 't3ver_oid'));
            }

            $live      = $wsid === 0 ? $uid : ($oid > 0 ? $oid : null);
            $draftedIn = $wsid > 0 ? $wsid : $workspace;
            $refusals  = [];
            if ($live !== null) {
                $refusals[] = $checker->recordRefusal($scope, $operation, $table, $live, 0);
            }

            if ($draftedIn > 0) {
                $refusals[] = $checker->recordRefusal($scope, $operation, $table, $live ?? $uid, $draftedIn);
            }

            foreach ($refusals as $refusal) {
                if ($refusal instanceof ScopeRefusal) {
                    AssignmentScopeGuard::observe($refusal, $draftedIn);
                }
            }
        } catch (Throwable) {
            AssignmentScopeGuard::observe(new ScopeRefusal($operation, $table, max(0, $uid), 0, ScopeRefusal::UNRESOLVABLE), $workspace);
        }
    }

    /**
     * Whether the table keeps workspace versions, and so has the version
     * columns: a query for them on another table fails, and on PostgreSQL a
     * failed query ends the call's transaction.
     */
    private function versioned(string $table): bool
    {
        $tca    = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $schema = is_array($tca[$table] ?? null) ? $tca[$table] : [];
        $ctrl   = is_array($schema['ctrl'] ?? null) ? $schema['ctrl'] : [];

        return (bool) ($ctrl['versioningWS'] ?? false);
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
        $given  = $label !== '' && array_key_exists($label, $fields) ? $this->canonical($fields[$label], true) : null;
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

            if ($host instanceof RecordReference && $this->pointsAtAHost($table, $fields)) {
                $refusal = $checker->recordRefusal($scope, 'attach', $host->table, $host->uid, $workspace);
                if ($refusal instanceof ScopeRefusal) {
                    return $refusal;
                }
            }

            // Core copies a synchronised field to the translations of the
            // record, so their languages have to be in scope too. Read from the
            // entry as the run submitted it: core's processor merges the
            // synchronised fields into the entry of a submitted translation.
            // The translations point at the live row, also when the entry names
            // its workspace version.
            if (array_intersect($this->names($this->submittedEntry($dataHandler, $table, $uid) ?? $fields), $this->synchronisedFields($table)) !== []) {
                $refusal = $checker->synchronisedTranslationsRefusal($scope, $table, $checker->factsOf($table, $uid, $workspace)->liveUid ?? $uid, $workspace);
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
     * Whether the entry is one core's data-map processor added for an existing
     * translation, because the run updated the record it translates or was
     * translated from in a way that synchronises nothing: its id was not
     * submitted; the record it translates (`l10n_parent`) or its source
     * (`l10n_source`) was, and no submitted entry of those two names a
     * synchronised field; the entry holds nothing but synchronised fields and
     * `l10n_state`; and no inline or file field in it lists a child, because
     * core copies or localises children for such a field before any entry is
     * judged. Any doubt is a no, and the entry is judged as any other.
     *
     * @param array<array-key, mixed> $fields
     */
    private function isAddedByCoreForAnUnsynchronisedUpdate(AssignmentScopeChecker $checker, string $table, int|string $id, array $fields, DataHandler $dataHandler, int $workspace): bool
    {
        $submitted = self::$submitted instanceof WeakMap && isset(self::$submitted[$dataHandler]) ? self::$submitted[$dataHandler] : null;
        if (!is_array($submitted) || !MathUtility::canBeInterpretedAsInteger($id) || (int) $id < 1) {
            return false;
        }

        $entries      = is_array($submitted[$table] ?? null) ? $submitted[$table] : [];
        $synchronised = $this->synchronisedFields($table);
        if ($synchronised === [] || array_key_exists((int) $id, $entries) || array_diff($this->names($fields), [...$synchronised, 'l10n_state']) !== [] || $this->listsAChild($table, $fields)) {
            return false;
        }

        $facts   = $checker->factsOf($table, (int) $id, $workspace);
        $origins = array_values(array_filter([$facts?->translationOf, $facts?->translationSource], static fn (?int $uid): bool => $uid !== null && $uid > 0));
        if ($origins === []) {
            return false;
        }

        return $this->originsWereSubmittedWithoutASynchronisedField($checker, $table, $entries, $origins, $synchronised, $workspace);
    }

    /**
     * Whether the run submitted at least one of the records an added entry
     * derives from, and none of them with a synchronised field.
     *
     * @param array<array-key, mixed> $entries      the submitted entries of the table
     * @param list<int>               $origins      the live uids the entry derives from
     * @param list<string>            $synchronised
     */
    private function originsWereSubmittedWithoutASynchronisedField(AssignmentScopeChecker $checker, string $table, array $entries, array $origins, array $synchronised, int $workspace): bool
    {
        $found = false;
        foreach ($entries as $key => $entry) {
            $isOrigin = is_array($entry) && MathUtility::canBeInterpretedAsInteger($key) && (int) $key > 0
                && (in_array((int) $key, $origins, true) || in_array($checker->factsOf($table, (int) $key, $workspace)?->liveUid, $origins, true));
            if (!$isOrigin) {
                continue;
            }

            if (array_intersect($this->names($entry), $synchronised) !== []) {
                return false;
            }

            $found = true;
        }

        return $found;
    }

    /**
     * Whether an inline or file field of the entry lists anything.
     *
     * @param array<array-key, mixed> $fields
     */
    private function listsAChild(string $table, array $fields): bool
    {
        $tca     = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $schema  = is_array($tca[$table] ?? null) ? $tca[$table] : [];
        $columns = is_array($schema['columns'] ?? null) ? $schema['columns'] : [];
        foreach ($fields as $name => $value) {
            $column = is_array($columns[$name] ?? null) ? $columns[$name] : [];
            $config = is_array($column['config'] ?? null) ? $column['config'] : [];
            if (in_array($config['type'] ?? null, ['inline', 'file'], true) && !in_array($value, [null, '', 0, '0', []], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entry the run submitted for the record, or null when it submitted
     * none or the datamap was not kept.
     *
     * @return array<array-key, mixed>|null
     */
    private function submittedEntry(DataHandler $dataHandler, string $table, int $uid): ?array
    {
        $submitted = self::$submitted instanceof WeakMap && isset(self::$submitted[$dataHandler]) ? self::$submitted[$dataHandler] : null;
        $entries   = is_array($submitted) && is_array($submitted[$table] ?? null) ? $submitted[$table] : [];

        return is_array($entries[$uid] ?? null) ? $entries[$uid] : null;
    }

    /**
     * The columns core synchronises from a record to its translations, as its
     * data-map processor selects them: `l10n_mode` `exclude` (except type
     * `none`) and `allowLanguageSynchronization`.
     *
     * @return list<string>
     */
    private function synchronisedFields(string $table): array
    {
        $tca     = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $schema  = is_array($tca[$table] ?? null) ? $tca[$table] : [];
        $columns = is_array($schema['columns'] ?? null) ? $schema['columns'] : [];
        $names   = [];
        foreach ($columns as $name => $column) {
            $config    = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];
            $behaviour = is_array($config['behaviour'] ?? null) ? $config['behaviour'] : [];
            if (is_array($column) && ($column['l10n_mode'] ?? null) === 'exclude' && ($config['type'] ?? null) !== 'none' || !empty($behaviour['allowLanguageSynchronization'])) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @param array<array-key, mixed> $fields
     *
     * @return list<string>
     */
    private function names(array $fields): array
    {
        return array_map(static fn (int|string $name): string => (string) $name, array_keys($fields));
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
     * anything else. For a language field -1 (all languages) is a number too.
     */
    private function canonical(mixed $value, bool $language = false): ?int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        // -1 is "all languages", which only a language field may say.
        if ($language && ($value === -1 || $value === '-1')) {
            return -1;
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

                $refusal = $checker->recordRefusal($scope, 'update', $from, $uid, $workspace);
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

        // A page is copied with everything on it, free-mode translations included,
        // whatever `ignoreLocalization` says, and with its subpages when the
        // copy levels reach them.
        if (!$refusal instanceof ScopeRefusal && $operation === 'copy' && $table === 'pages') {
            $refusal = $checker->pageLanguagesRefusal($scope, $operation, $id, $workspace, $this->copiesSubpages($dataHandler));
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

        if (!is_int($value) && (!is_string($value) || !MathUtility::canBeInterpretedAsInteger($value))) {
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
