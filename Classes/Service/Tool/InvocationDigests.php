<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\ObservedState;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Tool\Builtin\ReadsTheRunsWorkspaceTrait;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use stdClass;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * The keyed digests an invocation history entry may carry (ADR-298): one of a
 * call's final arguments, one of a record state a tool observed.
 *
 * Each is `v1:` and the hex HMAC-SHA256 of the canonical form below, keyed
 * with the installation's encryption key joined with a purpose string of its
 * own, as TYPO3's `HashService` builds its key; PHP's `hash_hmac()` computes
 * it, because the `HashService` of TYPO3 13.4 offers SHA-1 only. An empty
 * encryption key gives no digest.
 *
 * Canonical form, version 1: the JSON encoding of the value, where an array
 * that is not a list (decided before its keys are sorted) has its keys sorted
 * as strings and stays an object, and a list keeps its order. Slashes and
 * Unicode are not escaped, integers and numeric strings stay apart, floats are
 * written with `serialize_precision` -1, and a float without a fraction whose
 * magnitude is below 2^53 is written as an integer (`-0.0` as `0`), because a
 * suspended state does not keep the fraction; PHP writes larger integral
 * floats up to about 1e17 without a fraction too, so they share the digest of
 * the equal integer, as they do after a resume. A value that cannot be
 * encoded, nested too deep for the running PHP included, gets no digest. A new canonical form is a new version prefix.
 *
 * A tool and a rule, in nr-llm or outside it, compute the same digests with
 * this one class, so the read behind a state digest is the same on both
 * sides; which fields count is theirs to declare alike.
 *
 * @api
 */
final readonly class InvocationDigests
{
    use ReadsTheRunsWorkspaceTrait;
    use SafeCastTrait;

    public const PURPOSE_ARGUMENTS = 'nr_llm.history.arguments';

    public const PURPOSE_STATE = 'nr_llm.history.state';

    private const VERSION = 'v1:';

    /** Floats without a fraction below this magnitude are written as integers. */
    private const EXACT_INTEGER_LIMIT = 9007199254740992.0;

    /** The deepest nesting JSON encodes (json_encode's default depth). */
    private const MAX_DEPTH = 512;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * The digest of a call's arguments, or null when they cannot be encoded
     * or the installation has no encryption key.
     *
     * @param array<array-key, mixed> $arguments
     */
    public function arguments(array $arguments): ?string
    {
        return $this->digest(self::PURPOSE_ARGUMENTS, $arguments);
    }

    /**
     * The listed fields of a record as the run's workspace shows it (ADR-260,
     * the workspace the writing tools write into), and their digest.
     *
     * Read with the deleted restriction only, so a hidden record has a state.
     * Each value is written as a string; null stays null. Null, and so no
     * digest, when that workspace shows no such row (deleted, a version of
     * another workspace, a table without versioning seen from a draft
     * workspace), the table is unknown, the field list is empty, a listed
     * field is not a column of the table, or there is no encryption key.
     *
     * @param list<string> $fields
     */
    public function observe(string $table, int $uid, array $fields, ToolExecutionContext $execution): ?ObservedState
    {
        if ($fields === [] || $uid < 1 || !$this->isKnownTable($table)) {
            return null;
        }

        $workspace = $this->workspaceToRead($execution, $execution->actingBackendUser());
        $row       = $this->row($table, $uid, $workspace);
        if ($row === null) {
            return null;
        }

        $values = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                return null;
            }

            $value          = $row[$field];
            $values[$field] = $value === null ? null : self::toStr($value);
        }

        ksort($values, SORT_STRING);
        $digest = $this->digest(self::PURPOSE_STATE, ['table' => $table, 'uid' => $uid, 'fields' => $values]);

        return $digest === null ? null : new ObservedState($digest, $values);
    }

    /**
     * The row of record `$uid` as `$workspace` shows it, every column; null
     * when that workspace shows none.
     *
     * @return array<string, mixed>|null
     */
    private function row(string $table, int $uid, int $workspace): ?array
    {
        $query = $this->connectionPool->getQueryBuilderForTable($table);
        $query->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $query->select('*')->from($table);

        if ($workspace > 0) {
            // A table without versioning has no draft of its own; what a
            // draft workspace would write there is not this row.
            if (!$this->isWorkspaceAware($table)) {
                return null;
            }

            $query->where(
                $this->shownInWorkspace($query, $table, $workspace),
                $this->isRecordInWorkspace($query, $uid),
            );
        } else {
            $query->where($query->expr()->eq('uid', $query->createNamedParameter($uid, Connection::PARAM_INT)));
            if ($this->isWorkspaceAware($table)) {
                // Live: neither a version nor a record new in a workspace.
                $query->andWhere(
                    $query->expr()->eq('t3ver_wsid', $query->createNamedParameter(0, Connection::PARAM_INT)),
                    $query->expr()->eq('t3ver_oid', $query->createNamedParameter(0, Connection::PARAM_INT)),
                );
            }
        }

        $row = $query->setMaxResults(1)->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function isKnownTable(string $table): bool
    {
        $tca = $GLOBALS['TCA'] ?? null;

        return $table !== '' && is_array($tca) && is_array($tca[$table] ?? null);
    }

    private function digest(string $purpose, mixed $value): ?string
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $system        = is_array($configuration) && is_array($configuration['SYS'] ?? null) ? $configuration['SYS'] : [];
        $key           = $system['encryptionKey'] ?? '';
        if (!is_string($key) || $key === '') {
            return null;
        }

        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            $json = json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        } finally {
            ini_set('serialize_precision', $precision === false ? '-1' : $precision);
        }

        return self::VERSION . hash_hmac('sha256', $json, $key . $purpose);
    }

    /**
     * The value in canonical form, version 1 (see the class docblock).
     *
     * @throws InvalidArgumentException for a value that has no canonical form
     */
    private static function canonical(mixed $value, int $depth = 0): mixed
    {
        // JSON nests 512 levels at most; deeper, or cyclic, has no canonical
        // form and gets no digest instead of recursing without end.
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('A value nested this deep has no canonical form.', 1794060005);
        }

        if (is_float($value)) {
            return is_finite($value) && floor($value) === $value && abs($value) < self::EXACT_INTEGER_LIMIT ? (int) $value : $value;
        }

        if (is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('A value of this type has no canonical form.', 1794060003);
        }

        if (!is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $items  = [];
        foreach ($value as $key => $item) {
            $items[$key] = self::canonical($item, $depth + 1);
        }

        if ($isList) {
            return $items;
        }

        ksort($items, SORT_STRING);
        $object = new stdClass();
        foreach ($items as $key => $item) {
            // A property name cannot start with NUL; such a key has no
            // canonical form here, so the value gets no digest.
            if (str_starts_with((string) $key, "\0")) {
                throw new InvalidArgumentException('A key starting with NUL has no canonical form.', 1794060004);
            }

            $object->{(string) $key} = $item;
        }

        return $object;
    }
}
