<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The descriptive metadata of ONE managed file, for the acting backend user
 * (ADR-303, spec 058).
 *
 * `read_fal_asset_meta` answers the same question for administrators only
 * (ADR-047) and stays that way. This tool answers it for a non-admin with the
 * permissions the backend applies when it shows that file: the file must be
 * readable for the acting user ({@see FalStorageGate::isFileAccessible()} —
 * the storage allow-list and, for a non-admin, the user's storages and file
 * mounts), and every column the TCA marks `exclude` needs the user's
 * `non_exclude_fields` grant. That pair is what core's own read-only file view,
 * `ElementInformationController`, checks; it asks for no `tables_select` grant
 * on `sys_file_metadata`, and neither does this.
 *
 * Three answers per field, and they never blur: a value (as a JSON string
 * literal, so it cannot pass for a marker or start a line of its own),
 * {@see self::NOT_SET} and {@see self::WITHHELD}. A withheld column is not
 * selected, so nothing about its stored value can shape the answer.
 *
 * Everything about the FILE collapses into one neutral string: a uid outside
 * the acting user's mounts, a uid no file carries and a run without a user are
 * byte-identical, so the model cannot probe `sys_file` for existence. A file
 * the user may read but that has no metadata record is reported as not set —
 * that file is already known to exist for this user.
 *
 * The row is the default-language record as the run's workspace shows it
 * ({@see ReadsTheRunsWorkspaceTrait}, ADR-263). The query that selects it is
 * the same one `read_fal_asset_meta` runs; that tool is left unchanged on
 * purpose, so the two copies are not merged here.
 */
final readonly class DescribeFalAssetTool implements ToolInterface, ToolEffectInterface
{
    use DeclaresReadEffectTrait;

    use SafeCastTrait;
    use FetchesSysFileRowTrait;
    use ReadsTheRunsWorkspaceTrait;

    /** The sentence every FAL tool denies a file in. */
    private const NOT_PERMITTED = 'Asset not found or not permitted.';

    /** A field without a value. Bare, so it differs from a stored text. */
    private const NOT_SET = 'not set';

    /** A field the acting user holds no field-level grant for. */
    private const WITHHELD = 'withheld (no field permission)';

    private const METADATA_TABLE = 'sys_file_metadata';

    private const LIVE_WORKSPACE = 0;

    private const DEFAULT_LANGUAGE = 0;

    /** Core's columns, always reported. */
    private const CORE_TEXT_FIELDS = ['title', 'alternative', 'description'];

    private const CORE_DIMENSION_FIELDS = ['width', 'height'];

    /** EXT:filemetadata's columns, reported only where the TCA declares them. */
    private const OPTIONAL_TEXT_FIELDS = ['copyright', 'creator', 'source', 'publisher', 'language'];

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'describe_fal_asset',
            'Return the descriptive metadata of ONE managed file (sys_file) identified by its uid, as the acting '
            . 'backend user may see it: title, alternative text, description, width and height, plus copyright, '
            . 'creator, source, publisher and language where the installation has those fields. Default language '
            . 'only. A field reads "not set" when it has no value — that means unknown, not free to use: never '
            . 'infer usage rights from a missing value or from the file name. A field reads "withheld" when the '
            . "user may not see it. Only files inside the user's file mounts are described.",
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file uid of the file to describe.',
                    ],
                ],
                'required' => ['uid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        $file = $this->fetchFile(self::toInt($arguments['uid'] ?? 0));

        // One answer for "no user", "no such file" and "not yours".
        if (!$user instanceof BackendUserAuthentication || $file === null || !$this->storageGate->isFileAccessible(
            $user,
            self::toInt($file['storage'] ?? 0),
            self::toStr($file['identifier'] ?? ''),
        )) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $fields   = $this->reportedFields();
        $withheld = $this->withheldFields($user, $fields);
        $readable = array_values(array_diff($fields, $withheld));
        $metadata = $readable === []
            ? []
            : $this->fetchMetadata(self::toInt($file['uid'] ?? 0), $this->workspaceToRead($context, $user), $readable);

        // The name is encoded like every value: a file placed in the storage
        // outside TYPO3 can carry a line break in its name.
        $lines = [sprintf('File [%d] %s, metadata in the default language:', self::toInt($file['uid'] ?? 0), $this->quoted(self::toStr($file['name'] ?? '')))];
        foreach ($fields as $field) {
            $lines[] = $field . ': ' . (in_array($field, $withheld, true) ? self::WITHHELD : $this->rendered($field, $metadata[$field] ?? null));
        }

        return ToolResult::text(implode("\n", $lines));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Usable by non-admins: the storage gate checks the acting user's file
        // mounts and the field-level grant is checked per column.
        return false;
    }

    public function getGroup(): string
    {
        return 'files';
    }

    /**
     * The fields this installation reports, in a fixed order: core's, then
     * those of EXT:filemetadata whose column the live TCA declares. A column
     * the TCA does not declare is not named in any query.
     *
     * @return list<non-empty-string>
     */
    private function reportedFields(): array
    {
        $columns = $this->metadataColumns();
        $fields  = [...self::CORE_TEXT_FIELDS, ...self::CORE_DIMENSION_FIELDS];
        foreach (self::OPTIONAL_TEXT_FIELDS as $field) {
            if (isset($columns[$field])) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The fields among `$fields` the user may not see: the TCA marks them
     * `exclude` and a non-admin holds no `non_exclude_fields` grant — the
     * question core's `VisibleSchemaFieldsCollector` asks for the file view.
     * `exclude` is read as a boolean cast, as core's schema reads it.
     *
     * @param list<non-empty-string> $fields
     *
     * @return list<non-empty-string>
     */
    private function withheldFields(BackendUserAuthentication $user, array $fields): array
    {
        if ($user->isAdmin()) {
            return [];
        }

        $columns  = $this->metadataColumns();
        $withheld = [];
        foreach ($fields as $field) {
            $column = $columns[$field] ?? null;
            if (is_array($column) && (bool) ($column['exclude'] ?? false)
                && !$user->check('non_exclude_fields', self::METADATA_TABLE . ':' . $field)
            ) {
                $withheld[] = $field;
            }
        }

        return $withheld;
    }

    /**
     * The default-language metadata row of a file as `$workspace` shows it,
     * or an empty array when there is none.
     *
     * @param non-empty-list<non-empty-string> $columns
     *
     * @return array<string, mixed>
     */
    private function fetchMetadata(int $fileUid, int $workspace, array $columns): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::METADATA_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        if ($this->readsWorkspaceRows(self::METADATA_TABLE, $workspace)) {
            // The one row the run's draft workspace shows for the file: its
            // version there, or the live row it did not change (ADR-263).
            $queryBuilder->where($this->shownInWorkspace($queryBuilder, self::METADATA_TABLE, $workspace));
        } else {
            // Live only: a file with a draft version has a second row with the
            // same `file` and language, and that draft is no visitor's value.
            $queryBuilder->getRestrictions()
                ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, self::LIVE_WORKSPACE));
        }

        $row = $queryBuilder
            ->select(...$columns)
            ->from(self::METADATA_TABLE)
            ->andWhere(
                $queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                // removeAll() drops the language restriction: pin the default
                // language, or a translation could stand in for the original.
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(self::DEFAULT_LANGUAGE, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    /**
     * A stored value as one line: a dimension as its number, a text as a JSON
     * string literal, anything empty as {@see self::NOT_SET}.
     */
    private function rendered(string $field, mixed $value): string
    {
        if (in_array($field, self::CORE_DIMENSION_FIELDS, true)) {
            $number = self::toInt($value);

            return $number > 0 ? (string) $number : self::NOT_SET;
        }

        $text = trim(self::toStr($value));
        if ($text === '') {
            return self::NOT_SET;
        }

        return $this->quoted($text);
    }

    /**
     * A text as a JSON string literal. Umlauts stay readable; every line
     * break and C1 control, NEL included, is escaped, so a value cannot start
     * a line of its own.
     */
    private function quoted(string $text): string
    {
        $literal = (string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return preg_replace_callback(
            '/[\x{0080}-\x{009F}\x{2028}\x{2029}]/u',
            static fn (array $match): string => sprintf('\\u%04x', mb_ord($match[0], 'UTF-8')),
            $literal,
        ) ?? $literal;
    }

    /**
     * The column definitions of `sys_file_metadata` in the live TCA, or an
     * empty array when no TCA is loaded.
     *
     * @return array<array-key, mixed>
     */
    private function metadataColumns(): array
    {
        $tca     = $GLOBALS['TCA'] ?? null;
        $table   = is_array($tca) ? ($tca[self::METADATA_TABLE] ?? null) : null;
        $columns = is_array($table) ? ($table['columns'] ?? null) : null;

        return is_array($columns) ? $columns : [];
    }
}
