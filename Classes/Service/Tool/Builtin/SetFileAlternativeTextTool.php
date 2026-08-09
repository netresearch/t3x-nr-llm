<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Set the alternative text of ONE managed file, through the DataHandler, as the
 * acting backend user — the second writing tool (ADR-135).
 *
 * It follows {@see UpdatePageMetadataTool} deliberately rather than inventing a
 * second vocabulary: the same live-workspace restriction, the same
 * backend-environment refusal, the same read-back verification, the same
 * before/after preview, `IDEMPOTENT_WRITE`, off by default, non-admin usable.
 * Where it differs, it differs because `sys_file_metadata` is not `pages`:
 *
 * - **The permission axis is FAL, not the page tree.** Access is decided by
 *   {@see FalStorageGate::isFileAccessible()} against the EXPLICIT acting user
 *   (ADR-083): the configured storage allow-list, intersected for non-admins
 *   with their file mounts. That allow-list is nr_llm's own barrier — the
 *   DataHandler has never heard of it, so the gate has to run BEFORE the write,
 *   not instead of it. Core's `FileMetadataPermissionsAspect` then enforces its
 *   own rule on top (a WRITABLE file mount, `editMeta`), which is strictly the
 *   narrower question: a read-only mount passes this tool's gate and is refused
 *   by the DataHandler.
 * - **It never creates a metadata record.** A `sys_file` without a
 *   `sys_file_metadata` row is refused. Creating one would make a tool named
 *   "set the alternative text" also an indexer, and a record it invented is a
 *   record nobody reviewed.
 * - **It works on the DEFAULT language only** (`sys_language_uid = 0`), takes no
 *   language argument and refuses when the default-language record is absent.
 *   Rationale in ADR-135, "The second writer and the language question": the
 *   read tool a model uses to see the current value
 *   ({@see ReadFalAssetMetaTool}) pins the same language, so writer and reader
 *   address the same row; a model-chosen language argument would let a call
 *   land on a translation nothing reads back. A translated alt text stays a job
 *   for the backend. `checkLanguageAccess(0)` is still asserted, because a
 *   backend user may be restricted to languages that do not include the default
 *   one.
 *
 * Every refusal that concerns the FILE uses one neutral string, the same one
 * {@see ReadFalAssetMetaTool} answers with: a uid in a forbidden storage, a uid
 * outside the acting user's mounts, a uid no file carries and a file without a
 * metadata record are byte-identical answers. The model may not learn which of
 * the four it hit, and therefore cannot probe `sys_file` for existence. The
 * price is named rather than hidden: the model cannot tell "not yours" from
 * "not indexed", and an editor whose file genuinely lacks metadata has to find
 * that out in the backend.
 *
 * Success is verified rather than assumed: an empty `errorLog` is no proof the
 * value landed, because the DataHandler SKIPS a field the acting user holds no
 * `non_exclude_fields` grant for without logging anything. Core ships
 * `sys_file_metadata.alternative` without the `exclude` flag, so that grant is
 * not required as shipped — but the flag is one TCA override away, and a tool
 * whose whole premise is that a human approved a specific change must not
 * report a write that did not happen.
 *
 * Deliberately NOT checked here: `tables_modify` for `sys_file_metadata`. Core's
 * aspect overrides that list for this table and decides on file write access
 * instead, so asserting it would refuse editors the backend itself allows.
 *
 * Effect: {@see ToolEffect::IDEMPOTENT_WRITE} — setting one scalar field to a
 * given value converges on repeat. Because the effect is a write, every call is
 * suspended for human approval (ADR-134); the tool carries no separate approval
 * marker. As with the first writer it is NOT covered by the ADR-112 write fence,
 * which arms only on the queued path (ADR-135).
 */
final readonly class SetFileAlternativeTextTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;

    /**
     * One string for "no such file", "not in a permitted storage", "outside your
     * file mounts" and "carries no metadata record", so a refusal never confirms
     * that a uid exists. Identical to {@see ReadFalAssetMetaTool}'s, so the read
     * and the write tool deny in the same words.
     */
    private const NOT_PERMITTED = 'Asset not found or not permitted.';

    private const FILE_TABLE = 'sys_file';

    private const METADATA_TABLE = 'sys_file_metadata';

    /** The one field this tool writes. */
    private const FIELD = 'alternative';

    /** The only language this tool addresses; see the class docblock. */
    private const DEFAULT_LANGUAGE = 0;

    /**
     * Upper bound for the value. The DB column is `text` and the TCA declares no
     * `max`, so nothing else bounds a model-chosen argument — and an alternative
     * text is a sentence, not a document.
     */
    private const MAX_VALUE_LENGTH = 1000;

    /** How many DataHandler complaints are echoed back, and how long each may be. */
    private const MAX_ERRORS = 5;

    private const MAX_ERROR_LENGTH = 200;

    /** How much of a value the approval preview shows, as in the first writer. */
    private const PREVIEW_EXCERPT_LENGTH = 120;

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'set_file_alternative_text',
            'Set the alternative text (alt text) of ONE managed file (sys_file), identified by its uid. '
            . 'Writes sys_file_metadata through the TYPO3 DataHandler as the acting backend user, in the live '
            . 'workspace and in the default language only. The file must already carry a metadata record — this '
            . 'tool never creates one — and must lie in a permitted storage inside the acting user\'s file mounts. '
            . 'Pass an empty string to mark a purely decorative image as such.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file uid of the single file to describe.',
                    ],
                    self::FIELD => [
                        'type'        => 'string',
                        'description' => 'The new alternative text. An empty string marks the image as decorative.',
                    ],
                ],
                'required' => ['uid', self::FIELD],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $missing = $this->missingBackendEnvironment();
        if ($missing !== []) {
            return ToolResult::error(sprintf(
                'Refused: writing needs a full backend environment, and this process has no %s. '
                . 'Run this tool from a backend request rather than a bare worker process.',
                implode(' and no ', $missing),
            ));
        }

        if ($user->workspace !== 0) {
            return ToolResult::error(
                'Refused: this tool only edits the live workspace. Switch out of the draft workspace and retry.',
            );
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return ToolResult::error('Refused: "uid" must be the positive sys_file uid of exactly one file.');
        }

        $value = $this->collectValue($arguments);
        if (is_string($value)) {
            return ToolResult::error($value);
        }

        $target = $this->resolveTarget($user, $uid);
        if ($target === null) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        [$file, $metadata] = $target;
        $metadataUid       = self::toInt($metadata['uid'] ?? 0);
        $text              = $value[0];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::METADATA_TABLE => [$metadataUid => [self::FIELD => $text]]], [], $user);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            return ToolResult::error(sprintf(
                'The update was refused by TYPO3: %s',
                $this->summariseErrors($dataHandler->errorLog),
            ));
        }

        // Read back before reporting success. An empty errorLog is NOT proof the
        // value landed: the DataHandler SKIPS a field the acting user lacks the
        // `non_exclude_fields` grant for, silently and without logging.
        if (!$this->valueTook($metadataUid, $text)) {
            return ToolResult::error(sprintf(
                'The alternative text did not take on file [%d]. The acting backend user is most likely missing '
                . 'the field-level ("exclude field") grant for %s:%s.',
                $uid,
                self::METADATA_TABLE,
                self::FIELD,
            ));
        }

        return ToolResult::text(sprintf(
            'Set the alternative text of file [%d] "%s" to %s.',
            $uid,
            $this->excerpt(self::toStr($file['name'] ?? '')),
            $this->quoted($text),
        ));
    }

    /**
     * The before/after this call would produce (ADR-136).
     *
     * Called by the loop when the run suspends for approval — before anything
     * ran, in the RUN's actor context — so the "before" is the row as it stood
     * at the pause. It is a snapshot and not a reservation: this tool writes an
     * absolute value, so a human editing the same metadata in between changes
     * what the approver read, never what the write does.
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string: a preview can
     * never show a file the run itself could not have written, and cannot be
     * used to probe `sys_file` for existence.
     *
     * NOT checked here, deliberately: the live-workspace and backend-environment
     * refusals. Both describe the PROCESS that performs the write, and that is
     * the approver's request, not this one.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return [self::NOT_PERMITTED];
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return ['Refused: "uid" must be the positive sys_file uid of exactly one file.'];
        }

        $value = $this->collectValue($arguments);
        if (is_string($value)) {
            return [$value];
        }

        $target = $this->resolveTarget($user, $uid);
        if ($target === null) {
            return [self::NOT_PERMITTED];
        }

        [$file, $metadata] = $target;
        $new               = $value[0];
        $old               = self::toStr($metadata[self::FIELD] ?? '');

        return [
            sprintf('File [%d] "%s" — alternative text (default language):', $uid, $this->excerpt(self::toStr($file['name'] ?? ''))),
            $old === $new
                ? sprintf('%s: unchanged (%s)', self::FIELD, $this->quoted($new))
                : sprintf('%s: %s → %s', self::FIELD, $this->quoted($old), $this->quoted($new)),
        ];
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default: an admin enables it in the
        // Tools module deliberately, on top of the group gate and the approval
        // pause every write already carries (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the storage gate authorises the ACTING user's
        // own file mounts, and core's FileMetadataPermissionsAspect enforces
        // write access to the same file a second time inside the DataHandler.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135), not `files`: a configuration that
        // already grants the read-only FAL tools must not inherit write
        // capability because a new tool joined that group.
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // Setting one named scalar field to a given value converges on repeat,
        // so a reaped-and-requeued run may safely repeat it.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * The file row and its default-language metadata row, or null when the
     * acting user may not reach the file, no file carries the uid, or the file
     * carries no metadata record — all four collapse into one answer on purpose.
     *
     * @return array{array<string, mixed>, array<string, mixed>}|null
     */
    private function resolveTarget(BackendUserAuthentication $user, int $uid): ?array
    {
        $file = $this->fetchFile($uid);
        if ($file === null) {
            return null;
        }

        // nr_llm's own barrier, and it has to run here: the allow-list is this
        // extension's configuration and the DataHandler cannot consult it.
        if (!$this->storageGate->isFileAccessible($user, self::toInt($file['storage'] ?? 0), self::toStr($file['identifier'] ?? ''))) {
            return null;
        }

        // The tool writes the default-language record, so the acting user needs
        // access to the default language — a user restricted to other languages
        // may not write it (checked against the explicit user, ADR-083).
        if (!$user->checkLanguageAccess(self::DEFAULT_LANGUAGE)) {
            return null;
        }

        $metadata = $this->fetchMetadata($uid);
        if ($metadata === null) {
            return null;
        }

        return [$file, $metadata];
    }

    /**
     * The globals the DataHandler declares as its prerequisites and does not
     * establish itself; `start()` sets only its own `$BE_USER`, so a hook
     * running inside the write still reads the ambient one. Core's own
     * `FileMetadataPermissionsAspect` is exactly such a hook, which makes the
     * check load-bearing here rather than merely declared (ADR-135).
     *
     * @return list<string>
     */
    private function missingBackendEnvironment(): array
    {
        $missing = [];
        if ($this->metadataColumns() === null) {
            $missing[] = 'TCA';
        }

        if (!(($GLOBALS['LANG'] ?? null) instanceof LanguageService)) {
            $missing[] = 'language service';
        }

        if (!(($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication)) {
            $missing[] = 'backend user';
        }

        return $missing;
    }

    /**
     * The `sys_file_metadata` column definitions from the live TCA, or null when
     * no TCA is loaded. Narrowed step by step because `$GLOBALS` is untyped.
     *
     * @return array<array-key, mixed>|null
     */
    private function metadataColumns(): ?array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !is_array($tca[self::METADATA_TABLE] ?? null)) {
            return null;
        }

        $columns = $tca[self::METADATA_TABLE]['columns'] ?? null;

        return is_array($columns) ? $columns : null;
    }

    /**
     * The validated alternative text, or a refusal message.
     *
     * The value is returned WRAPPED in a one-element list so a perfectly valid
     * alternative text can never be mistaken for a refusal message — both are
     * strings, and this tool's whole subject is a free-text value.
     *
     * An argument this tool does not know refuses the WHOLE call: a model asking
     * it to set a `title` must hear "no", not silently get an alt text instead.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list{string}|string
     */
    private function collectValue(array $arguments): array|string
    {
        foreach (array_keys($arguments) as $key) {
            if ($key === 'uid') {
                continue;
            }

            if ($key === self::FIELD) {
                continue;
            }

            return sprintf(
                'Refused: "%s" is not an argument of this tool. It sets only "%s" on one file; use a different '
                . 'tool for anything else.',
                // The key is echoed back so the model can correct itself; it is a
                // name the model itself chose, not instance data.
                preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '',
                self::FIELD,
            );
        }

        $raw = $arguments[self::FIELD] ?? null;
        if ($raw === null) {
            return sprintf('Refused: "%s" is required — pass the new alternative text (an empty string marks a decorative image).', self::FIELD);
        }

        if (!is_string($raw) && !is_numeric($raw)) {
            return sprintf('Refused: the value for "%s" must be a string.', self::FIELD);
        }

        $text = trim(self::toStr($raw));
        $max  = $this->maxLength();
        if (mb_strlen($text) > $max) {
            return sprintf('Refused: the value for "%s" exceeds %d characters.', self::FIELD, $max);
        }

        return [$text];
    }

    /**
     * The TCA's own bound for the field where an installation declares one,
     * otherwise the tool's bound for the unbounded core column.
     */
    private function maxLength(): int
    {
        $column = $this->metadataColumns()[self::FIELD] ?? null;
        $config = is_array($column) ? ($column['config'] ?? null) : null;
        $max    = is_array($config) ? ($config['max'] ?? null) : null;

        return is_int($max) && $max > 0 ? $max : self::MAX_VALUE_LENGTH;
    }

    /**
     * The `sys_file` row, or null when no file carries that uid.
     *
     * `sys_file` has no enable columns (no `deleted`, no `hidden`), so there is
     * no restriction to keep — the storage gate is the access decision.
     *
     * @return array<string, mixed>|null
     */
    private function fetchFile(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::FILE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid', 'storage', 'identifier', 'name')
            ->from(self::FILE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The DEFAULT-language metadata row of a file, or null when it has none.
     *
     * `sys_file_metadata` is language-aware and `removeAll()` drops the language
     * restriction, so the language is pinned explicitly — otherwise an arbitrary
     * translation could be picked up and written instead of the original.
     *
     * @return array<string, mixed>|null
     */
    private function fetchMetadata(int $fileUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::METADATA_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid', self::FIELD)
            ->from(self::METADATA_TABLE)
            ->where(
                $queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(self::DEFAULT_LANGUAGE, Connection::PARAM_INT),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Whether the stored value IS the requested one after the write — a field
     * the DataHandler dropped without complaining reads back unchanged.
     */
    private function valueTook(int $metadataUid, string $value): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::METADATA_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select(self::FIELD)
            ->from(self::METADATA_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($metadataUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        // The row vanished between the write and the read-back. Nothing was
        // verified, so nothing may be claimed.
        return is_array($row) && self::toStr($row[self::FIELD] ?? '') === $value;
    }

    /**
     * A value as it appears in output: quoted, or the explicit `(empty)` marker
     * — an empty pair of quotes reads like a rendering bug, and "this alt text
     * is empty" is exactly the decorative-image case an approver must see.
     */
    private function quoted(string $value): string
    {
        return $value === '' ? '(empty)' : '"' . $this->excerpt($value) . '"';
    }

    /**
     * One line's worth of a value: whitespace collapsed and truncated.
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
     * ever shown to a caller that already passed the storage and mount gate, so
     * they cannot disclose the existence of a file the caller may not see.
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
