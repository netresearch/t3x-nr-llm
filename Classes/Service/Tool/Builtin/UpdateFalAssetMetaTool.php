<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Set the title and the description of ONE managed file (`sys_file`), through
 * the DataHandler, as the acting backend user — the eighth writing tool.
 *
 * It exists because those two fields had no writer. `set_file_alternative_text`
 * writes `alternative` and refuses every other argument by name, so an assistant
 * asked to caption an asset could set its alt text and nothing else, and said
 * so — accurately, and uselessly.
 *
 * WHY A SECOND TOOL RATHER THAN A WIDER FIRST ONE. The obvious alternative is
 * adding `title` and `description` to `set_file_alternative_text`. That tool is
 * shipped and tested, its name states the one field it writes, and its refusal
 * vocabulary is built on writing exactly one — widening it would rename what it
 * does while keeping the name that describes the old behaviour. The two tools
 * are field-disjoint on purpose: this one does NOT write `alternative`, so no
 * file field has two writers and an approver never has to work out which of two
 * cards won. That disjointness is the same line ADR-146 and ADR-180 drew
 * between their writers, and it is the reason the model can be told plainly
 * which tool to reach for.
 *
 * The eighth writer, on the terms the previous seven meet (ADR-135, ADR-146,
 * ADR-180): disabled by default, in the `editing` group, an explicit
 * {@see ToolEffect}, a human approval before every call (ADR-134), a preview at
 * suspend (ADR-136), a write through the DataHandler under the acting user's
 * permissions (ADR-083), a read-after-write verification, and a refusal
 * vocabulary that never confirms a uid exists.
 *
 * It uses the ADR-146 `plan()` shape, as ADR-180 asked the writers after it to.
 * It is the FIRST `plan()` writer that updates an existing record instead of
 * creating one, so {@see PlansOneEditorialWriteTrait::createRecord()} does not
 * apply to it and the datamap and the read-back below are its own. Nothing is
 * extracted for that yet: one implementation is not a shape, and the two
 * pre-ADR-146 writers that also update are deliberately not retrofitted.
 *
 * WHAT IT SHARES WITH `set_file_alternative_text`, and why:
 *
 * - **The permission axis is FAL, not the page tree.** Access is decided by
 *   {@see FalStorageGate::isFileAccessible()} — the configured storage
 *   allow-list, intersected for non-admins with their file mounts. That
 *   allow-list is nr_llm's own barrier and the DataHandler has never heard of
 *   it, so the gate runs BEFORE the write rather than instead of it. Core's
 *   `FileMetadataPermissionsAspect` then asks its own, strictly narrower
 *   question (a WRITABLE file mount, `editMeta`) inside the DataHandler. The
 *   known defect in the file-mount half of that gate — it reads the ambient
 *   user's mounts rather than the explicit acting user's — is nr-llm issue
 *   #672 and is shared with the three read tools; it is not re-stated here.
 * - **It never creates a metadata record.** A `sys_file` without a
 *   `sys_file_metadata` row is refused, in the neutral string. A record this
 *   tool invented would be a record nobody reviewed.
 * - **The default language only** (`sys_language_uid = 0`), with no language
 *   argument. The reasoning is ADR-135's and unchanged: every FAL read path
 *   pins the same language, and so does {@see self::previewCall()}, so the
 *   value an approver reads is the row this tool writes. `checkLanguageAccess(0)`
 *   is still asserted — a backend user may be restricted to languages that do
 *   not include the default one.
 * - **One neutral refusal for everything about the FILE.** A uid in a forbidden
 *   storage, a uid outside the acting user's mounts, a uid no file carries and
 *   a file without a metadata record are byte-identical answers, the same
 *   sentence {@see ReadFalAssetMetaTool} and {@see SetFileAlternativeTextTool}
 *   use. The model cannot learn which of the four it hit and therefore cannot
 *   probe `sys_file` for existence. The price is named rather than hidden: an
 *   editor whose file genuinely lacks metadata has to find that out in the
 *   backend.
 *
 * WHERE IT DIFFERS, and both differences are measured rather than assumed:
 *
 * - **The field-level grant is asked BEFORE the write, and the read-back is per
 *   field.** Core ships `sys_file_metadata.title` with `'exclude' => true`
 *   (`sys_file_metadata.php`), unlike `alternative` and `description`, which
 *   carry no such flag. The DataHandler SKIPS a field the acting user holds no
 *   `non_exclude_fields` grant for — silently, with an empty `errorLog` — and
 *   applies the REST of the datamap. For a one-field writer that is a failure
 *   to detect after the fact; for this one it would be a half-described asset,
 *   because a user granted `description` and not `title` would get the
 *   description written and a failure reported. So the grant is checked first
 *   and the whole call is refused ({@see self::fieldsTheUserMayNotWrite()}),
 *   and the per-field read-back stays as the backstop for everything that
 *   check does not model.
 * - **`title` is bounded in BYTES.** The column is `tinytext` (core's
 *   `ext_tables.sql`), which holds 255 bytes, not 255 characters — a German
 *   title of 250 characters can exceed it and would be truncated by the
 *   database rather than refused here. `description` has no such column bound
 *   (`type: text`), so the tool's own length applies to it. Where an
 *   installation declares a TCA `max`, that wins for either field: it is the
 *   narrower statement and it is the one the backend form enforces.
 *
 * Effect: {@see ToolEffect::IDEMPOTENT_WRITE} — setting named scalar fields to
 * given values converges on repeat, so a reaped-and-requeued run may safely
 * repeat the call. Because the effect is a write, every call is suspended for
 * human approval (ADR-134) and covered by the ADR-112 write fence, which arms
 * on every executing segment since ADR-141.
 */
final readonly class UpdateFalAssetMetaTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface
{
    use SafeCastTrait;
    // The errands, not the decisions: the environment and workspace guards, the
    // bounded DataHandler complaints, the TCA narrowing and the formatting.
    use WritesThroughDataHandlerTrait;
    // The ADR-146 shape: the plan, the viewer gate, the unknown-argument
    // refusal and the acting-user guard. `createRecord()` is not used — this
    // writer updates.
    use PlansOneEditorialWriteTrait;

    /**
     * One string for "no such file", "not in a permitted storage", "outside
     * your file mounts" and "carries no metadata record". Identical to
     * {@see ReadFalAssetMetaTool}'s and {@see SetFileAlternativeTextTool}'s, so
     * every tool addressing an asset by uid denies in the same words.
     */
    private const NOT_PERMITTED = 'Asset not found or not permitted.';

    private const FILE_TABLE = 'sys_file';

    private const METADATA_TABLE = 'sys_file_metadata';

    private const TITLE = 'title';

    private const DESCRIPTION = 'description';

    /** The fields this tool writes; `alternative` is another tool's. */
    private const FIELDS = [self::TITLE, self::DESCRIPTION];

    /** The only language this tool addresses; see the class docblock. */
    private const DEFAULT_LANGUAGE = 0;

    /** The only workspace this tool addresses; see {@see self::fetchMetadata()}. */
    private const LIVE_WORKSPACE = 0;

    /**
     * The `title` column is `tinytext`, which holds 255 BYTES. Checked against
     * the byte length rather than the character count, because that is what the
     * database measures.
     */
    private const MAX_TITLE_BYTES = 255;

    /**
     * The tool's bound for `description`. The column is `text` and the TCA
     * declares no `max`, so nothing else bounds a model-chosen argument — and a
     * file description is a paragraph, not an article.
     */
    private const MAX_DESCRIPTION_LENGTH = 2000;

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'update_fal_asset_meta',
            'Set the title and/or the description of ONE managed file (sys_file), identified by its uid. '
            . 'Writes sys_file_metadata through the TYPO3 DataHandler as the acting backend user, in the live '
            . 'workspace and in the default language only. Pass either field or both; at least one is required, '
            . 'and a field left out keeps its current value. The file must already carry a metadata record — this '
            . 'tool never creates one — and must lie in a permitted storage inside the acting user\'s file mounts. '
            . 'It does NOT write the alternative text: use set_file_alternative_text for that.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file uid of the single file to describe.',
                    ],
                    self::TITLE => [
                        'type'        => 'string',
                        'description' => 'The new title. An empty string clears it, and the backend then falls back '
                            . 'to showing the file name.',
                    ],
                    self::DESCRIPTION => [
                        'type'        => 'string',
                        'description' => 'The new description — a longer caption for editors. An empty string clears it.',
                    ],
                ],
                'required' => ['uid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $this->writableActingUser($context, self::METADATA_TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        /** @var array<string, string> $values */
        $values      = $plan['values'];
        $metadataUid = self::toInt($plan['metadataUid']);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::METADATA_TABLE => [$metadataUid => $values]], [], $user);
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        // Read back before reporting success, field by field. An empty errorLog
        // is NOT proof a value landed: the DataHandler drops a field the acting
        // user lacks the `non_exclude_fields` grant for without logging it, and
        // core ships `title` WITH the exclude flag — so on a stock installation
        // this is the check that catches a half-applied call, not a precaution.
        $missed = $this->fieldsThatDidNotTake($metadataUid, $values);
        if ($missed !== []) {
            return ToolResult::error(sprintf(
                'The update did not take on file [%d] for: %s. The acting backend user is most likely missing the '
                . 'field-level ("exclude field") grant for %s. Nothing else about the call was wrong.',
                self::toInt($plan['uid']),
                implode(', ', $missed),
                implode(', ', array_map(
                    static fn(string $field): string => self::METADATA_TABLE . ':' . $field,
                    $missed,
                )),
            ));
        }

        return ToolResult::text(sprintf(
            'Updated file [%d] "%s": %s.',
            self::toInt($plan['uid']),
            $this->excerpt(self::toStr($plan['fileName'])),
            implode('; ', array_map(
                fn(string $field): string => $field . ' → ' . $this->quoted($values[$field]),
                array_keys($values),
            )),
        ))->withWriteTarget(new RecordReference(self::METADATA_TABLE, $metadataUid), WriteKind::UPDATED);
    }

    /**
     * The before/after this call would produce (ADR-136).
     *
     * Called by the loop when the run suspends for approval — before anything
     * ran, in the RUN's actor context — so the "before" is the row as it stood
     * at the pause. It is a snapshot and not a reservation: this tool writes
     * absolute values, so a human editing the same metadata in between changes
     * what the approver read, never what the write does.
     *
     * Only the fields the call actually sets are listed. A card naming a field
     * the call leaves alone would invite an approver to read its absence as a
     * clearing, which is the one misreading that costs data.
     *
     * NOT checked here, deliberately: the live-workspace and backend-environment
     * refusals. Both describe the PROCESS that performs the write, and that is
     * the approver's request rather than this one.
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

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return [$plan];
        }

        /** @var array<string, string> $values */
        $values = $plan['values'];
        /** @var array<string, string> $current */
        $current = $plan['current'];

        $lines = [sprintf(
            'File [%d] "%s" — metadata (default language):',
            self::toInt($plan['uid']),
            $this->excerpt(self::toStr($plan['fileName'])),
        )];

        foreach ($values as $field => $new) {
            $old     = $current[$field] ?? '';
            $lines[] = $old === $new
                ? sprintf('%s: unchanged (%s)', $field, $this->quoted($new))
                : sprintf('%s: %s → %s', $field, $this->quoted($old), $this->quoted($new));
        }

        return $lines;
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
        // Setting named scalar fields to given values converges on repeat.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * The human-facing declaration (ADR-152).
     *
     * The declared record type is `sys_file` — the uid the call names and the
     * record an editor selects. The row this tool writes is that file's
     * `sys_file_metadata`, which is a consequence of the action rather than its
     * subject.
     */
    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_fal_asset_meta.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_fal_asset_meta.description',
            'nrllm-editor-action-file-meta',
            [self::FILE_TABLE],
        );
    }

    /**
     * Everything the write needs, resolved and authorised against the given
     * user — or the refusal message that stops it (ADR-146).
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{uid: int, fileName: string, metadataUid: int, values: array<string, string>, current: array<string, string>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['uid', self::TITLE, self::DESCRIPTION],
            sprintf('sets "%s" and "%s" on one file', self::TITLE, self::DESCRIPTION),
        );
        if ($unknown !== null) {
            return $unknown;
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return 'Refused: "uid" must be the positive sys_file uid of exactly one file.';
        }

        $values = $this->collectValues($arguments);
        if (is_string($values)) {
            return $values;
        }

        $ungranted = $this->fieldsTheUserMayNotWrite($user, array_keys($values));
        if ($ungranted !== []) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was '
                . 'written — a call that set the other field and reported a failure would leave the asset half '
                . 'described.',
                implode(', ', array_map(
                    static fn(string $field): string => self::METADATA_TABLE . ':' . $field,
                    $ungranted,
                )),
            );
        }

        $target = $this->resolveTarget($user, $uid);
        if ($target === null) {
            return self::NOT_PERMITTED;
        }

        [$file, $metadata] = $target;

        $current = [];
        foreach (array_keys($values) as $field) {
            $current[$field] = self::toStr($metadata[$field] ?? '');
        }

        return [
            'uid'         => $uid,
            'fileName'    => self::toStr($file['name'] ?? ''),
            'metadataUid' => self::toInt($metadata['uid'] ?? 0),
            'values'      => $values,
            'current'     => $current,
        ];
    }

    /**
     * The validated field values, or a refusal message.
     *
     * A field the caller left out is absent from the result rather than present
     * and empty — an omitted argument keeps the stored value, and an empty
     * string clears it. Collapsing the two would make "set the title" also
     * erase the description, which is the one way a metadata writer destroys
     * work nobody asked it to touch.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, string>|string
     */
    private function collectValues(array $arguments): array|string
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }

            $raw = $arguments[$field];
            if ($raw === null) {
                return sprintf(
                    'Refused: "%s" was passed as null. Omit it to keep the current value, or pass an empty string to clear it.',
                    $field,
                );
            }

            if (!is_string($raw) && !is_numeric($raw)) {
                return sprintf('Refused: the value for "%s" must be a string.', $field);
            }

            $text    = trim(self::toStr($raw));
            $refusal = $this->refuseOverlongValue($field, $text);
            if ($refusal !== null) {
                return $refusal;
            }

            $values[$field] = $text;
        }

        if ($values === []) {
            return sprintf(
                'Refused: pass at least one of "%s" or "%s". A call that sets neither would change nothing and '
                . 'would still cost an approval.',
                self::TITLE,
                self::DESCRIPTION,
            );
        }

        return $values;
    }

    /**
     * The refusal when a value exceeds what the column or the TCA allows, or
     * null when it fits.
     *
     * `title` is measured in BYTES against `tinytext`'s 255; `description` in
     * characters against this tool's own bound. A TCA `max` an installation
     * declares is narrower than both and wins — it is what the backend form
     * enforces, so a tool that accepted more would write what an editor could
     * not.
     */
    private function refuseOverlongValue(string $field, string $value): ?string
    {
        $declared = $this->declaredMaxFor($field);
        if ($declared !== null && mb_strlen($value) > $declared) {
            return sprintf('Refused: the value for "%s" exceeds the %d characters this installation allows.', $field, $declared);
        }

        if ($field === self::TITLE) {
            // Bytes, not characters: the column is `tinytext`. A title of 250
            // characters with umlauts is over 255 bytes and would be truncated
            // by the database rather than refused here.
            return strlen($value) > self::MAX_TITLE_BYTES
                ? sprintf(
                    'Refused: the value for "%s" is %d bytes and the column holds %d. Note that this is a byte '
                    . 'limit — accented and non-Latin characters count for more than one.',
                    $field,
                    strlen($value),
                    self::MAX_TITLE_BYTES,
                )
                : null;
        }

        return mb_strlen($value) > self::MAX_DESCRIPTION_LENGTH
            ? sprintf('Refused: the value for "%s" exceeds %d characters.', $field, self::MAX_DESCRIPTION_LENGTH)
            : null;
    }

    /**
     * The TCA's own bound for a field, where an installation declares one.
     */
    private function declaredMaxFor(string $field): ?int
    {
        $column = $this->tcaColumnsFor(self::METADATA_TABLE)[$field] ?? null;
        $config = is_array($column) ? ($column['config'] ?? null) : null;
        $max    = is_array($config) ? ($config['max'] ?? null) : null;

        return is_int($max) && $max > 0 ? $max : null;
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
        // access to the default language (checked against the explicit user,
        // ADR-083).
        if (!$user->checkLanguageAccess(self::DEFAULT_LANGUAGE)) {
            return null;
        }

        $metadata = $this->fetchMetadata($uid);

        return $metadata === null ? null : [$file, $metadata];
    }

    /**
     * The `sys_file` row, or null when no file carries that uid.
     *
     * `sys_file` has no enable columns (no `deleted`, no `hidden`), so there is
     * no restriction to keep — the storage gate is the access decision. It does
     * NOT go through {@see PlansOneEditorialWriteTrait::fetchRowByUid()} for
     * exactly that reason: that helper adds the deleted restriction, which this
     * table has no column for.
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
     * The LIVE, DEFAULT-language metadata row of a file, or null when it has
     * none.
     *
     * A file is looked up by `file`, not by uid, so more than one row can match
     * and every pin below decides WHICH row this tool writes — the same three
     * {@see SetFileAlternativeTextTool::fetchMetadata()} pins, and core's own
     * {@see \TYPO3\CMS\Core\Resource\Index\MetaDataRepository::findByFileUid()}:
     *
     * - the LANGUAGE, because `sys_file_metadata` is language-aware and
     *   `removeAll()` drops the language restriction — an arbitrary translation
     *   could otherwise be written instead of the original;
     * - the WORKSPACE, because the table is workspace-aware and a draft version
     *   carries the same `file` and the same `sys_language_uid = 0` as the live
     *   row it versions. Without the restriction the tool could write a
     *   stranger's unpublished draft, leave the live values untouched and still
     *   report success: {@see self::fieldsThatDidNotTake()} re-reads by the uid
     *   it wrote, so it would confirm the wrong row rather than catch it;
     * - the ORDER, because two candidate rows and no `ORDER BY` leave the choice
     *   to the database.
     *
     * @return array<string, mixed>|null
     */
    private function fetchMetadata(int $fileUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::METADATA_TABLE);
        // Live only. The tool refuses outside the live workspace anyway, and the
        // preview must resolve the same row the write would target.
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, self::LIVE_WORKSPACE));

        $row = $queryBuilder
            ->select('uid', self::TITLE, self::DESCRIPTION)
            ->from(self::METADATA_TABLE)
            ->where(
                $queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(self::DEFAULT_LANGUAGE, Connection::PARAM_INT),
                ),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The fields among `$fields` that this user may not write, because the TCA
     * marks them `exclude` and the user holds no `non_exclude_fields` grant.
     *
     * Asked BEFORE the write rather than inferred from the read-back, and that
     * ordering is the point: the DataHandler skips such a field silently and
     * applies the rest of the datamap, so a call setting both fields for a user
     * granted only one would half-succeed and then be reported as a failure.
     * Refusing first leaves the asset as it was.
     *
     * It is deliberately the SAME question the DataHandler asks, through the
     * same method — `BackendUserAuthentication::check('non_exclude_fields', …)`,
     * `DataHandler::fillInFieldArray()` — so the two cannot drift apart. Note
     * that `check()` tests `isset($groupData[$type])` BEFORE `isAdmin()`, so a
     * user object assembled without group data is refused here exactly as the
     * DataHandler would refuse it, admin or not.
     *
     * {@see self::fieldsThatDidNotTake()} stays as the backstop: this asks
     * about the one refusal that is silent, not about every reason a write may
     * fail to land.
     *
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private function fieldsTheUserMayNotWrite(BackendUserAuthentication $user, array $fields): array
    {
        $columns = $this->tcaColumnsFor(self::METADATA_TABLE) ?? [];

        $ungranted = [];
        foreach ($fields as $field) {
            $column = $columns[$field] ?? null;
            // `exclude` is what core's schema reports as supportsAccessControl();
            // reading it here keeps the tool free of a schema dependency for one
            // boolean.
            $excluded = is_array($column) && ($column['exclude'] ?? false) === true;
            if ($excluded && !$user->check('non_exclude_fields', self::METADATA_TABLE . ':' . $field)) {
                $ungranted[] = $field;
            }
        }

        return $ungranted;
    }

    /**
     * The fields whose stored value is NOT the requested one after the write.
     *
     * A field the DataHandler dropped without complaining reads back unchanged,
     * and core ships `title` with the `exclude` flag, so that is the ordinary
     * case for a non-admin rather than a remote one. A row that vanished between
     * the write and the read-back verifies nothing, so every field is reported
     * as not taken.
     *
     * @param array<string, string> $values
     *
     * @return list<string>
     */
    private function fieldsThatDidNotTake(int $metadataUid, array $values): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::METADATA_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select(...array_keys($values))
            ->from(self::METADATA_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($metadataUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return array_keys($values);
        }

        $missed = [];
        foreach ($values as $field => $expected) {
            if (self::toStr($row[$field] ?? '') !== $expected) {
                $missed[] = $field;
            }
        }

        return $missed;
    }
}
