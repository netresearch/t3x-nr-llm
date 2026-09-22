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
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Set the social preview image of ONE page — `pages.og_image` or
 * `pages.twitter_image` — to an EXISTING managed file, through the DataHandler,
 * as the acting backend user (ADR-195).
 *
 * The ninth writing tool. ADR-135 kept the two image fields out of
 * `update_page_metadata` because they are file references rather than scalars;
 * this tool is the writer that risk class gets instead. It creates exactly one
 * `sys_file_reference` row on the named field and, only when asked to, deletes
 * the reference that was there before — through the DataHandler, so the old row
 * goes to `deleted = 1` and both steps are in `sys_log` under the acting user.
 *
 * What it refuses, and why:
 *
 * - **A field outside the two.** The tool's name states what it sets; the
 *   content-element file fields have {@see AttachFileToContentElementTool}.
 * - **A field the live TCA does not declare.** Both columns are EXT:seo's. On
 *   an installation without it the call is refused naming the extension, and
 *   the `field` enum on the wire follows the live TCA.
 * - **A file the field does not accept.** The `allowed` list of the column,
 *   read live (core resolves the `common-image-types` placeholder into the
 *   configured image extensions when it builds the TCA).
 * - **A reference that is already there, unless `replace` is true.** Mirrors
 *   `create_translation_draft`'s `overwrite`: the destructive step is a word the
 *   approver reads on the card, never a default.
 * - **A translated page.** The reference is written in the default language,
 *   like the other FAL writer's, and a translation's own image is a page
 *   properties decision (`allowLanguageSynchronization`) this tool does not
 *   make.
 * - **A page translated into a language the user may not edit.** Core saves a
 *   page's translations along with the page (`DataMapProcessor`), and the
 *   DataHandler refuses that record for the language — after the reference
 *   row is written. Asked before the write instead, naming the translation.
 * - **A page the user may not edit, a file the user may not reach.** One
 *   neutral string for all of them, so a refusal never confirms that a uid
 *   exists.
 *
 * **Why the page row and the reference travel in ONE DataHandler run.** With
 * `pages` in the datamap, the DataHandler asks {@see Permission::PAGE_EDIT} for
 * inserting and deleting a `sys_file_reference` on that page — exactly the right
 * an editor holds on a page whose properties they may edit. Written separately,
 * the same rows would need {@see Permission::CONTENT_EDIT}, a right about the
 * page's content rather than the page. The tool therefore checks PAGE_EDIT, the
 * same bar as `update_page_metadata`, and the DataHandler enforces it a second
 * time inside the write.
 *
 * **The field-level grant is asked BEFORE the write.** Both columns carry
 * `'exclude' => true`. For a user without the `non_exclude_fields` grant the
 * DataHandler would still create the reference row and drop the page's side of
 * the relation in silence, with an empty `errorLog` — a reference EXT:seo never
 * renders, because it reads the page's counter first. The same silent drop hits
 * every non-admin on a column whose `displayCond` is `HIDE_FOR_NON_ADMINS`.
 * Both shapes are decided with the DataHandler's own predicates — the flag cast
 * to bool, the condition compared by string identity — and the grant is asked
 * through the same method the DataHandler asks it with (ADR-192); the whole
 * call is refused.
 *
 * Effect: {@see ToolEffect::NON_IDEMPOTENT_WRITE}. Without `replace` a second
 * run refuses, so a reaped run that already succeeded would report failure for
 * a write that happened; with it a second run discards a reference an editor
 * may have set in between. Every call is suspended for human approval because
 * the effect is a write (ADR-134), and the ADR-112 write fence covers it through
 * the per-segment lease (ADR-141).
 */
final readonly class SetPageSocialImageTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The shape the ADR-146 writers share; ADR-180 asks the writers after it to use it.
    use PlansOneEditorialWriteTrait;

    /**
     * One string for "no such page", "you may not edit it", "no such file",
     * "not in a permitted storage" and "outside your file mounts", so a refusal
     * never confirms that a uid exists.
     */
    private const NOT_PERMITTED = 'Page or file not found, or not permitted.';

    private const PAGES_TABLE = 'pages';

    private const FILE_TABLE = 'sys_file';

    private const REFERENCE_TABLE = 'sys_file_reference';

    /**
     * The two fields this tool will ever write, intersected at call time with
     * the live TCA: both come with EXT:seo and are absent without it.
     *
     * @var list<string>
     */
    private const FIELDS = ['og_image', 'twitter_image'];

    private const LIVE_WORKSPACE = 0;

    /** The only language this tool writes in; see the class docblock. */
    private const DEFAULT_LANGUAGE = 0;

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
    ) {}

    public function getSpec(): ToolSpec
    {
        $available = $this->availableFields();

        return ToolSpec::function(
            'set_page_social_image',
            'Set the social preview image of ONE page: its Open Graph image (og_image) or its Twitter image '
            . '(twitter_image), both provided by EXT:seo. References an EXISTING managed file (sys_file) from the '
            . 'page through the TYPO3 DataHandler as the acting backend user, in the live workspace, on a '
            . 'default-language page. It never uploads, moves or renames a file: the file must already exist in a '
            . "permitted storage inside the acting user's file mounts, and its extension must be one the field "
            . 'accepts. When the field already holds an image the call is refused, unless "replace" is true — '
            . 'which DELETES the existing reference and sets the new one. The file fields of a content element '
            . 'are attach_file_to_content_element\'s, not this tool\'s.',
            [
                'type'       => 'object',
                'properties' => [
                    'page' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the single default-language page to set the image on.',
                    ],
                    'field' => [
                        'type'        => 'string',
                        // Never an empty enum: a schema the model cannot satisfy
                        // is worse than a call that is refused with the reason.
                        'enum'        => $available === [] ? self::FIELDS : $available,
                        'description' => 'Which social image to set: og_image (Open Graph, used by most platforms) or '
                            . 'twitter_image.',
                    ],
                    'file' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file uid of the single existing image file to reference.',
                    ],
                    'replace' => [
                        'type'        => 'boolean',
                        'description' => 'Replace an image the field already holds. Destructive: the existing reference '
                            . 'is deleted (recoverably) before the new one is set. Defaults to false.',
                    ],
                ],
                'required' => ['page', 'field', 'file'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $this->writableActingUser($context, self::PAGES_TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $survivors   = array_map(static fn(array $reference): string => (string)$reference['uid'], $plan['existing']);
        $placeholder = StringUtility::getUniqueId('NEW');

        // One run for the reference AND the page: see the class docblock for
        // why the page row has to be in the same datamap.
        $cmdmap = [];
        foreach ($plan['existing'] as $reference) {
            $cmdmap[self::REFERENCE_TABLE][$reference['uid']] = ['delete' => 1];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            [
                self::REFERENCE_TABLE => [
                    $placeholder => [
                        'uid_local'   => $plan['file'],
                        'tablenames'  => self::PAGES_TABLE,
                        'uid_foreign' => $plan['page'],
                        'fieldname'   => $plan['field'],
                        // A page's inline children live on the page itself.
                        'pid'         => $plan['page'],
                        // Stated rather than defaulted: the DataHandler checks
                        // language access against the record it is handed, and
                        // a payload without this field fails that check for a
                        // non-admin.
                        'sys_language_uid' => self::DEFAULT_LANGUAGE,
                    ],
                ],
                self::PAGES_TABLE => [
                    $plan['page'] => [$plan['field'] => $placeholder],
                ],
            ],
            $cmdmap,
            $user,
        );
        $dataHandler->process_datamap();

        $newUid = self::toInt($dataHandler->substNEWwithIDs[$placeholder] ?? 0);

        // A refused page row does not stop the reference row, whose grants the
        // user may well hold; and a refused reference row leaves the page's
        // counter recounted without it. Either way the page goes back to what
        // it was before the complaint is reported.
        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            $this->discard($newUid, $plan['page'], $plan['field'], $survivors, $user);

            return $refused;
        }

        if ($newUid < 1) {
            $this->discard(0, $plan['page'], $plan['field'], $survivors, $user);

            return ToolResult::error('The reference was not created, and the DataHandler reported no error.');
        }

        // Read back BEFORE the replaced references are deleted. The row must
        // sit on the named page, field and file, and the page must count it —
        // EXT:seo reads the counter before it looks for rows, so a reference
        // the page does not count is one nothing renders. A page whose side
        // was dropped goes back to what it was, and its previous references
        // are still live to be put back, because the cmdmap has not run yet.
        $mismatch = $this->readBack($plan['page'], $plan['field'], $newUid, $plan['file']);
        if ($mismatch !== null) {
            $this->discard($newUid, $plan['page'], $plan['field'], $survivors, $user);

            return ToolResult::error($mismatch . ' The reference was taken back and the page left as it was.');
        }

        if ($cmdmap !== []) {
            $dataHandler->process_cmdmap();
        }

        // Only now can the field be required to hold exactly this reference in
        // the default language: whatever else is still live there is a
        // previous reference the cmdmap did not remove. The list the call
        // found goes back into the page's field; where the cmdmap removed
        // some of it, the DataHandler relates only the rows still live and
        // the page counts those (measured, not read).
        $live = array_map(static fn(array $reference): int => $reference['uid'], $this->existingReferences($plan['page'], $plan['field']));
        if ($live !== [$newUid]) {
            $this->discard($newUid, $plan['page'], $plan['field'], $survivors, $user);

            return ToolResult::error(sprintf(
                'Reference [%d] was created, but page [%d] still carries %d other live reference(s) in "%s": the previous '
                . 'reference(s) were not removed. The new reference was taken back and the page left as it was.%s',
                $newUid,
                $plan['page'],
                count(array_diff($live, [$newUid])),
                $plan['field'],
                $dataHandler->errorLog === [] ? '' : ' TYPO3 reported: ' . $this->summariseErrors($dataHandler->errorLog),
            ));
        }

        return ToolResult::text(sprintf(
            'Set %s of page [%d] "%s" to file [%d] "%s"%s.',
            $plan['field'],
            $plan['page'],
            $this->excerpt($plan['pageTitle']),
            $plan['file'],
            $this->excerpt($plan['fileName']),
            $plan['existing'] === [] ? '' : ', replacing ' . $this->describe($plan['existing']),
        ))->withWriteTarget(new RecordReference(self::REFERENCE_TABLE, $newUid), WriteKind::CREATED);
    }

    /**
     * What this call would change, as the approver reads it (ADR-136): the
     * page, the field, the file referenced now and the file that would be.
     *
     * A pure function of the arguments and the current state (ADR-184): it
     * reads and writes nothing, and the destructive case gets its own line so
     * an approver who skims cannot miss it.
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string.
     *
     * NOT checked here, deliberately: the live-workspace and backend-environment
     * refusals, which describe the process performing the write.
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

        $lines = [
            sprintf('Page [%d] "%s" — %s:', $plan['page'], $this->excerpt($plan['pageTitle']), $plan['field']),
            sprintf('now: %s', $this->describe($plan['existing'])),
            sprintf(
                'new: file [%d] "%s" (%s)',
                $plan['file'],
                $this->excerpt($plan['fileName']),
                $this->excerpt($plan['fileIdentifier']),
            ),
        ];

        if ($plan['existing'] !== []) {
            $lines[] = 'REPLACES the reference(s) above — they are deleted (recoverably) and the new file takes their place.';
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
        // Usable by a non-admin: the page is authorised against the acting
        // user's own page-edit right, the file against their own storages and
        // file mounts, and the DataHandler enforces both a second time.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // Not repeatable. Without `replace` a second run refuses, so a reaped
        // run that already succeeded would report failure for a write that
        // happened; with it, a second run discards a reference an editor may
        // have set between the two attempts.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * The human-facing declaration (ADR-152).
     *
     * The declared record type is `pages`: the page is what an editor selects
     * and what the action changes. The file is an argument to it.
     */
    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.set_page_social_image.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.set_page_social_image.description',
            'nrllm-editor-action-page-social-image',
            [self::PAGES_TABLE],
        );
    }

    /**
     * Everything the write needs, resolved and authorised — or the refusal
     * message that stops it.
     *
     * One method for {@see self::execute()}, {@see self::previewCall()} and the
     * viewer gate: whether an existing reference is about to be discarded is
     * the single most important thing on the card, and it is decided once.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{page:int, pageTitle:string, field:string, file:int, fileName:string, fileIdentifier:string, existing:list<array{uid:int, file:int, name:string}>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['page', 'field', 'file', 'replace'],
            'sets one social image of one page',
        );
        if ($unknown !== null) {
            return $unknown;
        }

        $pageUid = self::toInt($arguments['page'] ?? 0);
        if ($pageUid < 1) {
            return 'Refused: "page" must be the positive uid of exactly one page.';
        }

        $field     = trim(self::toStr($arguments['field'] ?? ''));
        $available = $this->availableFields();
        if (!in_array($field, $available, true)) {
            return sprintf(
                'Refused: "%s" is not a page field this tool sets. Available: %s.',
                // The key is echoed back so the model can correct itself; it is
                // a name the model itself chose, not instance data.
                preg_replace('/[^A-Za-z0-9_]/', '', $field) ?? '',
                $available === []
                    ? 'none — og_image and twitter_image come with EXT:seo, which this installation does not have'
                    : implode(', ', $available),
            );
        }

        $fileUid = self::toInt($arguments['file'] ?? 0);
        if ($fileUid < 1) {
            return 'Refused: "file" must be the positive sys_file uid of exactly one existing file.';
        }

        $replace = $arguments['replace'] ?? false;
        if (!is_bool($replace)) {
            return 'Refused: "replace" must be true or false.';
        }

        // The reference is written in the default language; a user without the
        // right to edit it may not set one either.
        if (!$user->checkLanguageAccess(self::DEFAULT_LANGUAGE)) {
            return 'Refused: you may not edit content in the default language.';
        }

        // The two shapes the DataHandler drops from the page row without an
        // error, asked BEFORE anything is written and with the predicates it
        // uses (`DataHandler::fillInFieldArray()`): a column hidden from
        // non-admins is skipped for every non-admin whatever they hold, and an
        // `exclude` column for a user without the grant. The grant is asked
        // through the same method the DataHandler asks it with (ADR-192); note
        // that `check()` tests isset($groupData[$type]) before isAdmin().
        if (!$user->isAdmin() && $this->isHiddenFromNonAdmins($field)) {
            return sprintf(
                'Refused: %s:%s is hidden from non-administrators on this installation (displayCond '
                . "HIDE_FOR_NON_ADMINS). The DataHandler would create the reference and drop the page's side of it in "
                . 'silence, so nothing was written.',
                self::PAGES_TABLE,
                $field,
            );
        }

        if ($this->isExcludeField($field) && !$user->check('non_exclude_fields', self::PAGES_TABLE . ':' . $field)) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s:%s. The '
                . "DataHandler would create the reference and drop the page's side of it in silence, so nothing "
                . 'was written.',
                self::PAGES_TABLE,
                $field,
            );
        }

        $page = $this->fetchRowByUid(self::PAGES_TABLE, $pageUid);
        if ($page === null || !$user->doesUserHaveAccess($page, Permission::PAGE_EDIT)) {
            return self::NOT_PERMITTED;
        }

        if (self::toInt($page['sys_language_uid'] ?? 0) !== self::DEFAULT_LANGUAGE) {
            $parent = self::toInt($page['l10n_parent'] ?? 0);

            return sprintf(
                'Refused: page [%d] is a translation. This tool sets the image on the default-language page%s; a '
                . 'translation keeps or overrides it in its own page properties.',
                $pageUid,
                $parent > 0 ? sprintf(' — here page [%d]', $parent) : '',
            );
        }

        // Core saves a page's translations along with the page: the
        // DataMapProcessor puts every live translation into the datamap, in
        // whatever state its fields are, and the DataHandler then refuses that
        // record for a language the user may not edit — after the reference
        // row is written. Asked here instead, so the card shows it and nothing
        // is written to be taken back. Hidden translations count; core
        // synchronises them too.
        $outOfReach = [];
        foreach ($this->translationsOf($pageUid) as $translation) {
            if (!$user->checkLanguageAccess($translation['language'])) {
                $outOfReach[] = sprintf('page [%d] in language [%d]', $translation['uid'], $translation['language']);
            }
        }

        if ($outOfReach !== []) {
            return sprintf(
                'Refused: page [%d] is translated into a language you may not edit content in (%s). TYPO3 saves a '
                . "page's translations along with the page, so the DataHandler would refuse the write. Nothing was "
                . 'written.',
                $pageUid,
                implode(', ', $outOfReach),
            );
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

        $existing = $this->existingReferences($pageUid, $field);
        if ($existing !== [] && !$replace) {
            return sprintf(
                'Refused: page [%d] already has %s: %s. Pass "replace": true to delete that reference and set the '
                . 'new one.',
                $pageUid,
                $field,
                $this->describe($existing),
            );
        }

        return [
            'page'           => $pageUid,
            'pageTitle'      => self::toStr($page['title'] ?? ''),
            'field'          => $field,
            'file'           => $fileUid,
            'fileName'       => self::toStr($file['name'] ?? ''),
            'fileIdentifier' => self::toStr($file['identifier'] ?? ''),
            'existing'       => $existing,
        ];
    }

    /**
     * What the datamap got wrong, or null when it landed as planned: the row
     * on the named page, field and file, and a page that counts exactly it.
     *
     * Asked before the cmdmap deletes the replaced references, so the caller
     * can put the page back as it was — {@see self::discard()} knows the rows
     * that were there. The counter is compared with 1 because the page's field
     * was set to the new reference alone. A page whose side the DataHandler
     * dropped without an error still counts what it counted before: 0 for a
     * fresh field, the previous count for a replaced one. That count is 1 when
     * exactly one reference was there, and this check cannot tell it from the
     * new one — the run then ends with the new row as the single live, counted
     * reference, which is the state asked for. The limit is stated rather than
     * closed: the only stricter signal would be the DataHandler's own history
     * of the page row (`historyRecords`), which the tool does not read.
     *
     * The grant is NOT named here: both silent-drop shapes the DataHandler has
     * are refused by {@see self::plan()} before the write, so when this fires
     * the grant was present and the cause is something the tool cannot see.
     */
    private function readBack(int $pageUid, string $field, int $referenceUid, int $fileUid): ?string
    {
        $row = $this->fetchRowByUid(self::REFERENCE_TABLE, $referenceUid);
        if ($row === null
            || self::toInt($row['uid_foreign'] ?? 0) !== $pageUid
            || self::toStr($row['tablenames'] ?? '') !== self::PAGES_TABLE
            || self::toStr($row['fieldname'] ?? '') !== $field
            || self::toInt($row['uid_local'] ?? 0) !== $fileUid
        ) {
            return 'The reference was not stored against the named page, field and file.';
        }

        $counter = self::toInt($this->fetchRowByUid(self::PAGES_TABLE, $pageUid)[$field] ?? 0);
        if ($counter !== 1) {
            return sprintf(
                'Reference [%d] was created, but page [%d] counts %d reference(s) in "%s" where 1 was expected, so '
                . "nothing would render it: the page's side of the relation was not written.",
                $referenceUid,
                $pageUid,
                $counter,
                $field,
            );
        }

        return null;
    }

    /**
     * Puts the page back as the call found it after the DataHandler refused
     * part of the write: the new reference, if one came into being, is deleted
     * again, and the page's field is written back to the list it held before,
     * so its counter names exactly the references that are live.
     *
     * One DataHandler run, with the page row in the datamap, for the reason the
     * class docblock gives: the delete of a `sys_file_reference` is checked
     * against PAGE_EDIT only while `pages` is in the datamap. Issued on its own
     * it would need CONTENT_EDIT, which the user who just passed PAGE_EDIT does
     * not necessarily hold — and the orphan would stay.
     *
     * @param list<string> $survivors the reference uids the page had before this call
     */
    private function discard(int $referenceUid, int $pageUid, string $field, array $survivors, BackendUserAuthentication $user): void
    {
        $restore = GeneralUtility::makeInstance(DataHandler::class);
        $restore->start(
            [self::PAGES_TABLE => [$pageUid => [$field => implode(',', $survivors)]]],
            $referenceUid > 0 ? [self::REFERENCE_TABLE => [$referenceUid => ['delete' => 1]]] : [],
            $user,
        );
        $restore->process_datamap();
        $restore->process_cmdmap();
    }

    /**
     * The live translations of this page in the live workspace, hidden ones
     * included — the records core's `DataMapProcessor` adds to the datamap
     * beside the page (`fetchDependentElements()` applies the deleted and the
     * workspace restriction, no other).
     *
     * @return list<array{uid:int, language:int}>
     */
    private function translationsOf(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::PAGES_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid', 'sys_language_uid')
            ->from(self::PAGES_TABLE)
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(self::DEFAULT_LANGUAGE, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(self::LIVE_WORKSPACE, Connection::PARAM_INT)),
            )
            ->orderBy('sys_language_uid', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): array => [
                'uid'      => self::toInt($row['uid'] ?? 0),
                'language' => self::toInt($row['sys_language_uid'] ?? 0),
            ],
            $rows,
        );
    }

    /**
     * The live DEFAULT-LANGUAGE references on this page's field, in their
     * stored order, each with the file it points at — what the card shows as
     * "now" and what `replace` deletes.
     *
     * Default language only, because that is what the tool writes and what it
     * requires exactly one of. A translated copy core minted for a
     * parent-following translation belongs to that translation; where one sits
     * on the default-language page — the state a dropped page side leaves — it
     * is deleted with its parent (`deleteL10nOverlayRecords`), not named on the
     * card as a second image.
     *
     * @return list<array{uid:int, file:int, name:string}>
     */
    private function existingReferences(int $pageUid, string $field): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid', 'uid_local')
            ->from(self::REFERENCE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::PAGES_TABLE)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(self::DEFAULT_LANGUAGE, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(self::LIVE_WORKSPACE, Connection::PARAM_INT)),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $references = [];
        foreach ($rows as $row) {
            $fileUid      = self::toInt($row['uid_local'] ?? 0);
            $references[] = [
                'uid'  => self::toInt($row['uid'] ?? 0),
                'file' => $fileUid,
                'name' => self::toStr($this->fetchFile($fileUid)['name'] ?? ''),
            ];
        }

        return $references;
    }

    /**
     * The references as one readable clause: `file [1] "one.jpg"`, several
     * joined by commas, or `(none)`.
     *
     * @param list<array{uid:int, file:int, name:string}> $references
     */
    private function describe(array $references): string
    {
        if ($references === []) {
            return '(none)';
        }

        return implode(', ', array_map(
            fn(array $reference): string => sprintf('file [%d] "%s"', $reference['file'], $this->excerpt($reference['name'])),
            $references,
        ));
    }

    /**
     * The two fields the live TCA declares as file fields — none on an
     * installation without EXT:seo, both when no TCA is loaded at all (the
     * environment refusal in `execute()` is the one that fires then).
     *
     * @return list<string>
     */
    private function availableFields(): array
    {
        $columns = $this->tcaColumnsFor(self::PAGES_TABLE);
        if ($columns === null) {
            return self::FIELDS;
        }

        return array_values(array_filter(
            self::FIELDS,
            fn(string $field): bool => self::toStr($this->configOf($field)['type'] ?? '') === 'file',
        ));
    }

    /**
     * Whether the live TCA marks the column `exclude` — what core's schema
     * reports as supportsAccessControl(), read here without a schema dependency
     * and with the same cast: `(bool)($config['exclude'] ?? false)`, so an
     * installation's `'exclude' => 1` is the grant it is to the DataHandler.
     */
    private function isExcludeField(string $field): bool
    {
        $column = ($this->tcaColumnsFor(self::PAGES_TABLE) ?? [])[$field] ?? null;

        return is_array($column) && (bool)($column['exclude'] ?? false);
    }

    /**
     * Whether the live TCA hides the column from non-admins — the one
     * `displayCond` the DataHandler evaluates itself, by string identity
     * (`getDisplayConditions() === 'HIDE_FOR_NON_ADMINS'`), and skips the field
     * for. An array-form condition never matches, exactly as it never does
     * there.
     */
    private function isHiddenFromNonAdmins(string $field): bool
    {
        $column = ($this->tcaColumnsFor(self::PAGES_TABLE) ?? [])[$field] ?? null;

        return is_array($column) && ($column['displayCond'] ?? null) === 'HIDE_FOR_NON_ADMINS';
    }

    /**
     * The extensions this field accepts, or null when it accepts anything.
     *
     * @return list<string>|null
     */
    private function allowedExtensions(string $field): ?array
    {
        $allowed = self::toStr($this->configOf($field)['allowed'] ?? '');
        if ($allowed === '') {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn(string $part): string => strtolower(trim($part)),
            explode(',', $allowed),
        )));
    }

    /**
     * One page column's `config` array, narrowed step by step — `$GLOBALS['TCA']`
     * is `mixed` at level 10.
     *
     * @return array<mixed>
     */
    private function configOf(string $field): array
    {
        $column = ($this->tcaColumnsFor(self::PAGES_TABLE) ?? [])[$field] ?? null;
        $config = is_array($column) ? ($column['config'] ?? null) : null;

        return is_array($config) ? $config : [];
    }

    /**
     * `sys_file` carries no `deleted` column, so it gets its own reader rather
     * than the trait's — a soft-delete predicate against a table that has no
     * such field is an SQL error, not a narrower query.
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
}
