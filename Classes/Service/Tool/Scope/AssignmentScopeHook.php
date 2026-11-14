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
            $pid = $this->pidOf($fields['pid'] ?? null, $table, $checker, $dataHandler, $workspace);
            if ($pid === null) {
                return new ScopeRefusal('create', $table, 0, 0, ScopeRefusal::UNRESOLVABLE);
            }

            $refusal = $checker->destinationRefusal($scope, 'create', $table, $pid, $workspace);
            if ($refusal instanceof ScopeRefusal) {
                return $refusal;
            }
        }

        $language = $label !== '' && isset($fields[$label]) && is_numeric($fields[$label]) ? (int) $fields[$label] : null;
        if ($language !== null && !$scope->admitsLanguage($language)) {
            return new ScopeRefusal($isNew ? 'create' : 'update', $table, $isNew ? 0 : (int) $id, 0, ScopeRefusal::LANGUAGE_NOT_ALLOWED);
        }

        $origin = $link !== '' && is_numeric($fields[$link] ?? null) ? (int) $fields[$link] : 0;

        return $origin > 0 ? $checker->recordRefusal($scope, 'translate', $table, $origin, $workspace, false) : null;
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

        $pid = $this->pidOf($this->target($value), $table, $checker, null, $workspace);

        return $pid === null
            ? new ScopeRefusal($operation, $table, $id, 0, ScopeRefusal::UNRESOLVABLE)
            : $checker->destinationRefusal($scope, $operation, $table, $pid, $workspace);
    }

    /**
     * Whether a copy of a page takes its subpages along: the acting user's
     * `copyLevels` (TYPO3 14) or the DataHandler's `copyTree` (13). A copy
     * that stops at the page is judged on the page.
     */
    private function copiesSubpages(DataHandler $dataHandler): bool
    {
        $uc       = is_array($dataHandler->BE_USER->uc ?? null) ? $dataHandler->BE_USER->uc : [];
        $levels   = $uc['copyLevels'] ?? 0;
        $copyTree = property_exists($dataHandler, 'copyTree') ? $dataHandler->copyTree : 0;

        return is_numeric($levels) && (int) $levels > 0 || is_numeric($copyTree) && (int) $copyTree > 0;
    }

    /**
     * The target of a move or copy as core reads it: a plain value, the
     * `target` of an array, or the `target` of a paste command.
     */
    private function target(mixed $value): mixed
    {
        if (is_array($value) && ($value['action'] ?? null) === 'paste') {
            $value = $value['target'] ?? null;
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

            return $after ? $checker->pidOfRecord($table, (int) $real, $workspace) : (int) $real;
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
        return max(0, (int) ($dataHandler->BE_USER->workspace ?? 0));
    }
}
