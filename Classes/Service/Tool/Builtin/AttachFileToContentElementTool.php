<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
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
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * References an EXISTING managed file from a content element (#834).
 *
 * The seventh purpose-built writer, on the terms of the six before it
 * (ADR-135/146/180). It creates exactly one `sys_file_reference` row through
 * the DataHandler as the acting backend user, in the live workspace. It does
 * not upload, move, copy or rename anything: the file must already exist in a
 * storage the {@see FalStorageGate} allows and inside the acting user's file
 * mounts, and the element must be editable by that user.
 *
 * **One datamap, and the parent list carries the placeholder.** The element's
 * own reference counter and the new reference's `sorting_foreign` both come out
 * right from that single write, so appending needs no second pass and no
 * `position` argument. That was worth measuring rather than assuming: a probe
 * that ran the DataHandler WITHOUT `$GLOBALS['LANG']` produced a counter one
 * short and a reference that sorted first, which reads exactly like a real
 * defect. It is an artefact of the incomplete environment this tool refuses to
 * run in — {@see self::execute()} checks for it before writing — and the
 * read-back below is what proves the difference rather than a comment.
 *
 * **The `allowed` list is a refusal, not a preference.** Each file field on
 * `tt_content` declares which extensions it accepts, and they differ sharply —
 * `image` takes fourteen, `assets` twenty-seven, `media` anything. Attaching a
 * `.docx` to `image` is not a field choice, it is a relation the FormEngine
 * would reject, so this tool refuses it rather than writing it.
 *
 * There is no `position` argument. The two-pass write appends by construction,
 * and an argument nothing reads is worse than none.
 *
 * Effect: {@see ToolEffect::NON_IDEMPOTENT_WRITE} — a second call attaches the
 * same file a second time, which is a legitimate thing to want and not
 * something to silently deduplicate. Every call is suspended for human approval
 * because the effect is a write (ADR-134), and the ADR-112 write fence covers it
 * through the per-segment lease (ADR-141).
 */
final readonly class AttachFileToContentElementTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface
{
    use SafeCastTrait;
    use WritesThroughDataHandlerTrait;

    /**
     * One string for "no such element", "no such file", "not in a permitted
     * storage", "outside your file mounts" and "you may not edit that page", so
     * a refusal never confirms that a uid exists. The same shape the other FAL
     * tools deny in.
     */
    private const NOT_PERMITTED = 'Content element or file not found, or not permitted.';

    private const CONTENT_TABLE = 'tt_content';

    private const PAGES_TABLE = 'pages';

    private const FILE_TABLE = 'sys_file';

    private const REFERENCE_TABLE = 'sys_file_reference';

    /**
     * The fields this tool will ever write, intersected at runtime with the
     * live TCA of the element's own CType. A field outside this list may be a
     * perfectly good file field and is still refused: widening the set is a
     * decision, not a lookup.
     *
     * @var list<string>
     */
    private const WRITABLE_FIELDS = ['image', 'assets', 'media'];

    private const LIVE_WORKSPACE = 0;

    /** The only language this tool addresses; see {@see self::execute()}. */
    private const DEFAULT_LANGUAGE = 0;

    /** Upper bound for each free-text field on the reference. */
    private const MAX_TEXT_LENGTH = 1000;

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'attach_file_to_content_element',
            'Reference an EXISTING managed file (sys_file) from ONE content element, appending it to a file '
            . 'field. Creates a sys_file_reference through the TYPO3 DataHandler as the acting backend user, in '
            . 'the live workspace. It never uploads, moves or renames a file: the file must already exist in a '
            . "permitted storage inside the acting user's file mounts, and the element must be on a page the "
            . "user may edit. The file's extension must be accepted by the chosen field. Calling twice attaches "
            . 'the same file twice.',
            [
                'type'       => 'object',
                'properties' => [
                    'content_element' => [
                        'type'        => 'integer',
                        'description' => 'The tt_content uid of the single element to attach to.',
                    ],
                    'file' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file uid of the single existing file to reference.',
                    ],
                    'field' => [
                        'type'        => 'string',
                        'enum'        => self::WRITABLE_FIELDS,
                        'description' => 'Which file field to append to. Omit it when the element type offers '
                            . 'exactly one of these; when it offers several, name the one you mean.',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'Optional caption for this reference. Describes the file IN THIS PLACE, '
                            . 'not the file itself.',
                    ],
                    'alternative' => [
                        'type'        => 'string',
                        'description' => "Optional alternative text for this reference, overriding the file's own.",
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'Optional longer description for this reference.',
                    ],
                ],
                'required' => ['content_element', 'file'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $refusal = $this->refuseWithoutBackendEnvironment(self::REFERENCE_TABLE)
            ?? $this->refuseOutsideLiveWorkspace($user);
        if ($refusal instanceof ToolResult) {
            return $refusal;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        [$element, $file, $field, $texts] = $plan;

        $elementUid = self::toInt($element['uid'] ?? 0);
        $pageUid    = self::toInt($element['pid'] ?? 0);
        $existing   = $this->existingReferenceUids($elementUid, $field);
        $placeholder = 'NEW' . uniqid('nrllm', true);

        $first = GeneralUtility::makeInstance(DataHandler::class);
        $first->start(
            [
                self::REFERENCE_TABLE => [
                    $placeholder => $texts + [
                        'uid_local'   => self::toInt($file['uid'] ?? 0),
                        'tablenames'  => self::CONTENT_TABLE,
                        'uid_foreign' => $elementUid,
                        'fieldname'   => $field,
                        'pid'         => $pageUid,
                        // Stated rather than defaulted: the DataHandler checks
                        // language access against the record it is handed, and
                        // a payload without this field fails that check outright
                        // for a non-admin. The tool writes the default-language
                        // reference and takes no language argument, like the
                        // other FAL writer (ADR-135).
                        'sys_language_uid' => self::DEFAULT_LANGUAGE,
                    ],
                ],
                self::CONTENT_TABLE => [
                    $elementUid => [$field => implode(',', [...$existing, $placeholder])],
                ],
            ],
            [],
            $user,
        );
        $first->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($first);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        $newUid = self::toInt($first->substNEWwithIDs[$placeholder] ?? 0);
        if ($newUid < 1) {
            return ToolResult::error('The reference was not created, and the DataHandler reported no error.');
        }

        $mismatch = $this->readBack($elementUid, $field, $newUid, count($existing) + 1, $texts);
        if ($mismatch !== null) {
            $this->discard($newUid, $elementUid, $field, $existing, $user);

            return ToolResult::error($mismatch);
        }

        return ToolResult::text(sprintf(
            'Attached file [%d] "%s" to %s [%d] "%s" as %s reference %d of %d.',
            self::toInt($file['uid'] ?? 0),
            $this->excerpt(self::toStr($file['name'] ?? '')),
            self::CONTENT_TABLE,
            $elementUid,
            $this->excerpt(self::toStr($element['header'] ?? '')),
            $field,
            count($existing) + 1,
            count($existing) + 1,
        ))->withWriteTarget(new RecordReference(self::REFERENCE_TABLE, $newUid));
    }

    /**
     * The before/after this call would produce (ADR-136).
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * explicit acting user, down to the neutral refusal string: a preview can
     * never show an element or a file the run itself could not have written.
     *
     * NOT checked here, deliberately: the live-workspace and backend-environment
     * refusals. Both describe the PROCESS that performs the write, which is the
     * approver's request rather than this one.
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

        [$element, $file, $field, $texts] = $plan;

        $elementUid = self::toInt($element['uid'] ?? 0);
        $existing   = count($this->existingReferenceUids($elementUid, $field));

        $lines = [
            sprintf(
                '%s [%d] "%s" on page [%d]',
                self::CONTENT_TABLE,
                $elementUid,
                $this->excerpt(self::toStr($element['header'] ?? '')),
                self::toInt($element['pid'] ?? 0),
            ),
            sprintf(
                'field %s: %d reference(s) → %d, appended last',
                $field,
                $existing,
                $existing + 1,
            ),
            sprintf(
                'file [%d] "%s" (%s)',
                self::toInt($file['uid'] ?? 0),
                $this->excerpt(self::toStr($file['name'] ?? '')),
                $this->excerpt(self::toStr($file['identifier'] ?? '')),
            ),
        ];

        foreach (['title', 'alternative', 'description'] as $name) {
            if (isset($texts[$name])) {
                $lines[] = sprintf('%s: %s', $name, $this->quoted(self::toStr($texts[$name])));
            }
        }

        return $lines;
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the storage gate authorises the acting user's
        // own file mounts, and the page permission is checked against that same
        // user before anything is written.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135), not `files`: a configuration that
        // grants the read-only FAL tools must not inherit write capability
        // because a new tool joined that group.
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // Calling twice attaches the file twice. That is a legitimate outcome —
        // two references to one image are valid — so it is declared rather than
        // deduplicated behind the caller's back.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * The human-facing declaration (ADR-152).
     *
     * The declared record type is `tt_content`: the element is what an editor
     * selects and what the action changes. The file is an argument to it.
     */
    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.attach_file_to_content_element.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.attach_file_to_content_element.description',
            'nrllm-editor-action-attach-file',
            [self::CONTENT_TABLE],
        );
    }

    /**
     * Whether the person LOOKING at the approval card may see this element and
     * file (ADR-136).
     *
     * The same resolution the write uses, run against the VIEWER: a second
     * authorisation path here would be a second answer to the same question.
     *
     * @param array<string, mixed> $arguments the model-chosen arguments of the pending call
     */
    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        return !is_string($this->plan($arguments, $viewer));
    }

    /**
     * Everything the write needs, or the refusal text explaining why there is
     * nothing to write.
     *
     * Resolution and authorisation live here rather than in execute() so the
     * preview, the viewer check and the write cannot drift apart — they are the
     * same question asked about three different users.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{array<string, mixed>, array<string, mixed>, string, array<string, string>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $elementUid = self::toInt($arguments['content_element'] ?? 0);
        if ($elementUid < 1) {
            return 'Refused: "content_element" must be the positive tt_content uid of exactly one element.';
        }

        $fileUid = self::toInt($arguments['file'] ?? 0);
        if ($fileUid < 1) {
            return 'Refused: "file" must be the positive sys_file uid of exactly one existing file.';
        }

        $texts = $this->collectTexts($arguments);
        if (is_string($texts)) {
            return $texts;
        }

        $element = $this->fetchRow(self::CONTENT_TABLE, $elementUid);
        if ($element === null) {
            return self::NOT_PERMITTED;
        }

        $page = $this->fetchRow(self::PAGES_TABLE, self::toInt($element['pid'] ?? 0));
        if ($page === null || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            return self::NOT_PERMITTED;
        }

        $file = $this->fetchFile($fileUid);
        if ($file === null) {
            return self::NOT_PERMITTED;
        }

        // nr_llm's own barrier, and it has to run here: the allow-list is this
        // extension's configuration and the DataHandler cannot consult it. It
        // covers the user's file mounts too.
        if (!$this->storageGate->isFileAccessible($user, self::toInt($file['storage'] ?? 0), self::toStr($file['identifier'] ?? ''))) {
            return self::NOT_PERMITTED;
        }

        $field = $this->chooseField($arguments, self::toStr($element['CType'] ?? ''));
        if (!is_string($field)) {
            return $field[0];
        }

        $extension = strtolower(self::toStr($file['extension'] ?? ''));
        $allowed   = $this->allowedExtensions($field);
        if ($allowed !== null && !in_array($extension, $allowed, true)) {
            return sprintf(
                'Refused: field "%s" does not accept a .%s file. It accepts: %s.',
                $field,
                $extension === '' ? '(none)' : $extension,
                implode(', ', $allowed),
            );
        }

        return [$element, $file, $field, $texts];
    }

    /**
     * The field to write, or a one-element array carrying the refusal.
     *
     * A named field must be writable AND offered by this element's own type; an
     * omitted one is only inferred when the type offers exactly one candidate,
     * because picking among several would be this tool inventing an editorial
     * preference.
     *
     * @param array<string, mixed> $arguments
     *
     * @return string|array{string}
     */
    private function chooseField(array $arguments, string $cType): string|array
    {
        $offered = $this->fileFieldsOfType($cType);
        if ($offered === []) {
            return [sprintf('Refused: content elements of type "%s" have no file field this tool writes.', $cType)];
        }

        $named = self::toStr($arguments['field'] ?? '');
        if ($named !== '') {
            if (!in_array($named, $offered, true)) {
                return [sprintf(
                    'Refused: "%s" is not a file field of content type "%s". It offers: %s.',
                    $named,
                    $cType,
                    implode(', ', $offered),
                )];
            }

            return $named;
        }

        if (count($offered) > 1) {
            return [sprintf(
                'Refused: content type "%s" offers several file fields (%s). Name the one you mean in "field".',
                $cType,
                implode(', ', $offered),
            )];
        }

        return $offered[0];
    }

    /**
     * The writable file fields this CType actually shows, in the live TCA.
     *
     * @return list<string>
     */
    private function fileFieldsOfType(string $cType): array
    {
        $columns = $this->tcaColumnsFor(self::CONTENT_TABLE);
        if ($columns === null) {
            return [];
        }

        $showitem = $this->showitemFor($cType);
        if ($showitem === '') {
            return [];
        }

        $shown = array_map(
            static fn(string $part): string => trim(explode(';', $part)[0]),
            explode(',', $showitem),
        );

        $offered = [];
        foreach (self::WRITABLE_FIELDS as $field) {
            if (!in_array($field, $shown, true)) {
                continue;
            }

            if (self::toStr($this->configOf($columns, $field)['type'] ?? '') === 'file') {
                $offered[] = $field;
            }
        }

        return $offered;
    }

    /**
     * The `showitem` string of one content type, narrowed the way the trait
     * narrows `columns` — `$GLOBALS['TCA']` is `mixed` at level 10.
     */
    private function showitemFor(string $cType): string
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !is_array($tca[self::CONTENT_TABLE] ?? null)) {
            return '';
        }

        $types = $tca[self::CONTENT_TABLE]['types'] ?? null;
        if (!is_array($types) || !is_array($types[$cType] ?? null)) {
            return '';
        }

        return self::toStr($types[$cType]['showitem'] ?? '');
    }

    /**
     * The extensions this field accepts, or null when it accepts anything.
     *
     * @return list<string>|null
     */
    private function allowedExtensions(string $field): ?array
    {
        $columns = $this->tcaColumnsFor(self::CONTENT_TABLE);
        if ($columns === null) {
            return null;
        }

        $allowed = self::toStr($this->configOf($columns, $field)['allowed'] ?? '');
        if ($allowed === '') {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn(string $part): string => strtolower(trim($part)),
            explode(',', $allowed),
        )));
    }

    /**
     * One column's `config` array, narrowed. `tcaColumnsFor()` answers
     * `array<mixed>`, so every step down into it needs its own check at level 10.
     *
     * @param array<mixed> $columns
     *
     * @return array<mixed>
     */
    private function configOf(array $columns, string $field): array
    {
        $column = $columns[$field] ?? null;
        if (!is_array($column)) {
            return [];
        }

        $config = $column['config'] ?? null;

        return is_array($config) ? $config : [];
    }

    /**
     * The optional free-text fields, or the refusal for one that is too long.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, string>|string
     */
    private function collectTexts(array $arguments): array|string
    {
        $texts = [];
        foreach (['title', 'alternative', 'description'] as $name) {
            if (!array_key_exists($name, $arguments)) {
                continue;
            }

            $value = self::toStr($arguments[$name]);
            if (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
                return sprintf('Refused: "%s" is longer than %d characters.', $name, self::MAX_TEXT_LENGTH);
            }

            $texts[$name] = $value;
        }

        return $texts;
    }

    /**
     * The live references already on this element's field, in their stored
     * order — the list the parent field has to keep.
     *
     * @return list<string>
     */
    private function existingReferenceUids(int $elementUid, string $field): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid')
            ->from(self::REFERENCE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($elementUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::CONTENT_TABLE)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(self::LIVE_WORKSPACE, Connection::PARAM_INT)),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): string => (string)self::toInt($row['uid'] ?? 0), $rows);
    }

    /**
     * What the write got wrong, or null when it landed as planned.
     *
     * An empty errorLog is NOT proof. `title`, `alternative` and `description`
     * are `exclude` fields on `sys_file_reference`, so the DataHandler DROPS
     * them for an acting user without the field-level grant — silently, without
     * logging. A caption the caller asked for would otherwise be reported as
     * written while the row carries an empty one. The relation itself (row,
     * counter, position) is asserted for the same reason.
     *
     * @param array<string, string> $texts
     */
    private function readBack(int $elementUid, string $field, int $referenceUid, int $expected, array $texts): ?string
    {
        $row = $this->fetchRow(self::REFERENCE_TABLE, $referenceUid);
        if ($row === null
            || self::toInt($row['uid_foreign'] ?? 0) !== $elementUid
            || self::toStr($row['tablenames'] ?? '') !== self::CONTENT_TABLE
            || self::toStr($row['fieldname'] ?? '') !== $field
        ) {
            return 'The reference was not stored against the named element and field.';
        }

        $element = $this->fetchRow(self::CONTENT_TABLE, $elementUid);
        $counter = self::toInt($element[$field] ?? 0);
        if ($counter !== $expected) {
            return sprintf(
                'The element now counts %d reference(s) in "%s" where %d were expected, so the relation is inconsistent.',
                $counter,
                $field,
                $expected,
            );
        }

        $live = $this->existingReferenceUids($elementUid, $field);
        if ($live === [] || (int)$live[count($live) - 1] !== $referenceUid) {
            return 'The reference was not appended last.';
        }

        foreach ($texts as $name => $value) {
            if (self::toStr($row[$name] ?? '') === $value) {
                continue;
            }

            return sprintf(
                'The reference was created but "%s" did not take. The acting backend user is most likely '
                . 'missing the field-level ("exclude field") grant for %s:%s.',
                $name,
                self::REFERENCE_TABLE,
                $name,
            );
        }

        return null;
    }

    /**
     * Removes a reference whose read-back failed, so a refused call leaves the
     * element as it found it.
     *
     * Deleting the row is not enough, and a test caught that: the element's own
     * counter still names the reference that is gone, which is the inconsistent
     * state this tool exists to avoid producing. The parent field is therefore
     * written back to the list that was there before the call.
     *
     * @param list<string> $survivors the reference uids the element had before this call
     */
    private function discard(int $referenceUid, int $elementUid, string $field, array $survivors, BackendUserAuthentication $user): void
    {
        $removal = GeneralUtility::makeInstance(DataHandler::class);
        $removal->start([], [self::REFERENCE_TABLE => [$referenceUid => ['delete' => 1]]], $user);
        $removal->process_cmdmap();

        $restore = GeneralUtility::makeInstance(DataHandler::class);
        $restore->start([self::CONTENT_TABLE => [$elementUid => [$field => implode(',', $survivors)]]], [], $user);
        $restore->process_datamap();
    }

    /**
     * `sys_file` carries no `deleted` column, so it gets its own reader rather
     * than a flag on the shared one — a soft-delete predicate against a table
     * that has no such field is an SQL error, not a narrower query.
     *
     * @return array<string, mixed>|null
     */
    private function fetchFile(int $uid): ?array
    {
        if ($uid < 1) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::FILE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid', 'storage', 'identifier', 'name', 'extension')
            ->from(self::FILE_TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * For the soft-deleting tables only; see {@see self::fetchFile()}.
     *
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $table, int $uid): ?array
    {
        if ($uid < 1) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }
}
