<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Closure;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Exception\PlanRefusedException;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Replace the file of ONE existing file reference on a content element, or
 * remove the reference, through the DataHandler, as the acting backend user
 * (ADR-198).
 *
 * {@see AttachFileToContentElementTool} only appends; this is the other half
 * an editor needs when an image is wrong. A replacement is a NEW reference at
 * the old one's position and the deletion of the old one, in one run — the
 * shape {@see SetPageSocialImageTool} writes, on a content element instead of
 * a page. Nothing is carried over from the old reference: its title,
 * alternative text, description and crop described the OLD file, and copying
 * them onto another image is how a wrong alternative text reaches a page. The
 * new reference shows the file's own metadata unless the call sets texts of
 * its own, and the approval card says which. A removal deletes the reference
 * (recoverably, `deleted = 1`); the file itself is never touched.
 *
 * What it refuses, and why:
 *
 * - **A reference that is not on a content element's `image`, `assets` or
 *   `media` field** — the fields {@see AttachFileToContentElementTool} writes,
 *   for the same reason: widening the set is a decision, not a lookup.
 * - **A file outside a permitted storage or the acting user's file mounts**
 *   ({@see FalStorageGate}), and an extension the field does not accept.
 * - **An element the acting user may not edit**: `CONTENT_EDIT` on its page,
 *   the record-level rights on the element, `tables_modify` for
 *   `sys_file_reference`, and the field-level grants for every column the call
 *   writes — asked before the write, because the DataHandler drops them in
 *   silence.
 * - **An owner the acting user may not edit**: where the element is an inline
 *   child (EXT:news holds content elements that way), every record that
 *   holds it, up the chain, is asked the table, page and record-level rights
 *   ({@see AsksInlineOwnersTrait}, ADR-230); so is an element whose owner is
 *   gone.
 * - **A process without a backend environment**, through
 *   {@see WritesThroughDataHandlerTrait}; in a draft workspace, a run that did
 *   not record it and a table TYPO3 keeps no draft of (ADR-262).
 *
 * In a draft workspace the run writes there (ADR-274): the element gets its
 * version, the new reference is new in the workspace and the replaced one is
 * marked for deletion there; live stays as it is until the workspace is
 * published.
 *
 * A reference, an element and a file that do not exist, and those the user may
 * not touch, all return one neutral string.
 */
final readonly class ReplaceFileReferenceTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface, RefusesBeforeApprovalInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The plan, the viewer gate, the unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;
    // Language and record-level rights of the element.
    use ActsOnAnExistingRecordTrait;
    // The owner chain of an element that is itself an inline child.
    use AsksInlineOwnersTrait;
    use FetchesSysFileRowTrait;
    // The plan's checks throw their refusal instead of returning it.
    use RefusesPlanStepsTrait;
    // execute(), the card and the refusal run in the acting user's workspace (ADR-274).
    use AsksItsRefusalFirstTrait;
    // The element's version, the rows as the workspace shows them (ADR-264).
    use CreatesRecordsInTheRunsWorkspaceTrait;
    // The element's stored row, asked as core asks it before the update.
    use AttachesToAStoredRecordTrait;

    /** The neutral refusal, shared in shape with the other FAL writers. */
    private const NOT_PERMITTED = 'File reference, content element or file not found, or not permitted.';

    private const CONTENT_TABLE = 'tt_content';

    private const NONE = '(none)';

    private const PAGES_TABLE = 'pages';

    private const REFERENCE_TABLE = 'sys_file_reference';

    /** The fields {@see AttachFileToContentElementTool} writes, and no other. */
    private const WRITABLE_FIELDS = ['image', 'assets', 'media'];

    private const TEXT_FIELDS = ['title', 'alternative', 'description'];

    /** Upper bound for each free-text field on the new reference, as the attaching writer bounds it. */
    private const MAX_TEXT_LENGTH = 1000;

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
        private ApprovalPreviewTranslator $translator,
        private TableReadAccessService $tableAccess,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'replace_file_reference',
            'Change ONE existing file reference (sys_file_reference) on a content element: "replace" points it at '
            . 'another EXISTING file at the same position, "remove" deletes the reference. The file itself is never '
            . "changed, moved or deleted. A replacement does not carry over the old reference's title, alternative "
            . 'text, description or crop — they described the old file; give new texts to set them. Writes through '
            . 'the TYPO3 DataHandler as the acting backend user; in a draft workspace the change is a draft there and '
            . 'live stays unchanged until it is published. To add a file, use attach_file_to_content_element.',
            [
                'type'       => 'object',
                'properties' => [
                    'reference' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file_reference uid of the single reference to change.',
                    ],
                    'action' => [
                        'type'        => 'string',
                        'enum'        => ['replace', 'remove'],
                        'description' => 'replace: point the reference at "file". remove: delete the reference.',
                    ],
                    'file' => [
                        'type'        => 'integer',
                        'description' => 'replace only: the sys_file uid of the existing file to reference instead.',
                    ],
                    'title' => [
                        'type'        => 'string',
                        'description' => 'replace only: a caption for the new reference.',
                    ],
                    'alternative' => [
                        'type'        => 'string',
                        'description' => "replace only: alternative text for the new reference, overriding the file's own.",
                    ],
                    'description' => [
                        'type'        => 'string',
                        'description' => 'replace only: a longer description for the new reference.',
                    ],
                ],
                'required' => ['reference', 'action'],
            ],
        );
    }

    /**
     * The write, in the acting user's workspace (ADR-274). In a draft
     * workspace the element gets its version first, so the reference the run
     * adds and the one it marks for deletion hang below that version and live
     * keeps its own (ADR-264); a write that fails takes that version back.
     *
     * @param array<string, mixed> $arguments
     */
    private function executeInItsWorkspace(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $this->actingUserForTheWrite($arguments, $context, self::REFERENCE_TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $versioned = $this->versionForTheWrite(self::CONTENT_TABLE, $plan['element'], $user);
        if ($versioned instanceof ToolResult) {
            return $versioned;
        }

        [$version, $versionComplaints, $before] = $versioned;

        try {
            return $plan['file'] === null
                ? $this->remove($plan, $version, $versionComplaints, $before, $user)
                : $this->replace($plan, $version, $versionComplaints, $before, $user);
        } catch (Throwable $throwable) {
            // What this call made in the workspace is not left behind by a
            // write that threw half-way (ADR-264, ADR-274); the failure itself
            // stays the caller's.
            $this->takeBackAfterAThrow(self::CONTENT_TABLE, $plan['element'], $version, $before, $user);

            throw $throwable;
        }
    }

    /**
     * The before/after this call would produce (ADR-136).
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string. Read in the
     * acting user's workspace, like the write; where the call is refused
     * whatever the approver decides, the card is that refusal (ADR-274). NOT
     * checked here: the backend-environment refusal, which describes the
     * process performing the write.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    private function previewCallInItsWorkspace(array $arguments, ToolExecutionContext $context): array
    {
        $user = $this->actingUserForTheCard($arguments, $context);
        if (is_array($user)) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return [$plan];
        }

        $count   = count($plan['references']);
        $remove  = $plan['file'] === null;
        [$t, $q] = $this->translator->boundTo($user, $this->excerpt(...));

        // ADR-213, in the order of the editorial guidelines: what, where, the
        // current file, the new state, the consequences.
        $lines = [
            $t($remove ? ApprovalPreviewLabel::ReplaceFileHeadingRemove : ApprovalPreviewLabel::ReplaceFileHeadingReplace),
            $t(ApprovalPreviewLabel::ObjectContent, $q($plan['header'])),
            $t(ApprovalPreviewLabel::LocationOnPage, $q($plan['pageTitle'])),
            ...array_filter([$this->workspaceLine($t, $q, $context, $user)], is_string(...)),
            $t($plan['language'] === 0 ? ApprovalPreviewLabel::LanguageDefault : ApprovalPreviewLabel::LanguageTranslation),
            $t(ApprovalPreviewLabel::FieldName, $this->translator->columnLabel($user, 'tt_content', $plan['field'])),
            $t(ApprovalPreviewLabel::ReplaceFilePosition, $plan['position'] + 1, $count),
        ];
        $details = [
            $t(ApprovalPreviewLabel::TechnicalRecord, 'tt_content', $plan['element']),
            $t(ApprovalPreviewLabel::TechnicalPage, $plan['page']),
            $t(ApprovalPreviewLabel::TechnicalLanguage, $plan['language']),
            $t(ApprovalPreviewLabel::TechnicalFields, $plan['field']),
            $t(ApprovalPreviewLabel::TechnicalFileReference, $plan['reference']),
            $t(ApprovalPreviewLabel::TechnicalCurrentFile, $plan['oldFile']),
        ];

        if ($plan['file'] === null) {
            $lines[] = $t(ApprovalPreviewLabel::ObjectFile, $q($plan['oldFileName']));
            $lines[] = $t(ApprovalPreviewLabel::ReplaceFileAfterwards, $count - 1);
            $lines[] = $t(ApprovalPreviewLabel::ReplaceFileFileStays);
        } else {
            $lines[]   = $t(ApprovalPreviewLabel::ReplaceFileCurrent, $q($plan['oldFileName']));
            $lines[]   = $t(ApprovalPreviewLabel::ReplaceFileProposed, $q($plan['fileName']));
            $details[] = $t(ApprovalPreviewLabel::TechnicalFile, $plan['file']);
            // Nothing is carried over from the old reference; the card says
            // which texts the new one has.
            foreach (self::TEXT_FIELDS as $name) {
                $label = $t(match ($name) {
                    'title'       => ApprovalPreviewLabel::FileFieldTitle,
                    'alternative' => ApprovalPreviewLabel::FileFieldAlternative,
                    default       => ApprovalPreviewLabel::FileFieldDescription,
                });
                $lines[] = array_key_exists($name, $plan['texts'])
                    ? $label . ': ' . $q($plan['texts'][$name])
                    : $t(ApprovalPreviewLabel::ReplaceFileTextOwn, $label);
            }
        }

        if ($plan['draftOnly']) {
            $lines[] = $t(ApprovalPreviewLabel::ReplaceFileDraftOnlyDiscarded);
        }

        if ($plan['translated'] !== []) {
            [$translatedLines, $translatedDetails] = $this->translatedLines($t, $plan['translated'], $remove);
            $lines                                 = [...$lines, ...$translatedLines];
            $details                               = [...$details, ...$translatedDetails];
        }

        $lines[] = $this->translator->technical($user, $details);

        return $lines;
    }

    /**
     * The card lines for the translated references core deletes along — a
     * consequence on its own line (rule 21) — and their technical details.
     *
     * @param Closure(ApprovalPreviewLabel, int|string...): string            $t
     * @param non-empty-list<array{reference:int, element:int, language:int}> $translated
     *
     * @return array{list<string>, list<string>}
     */
    private function translatedLines(Closure $t, array $translated, bool $remove): array
    {
        $lines   = [$t($remove ? ApprovalPreviewLabel::ReplaceFileTranslationsRemoved : ApprovalPreviewLabel::ReplaceFileTranslationsReplaced, count($translated))];
        $orphans = count(array_filter($translated, static fn (array $reference): bool => $reference['element'] === 0));
        if ($orphans > 0) {
            $lines[] = $t(ApprovalPreviewLabel::ReplaceFileOrphans, $orphans);
        }

        $details  = [$t(ApprovalPreviewLabel::TechnicalTranslatedReferences, implode(', ', array_column($translated, 'reference')))];
        $elements = array_values(array_unique(array_filter(array_column($translated, 'element'), static fn (int $element): bool => $element > 0)));
        if ($elements !== []) {
            $details[] = $t(ApprovalPreviewLabel::TechnicalTranslatedElements, implode(', ', $elements));
        }

        return [$lines, $details];
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the page permission, the storage gate and the
        // field-level grants are the acting user's own, checked here and
        // enforced a second time by the DataHandler.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    /**
     * The file reference the call replaces or removes, with no field
     * (ADR-214). A replacement creates a new reference, but the card is about
     * the one it replaces, which exists and is what the arguments name.
     */
    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return PendingWriteTarget::fromArguments(self::REFERENCE_TABLE, $arguments['reference'] ?? null);
    }

    /**
     * In a draft workspace the call writes there (ADR-274): refused before the
     * approval card only where the run did not record that workspace, or where
     * an installation took the versioning off `tt_content` or
     * `sys_file_reference` — TYPO3 would then keep no draft (ADR-262). The
     * loop asks this, and so does `execute()` first.
     *
     * @param array<string, mixed> $arguments
     */
    private function refusalBeforeApprovalInItsWorkspace(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        return $this->refusalInItsWorkspace($arguments, $context, $this->movedInTheWorkspace(...), self::CONTENT_TABLE, self::REFERENCE_TABLE);
    }

    /**
     * The refusal of a call whose element, or a translated element core
     * deletes an overlay from along with the reference, was moved in the
     * workspace, or null (ADR-274). Such an element's references moved with
     * it (move versions). Core turns a delete of a reference by its live uid
     * into discarding that reference's move (`deleteAction()`, 13.4.37 and
     * 14.3.10) and has no command that deletes a moved reference and keeps
     * the move, so neither a replacement nor a removal can be written as
     * asked, whatever the approver decides.
     *
     * @param array<string, mixed> $plan the plan of {@see self::plan()}
     */
    private function movedInTheWorkspace(array $plan, BackendUserAuthentication $user): ?string
    {
        $workspace  = max(0, $user->workspace);
        $translated = is_array($plan['translated'] ?? null) ? $plan['translated'] : [];
        $elements   = [self::toInt($plan['element'] ?? 0)];
        $references = [self::toInt($plan['reference'] ?? 0)];
        foreach ($translated as $reference) {
            $elements[]   = is_array($reference) ? self::toInt($reference['element'] ?? 0) : 0;
            $references[] = is_array($reference) ? self::toInt($reference['reference'] ?? 0) : 0;
        }

        foreach ($elements as $elementUid) {
            if ($elementUid > 0 && $this->isMovedIn(self::CONTENT_TABLE, $elementUid, $workspace)) {
                return sprintf(
                    'Refused: content element [%d] was moved in this workspace. TYPO3 treats deleting a file reference of a '
                    . "moved element as discarding that reference's move, so this tool cannot replace or remove it there. "
                    . 'Publish the move or discard it in the Workspaces module first. Nothing was written.',
                    $elementUid,
                );
            }
        }

        // A reference can also be moved on its own with core's `move`
        // command while its element stays (measured, 14.3.10): it carries a
        // move version then, and the delete would discard that move too.
        foreach ($references as $referenceUid) {
            if ($referenceUid > 0 && $this->isMovedIn(self::REFERENCE_TABLE, $referenceUid, $workspace)) {
                return sprintf(
                    'Refused: file reference [%d] was moved in this workspace. TYPO3 treats deleting a moved file reference '
                    . 'as discarding its move, so this tool cannot replace or remove it there. Publish the move or discard it '
                    . 'in the Workspaces module first. Nothing was written.',
                    $referenceUid,
                );
            }
        }

        return null;
    }

    public function getEffect(): ToolEffect
    {
        // A replacement creates a reference row; a repeat would find the old
        // one gone and be refused, and a removal likewise. Neither is a write
        // a reaped run may silently do again.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * Everything the write needs, resolved and authorised — or the refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{reference:int, element:int, header:string, page:int, pageTitle:string, field:string, language:int, references:list<int>, position:int, oldFile:int, oldFileName:string, file:int|null, fileName:string, texts:array<string, string>, translated:list<array{reference:int, element:int, language:int}>, draftOnly:bool}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        return self::planOrRefusal(fn (): array => $this->resolvePlan($arguments, $user));
    }

    /**
     * The checks of {@see self::plan()}, in order; the first that fails throws
     * its refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{reference:int, element:int, header:string, page:int, pageTitle:string, field:string, language:int, references:list<int>, position:int, oldFile:int, oldFileName:string, file:int|null, fileName:string, texts:array<string, string>, translated:list<array{reference:int, element:int, language:int}>, draftOnly:bool}
     *
     * @throws PlanRefusedException
     */
    private function resolvePlan(array $arguments, BackendUserAuthentication $user): array
    {
        self::refuseIf($this->refuseUnknownArguments(
            $arguments,
            ['reference', 'action', 'file', ...self::TEXT_FIELDS],
            'replaces the file of one file reference on a content element, or removes the reference',
        ));

        $referenceUid = self::toInt($arguments['reference'] ?? 0);
        if ($referenceUid < 1) {
            self::refuse('Refused: "reference" must be the positive sys_file_reference uid of exactly one reference.');
        }

        $action = $arguments['action'] ?? null;
        if ($action !== 'replace' && $action !== 'remove') {
            self::refuse('Refused: "action" must be "replace" or "remove".');
        }

        $fileUid = null;
        $texts   = [];
        if ($action === 'replace') {
            $fileUid = self::toInt($arguments['file'] ?? 0);
            if ($fileUid < 1) {
                self::refuse('Refused: "replace" needs "file", the positive sys_file uid of exactly one existing file.');
            }

            foreach (self::TEXT_FIELDS as $name) {
                if (!array_key_exists($name, $arguments)) {
                    continue;
                }

                if (!is_string($arguments[$name]) && !is_numeric($arguments[$name])) {
                    self::refuse(sprintf('Refused: the value for "%s" must be a string.', $name));
                }

                // The column's own limit where it has one (`title` is 255).
                $text = trim(self::toStr($arguments[$name]));
                $max  = $this->textLimitOf(self::REFERENCE_TABLE, $name, self::MAX_TEXT_LENGTH);
                if (mb_strlen($text) > $max) {
                    self::refuse(sprintf('Refused: "%s" is longer than %d characters.', $name, $max));
                }

                $texts[$name] = $text;
            }
        } else {
            foreach (['file', ...self::TEXT_FIELDS] as $name) {
                if (array_key_exists($name, $arguments)) {
                    self::refuse(sprintf('Refused: "%s" belongs to "replace"; "remove" takes only "reference".', $name));
                }
            }
        }

        // As the user's workspace shows them (ADR-274): a reference, element
        // or page changed there is read with its version, one new there is
        // found, one deleted there or of another workspace is not.
        $workspace = max(0, $user->workspace);
        $reference = $this->referenceShownIn($referenceUid, $workspace);
        $field     = self::toStr($reference['fieldname'] ?? '');
        if ($reference === null
            || self::toStr($reference['tablenames'] ?? '') !== self::CONTENT_TABLE
            || !in_array($field, self::WRITABLE_FIELDS, true)
        ) {
            self::refuse(self::NOT_PERMITTED);
        }

        // The page the element shows on in the workspace takes the new
        // reference; the element's STORED row — for an element moved in the
        // workspace the one on the page it left, without an edit lock set on
        // its draft — is what core asks before it updates the element, as the
        // attaching writers ask it (ADR-264).
        $elementUid = self::toInt($reference['uid_foreign'] ?? 0);
        $element    = $this->rowSeenIn(self::CONTENT_TABLE, $elementUid, $workspace);
        $page       = $element === null ? null : $this->pageForWriteIn(self::toInt($element['pid'] ?? 0), $user, Permission::CONTENT_EDIT);
        if ($element === null || $page === null
            || !$this->mayUpdateStoredRecord(self::CONTENT_TABLE, $elementUid, $user)
            || (!$user->isAdmin() && !$user->check('tables_modify', self::REFERENCE_TABLE))
        ) {
            self::refuse(self::NOT_PERMITTED);
        }

        // An element can be an inline child too (EXT:news holds content
        // elements that way): every record that holds it is asked (ADR-230).
        $this->refuseUnlessOwnersMayBeEdited(self::CONTENT_TABLE, $element, $user);

        $oldFile = $this->fetchFile(self::toInt($reference['uid_local'] ?? 0));

        $newFile = null;
        if ($fileUid !== null) {
            $newFile = $this->fetchFile($fileUid);
            if ($newFile === null
                || !$this->storageGate->isFileAccessible($user, self::toInt($newFile['storage'] ?? 0), self::toStr($newFile['identifier'] ?? ''))
            ) {
                self::refuse(self::NOT_PERMITTED);
            }

            if ($fileUid === self::toInt($reference['uid_local'] ?? 0)) {
                self::refuse(sprintf('Refused: reference [%d] already points at file [%d]; there is nothing to replace.', $referenceUid, $fileUid));
            }

            $extension = strtolower(self::toStr($newFile['extension'] ?? ''));
            $allowed   = $this->allowedExtensions($field);
            if ($allowed !== null && !in_array($extension, $allowed, true)) {
                self::refuse(sprintf(
                    'Refused: field "%s" does not accept a .%s file. It accepts: %s.',
                    $field,
                    $extension === '' ? self::NONE : $extension,
                    implode(', ', $allowed),
                ));
            }
        }

        $ungranted = [
            ...array_map(
                static fn (string $column): string => self::CONTENT_TABLE . ':' . $column,
                $this->columnsTheUserMayNotSet($user, self::CONTENT_TABLE, [$field]),
            ),
            ...array_map(
                static fn (string $column): string => self::REFERENCE_TABLE . ':' . $column,
                $this->columnsTheUserMayNotSet($user, self::REFERENCE_TABLE, array_keys($texts)),
            ),
        ];
        if ($ungranted !== []) {
            self::refuse(sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was written.',
                implode(', ', $ungranted),
            ));
        }

        // Core deletes the translated overlays of a default-language
        // reference together with it (`deleteL10nOverlayRecords()`), so they
        // are part of this act: the translated element each sits on must be
        // the acting user's to change (its language, lock, content type and
        // field grant; `tables_modify` for the references is asked above) —
        // refused up front, as the other ADR-198 writers refuse a translation
        // core carries along.
        $translated = [];
        foreach ($this->translatedReferencesOf($referenceUid, $field, $workspace) as $overlay) {
            $translatedElement = $this->rowSeenIn(self::CONTENT_TABLE, self::toInt($overlay['uid_foreign'] ?? 0), $workspace);
            $overlayLanguage   = self::toInt($overlay['sys_language_uid'] ?? 0);
            if ($translatedElement === null) {
                // An orphan: its element is gone or not live. Core deletes it
                // with the reference all the same, and there is no element to
                // settle — only its language is the user's question.
                if (!$user->checkLanguageAccess($overlayLanguage)) {
                    self::refuse(sprintf(
                        'Refused: reference [%d] has a translated reference in language %d, which the acting backend user '
                        . 'may not edit, and core deletes it with this one. Nothing was written.',
                        $referenceUid,
                        $overlayLanguage,
                    ));
                }

                $translated[] = ['reference' => self::toInt($overlay['uid'] ?? 0), 'element' => 0, 'language' => $overlayLanguage];

                continue;
            }

            // Asked as for the element itself: the page the translation shows
            // on, and its STORED row, which core asks before it settles it.
            $translatedPage = $this->pageForWriteIn(self::toInt($translatedElement['pid'] ?? 0), $user, Permission::CONTENT_EDIT);
            if ($translatedPage === null
                || !$this->mayUpdateStoredRecord(self::CONTENT_TABLE, self::toInt($translatedElement['uid'] ?? 0), $user)
                || $this->columnsTheUserMayNotSet($user, self::CONTENT_TABLE, [$field]) !== []
            ) {
                self::refuse(sprintf(
                    'Refused: reference [%d] has a translated reference in language %d which the acting backend user may '
                    . 'not change, and core deletes it with this one. Nothing was written.',
                    $referenceUid,
                    self::toInt($overlay['sys_language_uid'] ?? 0),
                ));
            }

            // Core deletes the overlay out of what the translated element
            // holds, so whatever holds the translated element as an inline
            // child is asked too, as for the element itself (ADR-230).
            $this->refuseUnlessOwnersMayBeEdited(self::CONTENT_TABLE, $translatedElement, $user);

            $translated[] = [
                'reference' => self::toInt($overlay['uid'] ?? 0),
                'element'   => self::toInt($translatedElement['uid'] ?? 0),
                'language'  => self::toInt($overlay['sys_language_uid'] ?? 0),
            ];
        }

        $references = $this->referenceUidsShownIn($elementUid, $field, $workspace);
        $position   = array_search($referenceUid, $references, true);

        return [
            'reference'   => $referenceUid,
            'element'     => $elementUid,
            'header'      => self::toStr($element['header'] ?? ''),
            'page'        => self::toInt($page['uid'] ?? 0),
            'pageTitle'   => self::toStr($page['title'] ?? ''),
            'field'       => $field,
            'language'    => self::toInt($reference['sys_language_uid'] ?? 0),
            'references'  => $references,
            'position'    => is_int($position) ? $position : count($references),
            'oldFile'     => self::toInt($reference['uid_local'] ?? 0),
            'oldFileName' => self::toStr($oldFile['name'] ?? ''),
            'file'        => $fileUid,
            'fileName'    => self::toStr($newFile['name'] ?? ''),
            'texts'       => $texts,
            'translated'  => $translated,
            // New in the workspace: replacing or removing it discards it.
            'draftOnly' => $workspace > 0
                && self::toInt($reference['t3ver_wsid'] ?? 0) === $workspace
                && self::toInt($reference['t3ver_state'] ?? 0) === 1,
        ];
    }

    /**
     * A new reference at the old one's position, then the old one deleted —
     * one DataHandler run, the datamap first and read back before the
     * cmdmap, as {@see SetPageSocialImageTool} does it.
     *
     * In a draft workspace the element's field is named by the rows that hold
     * its references there — their versions, since the element's version was
     * made — and the old reference is deleted by the uid it is addressed
     * under: its live uid, which core turns into a delete placeholder, or its
     * own uid where it is new in the workspace, which core discards (ADR-274).
     *
     * @param array{reference:int, element:int, header:string, page:int, pageTitle:string, field:string, language:int, references:list<int>, position:int, oldFile:int, oldFileName:string, file:int|null, fileName:string, texts:array<string, string>, translated:list<array{reference:int, element:int, language:int}>, draftOnly:bool} $plan
     * @param int                                                                                                                                                                                                                                                                                                                          $version           the element version this call made in a draft workspace, or 0
     * @param array<string, array<string, int|string|null>>                                                                                                                                                                                                                                                                                $before            the workspace around the element before the version was made
     * @param string                                                                                                                                                                                                                                                                                                                       $versionComplaints what TYPO3 reported while making it, for the answer
     */
    private function replace(array $plan, int $version, string $versionComplaints, array $before, BackendUserAuthentication $user): ToolResult
    {
        $workspace   = max(0, $user->workspace);
        $rows        = $this->referenceRowsShownIn($plan['element'], $plan['field'], $workspace);
        $placeholder = StringUtility::getUniqueId('NEW');
        $list        = array_map(
            static fn (int $uid, int $row): string => $uid === $plan['reference'] ? $placeholder : (string) $row,
            array_keys($rows),
            array_values($rows),
        );

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(
            [
                self::REFERENCE_TABLE => [
                    $placeholder => $plan['texts'] + [
                        'uid_local'   => $plan['file'],
                        'tablenames'  => self::CONTENT_TABLE,
                        'uid_foreign' => $plan['element'],
                        'fieldname'   => $plan['field'],
                        'pid'         => $plan['page'],
                        // Stated rather than defaulted: the DataHandler checks
                        // language access against the record it is handed.
                        'sys_language_uid' => $plan['language'],
                    ],
                ],
                self::CONTENT_TABLE => [
                    $plan['element'] => [$plan['field'] => implode(',', $list)],
                ],
            ],
            [self::REFERENCE_TABLE => [$plan['reference'] => ['delete' => 1]]],
            $user,
        );
        $dataHandler->process_datamap();

        // The read-back decides whatever the error log says (ADR-198): a
        // complaint can be about another record of the run while the new
        // reference landed in place.
        // Where no row came into being, TYPO3's refusal is the reason given;
        // where the read-back fails, what it found is, with TYPO3's
        // complaints after it.
        $newUid  = self::toInt($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($newUid < 1) {
            return ToolResult::error(
                ($refused->content ?? 'The new reference was not created, and the DataHandler reported no error.')
                . $versionComplaints . ' ' . $this->restore($newUid, $plan, array_values($rows), $version, $before, $user),
            );
        }

        // Read back BEFORE the old reference is deleted: the new row must
        // point at the element, the field and the file, and sit where the old
        // one sat. Otherwise the element goes back to the list it had.
        $expected = array_map(static fn (int $uid): int => $uid === $plan['reference'] ? $newUid : $uid, $plan['references']);
        $mismatch = $this->mismatch($newUid, $plan, $expected, [$plan['reference']], $workspace);
        if ($mismatch !== null) {
            return ToolResult::error($mismatch . $versionComplaints . $this->complaintsNote($dataHandler) . ' ' . $this->restore($newUid, $plan, array_values($rows), $version, $before, $user));
        }

        $dataHandler->process_cmdmap();
        if ($this->referenceUidsShownIn($plan['element'], $plan['field'], $workspace) !== $expected) {
            // In a draft workspace the element version this call made goes,
            // and with it both references below it, as set_page_social_image
            // takes its page version back in the same case.
            return ToolResult::error(sprintf(
                'Reference [%d] was created, but the old reference [%d] was not removed from "%s": the element %s.%s%s',
                $newUid,
                $plan['reference'],
                $plan['field'],
                $version > 0 ? 'would carry both' : 'now carries both, and one has to be removed by hand',
                $versionComplaints . $this->complaintsNote($dataHandler),
                $this->discardOwnVersion($version, $before, $user),
            ));
        }

        $settled = $this->settleTranslations($plan, $user);

        // The texts asked for are part of the plan as much as the file: a hook
        // or a rule of the column can drop or rewrite one without a word, and
        // the reference then carries the new file without the text the card
        // showed. That leaves the write PARTIAL, not undone (ADR-214).
        $notTaken = $this->textsThatDidNotTake($newUid, $plan['texts'], $workspace);
        if ($notTaken !== []) {
            $settled = [
                'sentence' => $settled['sentence'] . sprintf(
                    ' Not completely: %s did not take on the new reference — the DataHandler dropped or changed the value.',
                    implode(', ', $notTaken),
                ),
                'completeness' => WriteCompleteness::PARTIAL,
            ];
        }

        return ToolResult::text(sprintf(
            'Replaced file [%d] "%s" with file [%d] "%s" in %s of tt_content [%d] "%s" (reference [%d] is now [%d]).%s%s%s%s',
            $plan['oldFile'],
            $this->excerpt($plan['oldFileName']),
            (int) $plan['file'],
            $this->excerpt($plan['fileName']),
            $plan['field'],
            $plan['element'],
            $this->excerpt($plan['header']),
            $plan['reference'],
            $newUid,
            $settled['sentence'],
            $this->draftWorkspaceNote($user),
            $versionComplaints,
            $this->complaintsNote($dataHandler),
        ))->withWriteTarget(new RecordReference(self::REFERENCE_TABLE, $newUid), WriteKind::CREATED, $settled['completeness']);
    }

    /**
     * The reference deleted and the element's field set to the references
     * that remain, as the page module removes one. In a draft workspace the
     * field is named by the rows that hold the remaining references there and
     * the reference is deleted by the uid it is addressed under, as in
     * {@see self::replace()} (ADR-274).
     *
     * @param array{reference:int, element:int, header:string, page:int, pageTitle:string, field:string, language:int, references:list<int>, position:int, oldFile:int, oldFileName:string, file:int|null, fileName:string, texts:array<string, string>, translated:list<array{reference:int, element:int, language:int}>, draftOnly:bool} $plan
     * @param int                                                                                                                                                                                                                                                                                                                          $version           the element version this call made in a draft workspace, or 0
     * @param array<string, array<string, int|string|null>>                                                                                                                                                                                                                                                                                $before            the workspace around the element before the version was made
     * @param string                                                                                                                                                                                                                                                                                                                       $versionComplaints what TYPO3 reported while making it, for the answer
     */
    private function remove(array $plan, int $version, string $versionComplaints, array $before, BackendUserAuthentication $user): ToolResult
    {
        $workspace     = max(0, $user->workspace);
        $remaining     = array_values(array_filter($plan['references'], static fn (int $uid): bool => $uid !== $plan['reference']));
        $rows          = $this->referenceRowsShownIn($plan['element'], $plan['field'], $workspace);
        $remainingRows = array_values(array_diff_key($rows, [$plan['reference'] => true]));

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(
            [self::CONTENT_TABLE => [$plan['element'] => [$plan['field'] => implode(',', $remainingRows)]]],
            [self::REFERENCE_TABLE => [$plan['reference'] => ['delete' => 1]]],
            $user,
        );
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();

        // The datamap and the cmdmap have both run whatever either reported,
        // so the answer says what the element carries now. The read-back
        // decides (ADR-198): a complaint about another record of the run,
        // with the reference removed as asked, is passed on beside the write.
        $mismatch = $this->mismatch(0, $plan, $remaining, [$plan['reference']], $workspace);
        if ($mismatch !== null) {
            return ToolResult::error(sprintf(
                '%s%s tt_content [%d] now carries reference(s) %s in "%s".%s',
                $mismatch,
                $versionComplaints . $this->complaintsNote($dataHandler),
                $plan['element'],
                implode(', ', $this->referenceUidsShownIn($plan['element'], $plan['field'], $workspace)) ?: self::NONE,
                $plan['field'],
                $this->discardOwnVersion($version, $before, $user),
            ));
        }

        $settled = $this->settleTranslations($plan, $user);

        return ToolResult::text(sprintf(
            'Removed reference [%d] to file [%d] "%s" from %s of tt_content [%d] "%s"; %d reference(s) remain. The file '
            . 'itself is unchanged.%s%s%s%s',
            $plan['reference'],
            $plan['oldFile'],
            $this->excerpt($plan['oldFileName']),
            $plan['field'],
            $plan['element'],
            $this->excerpt($plan['header']),
            count($remaining),
            $settled['sentence'],
            $this->draftWorkspaceNote($user),
            $versionComplaints,
            $this->complaintsNote($dataHandler),
        ))->withWriteTarget(new RecordReference(self::REFERENCE_TABLE, $plan['reference']), WriteKind::DELETED, $settled['completeness']);
    }

    /**
     * What the write left different from the plan, or null.
     *
     * @param array{element:int, field:string, file:int|null} $plan
     * @param list<int>                                       $expected  the live references the field must hold, in order
     * @param list<int>                                       $gone      references that must no longer be live
     * @param int                                             $workspace the user's workspace: there the references it shows, the new one new in it, and the counter of the element's version (ADR-274)
     */
    private function mismatch(int $newUid, array $plan, array $expected, array $gone, int $workspace): ?string
    {
        if ($newUid > 0) {
            $row = $this->storedRowIn(self::REFERENCE_TABLE, $newUid, $workspace);
            if ($row === null
                || self::toInt($row['uid_foreign'] ?? 0) !== $plan['element']
                || self::toStr($row['tablenames'] ?? '') !== self::CONTENT_TABLE
                || self::toStr($row['fieldname'] ?? '') !== $plan['field']
                || self::toInt($row['uid_local'] ?? 0) !== $plan['file']
            ) {
                return 'The new reference was not stored against the element, field and file asked for.';
            }

            // Only the new row: the old one is still live until the cmdmap runs.
            $live = array_values(array_diff($this->referenceUidsShownIn($plan['element'], $plan['field'], $workspace), $gone));
        } else {
            $live = $this->referenceUidsShownIn($plan['element'], $plan['field'], $workspace);
        }

        if ($live !== $expected) {
            return sprintf('The references in "%s" of tt_content [%d] are not in the order asked for afterwards.', $plan['field'], $plan['element']);
        }

        $element = $this->rowSeenIn(self::CONTENT_TABLE, $plan['element'], $workspace);
        if (self::toInt($element[$plan['field']] ?? -1) !== count($expected)) {
            return sprintf(
                'tt_content [%d] counts %d reference(s) in "%s" where %d were expected, so the relation is inconsistent.',
                $plan['element'],
                self::toInt($element[$plan['field']] ?? -1),
                $plan['field'],
                count($expected),
            );
        }

        return null;
    }

    /**
     * The asked texts the new reference does not hold as asked, by name. A row
     * that cannot be read back verifies nothing, so every text counts; that
     * case is not reached today, because the read-back before the cmdmap
     * already refuses a new row that is not there.
     *
     * @param array<string, string> $texts
     *
     * @return list<string>
     */
    private function textsThatDidNotTake(int $referenceUid, array $texts, int $workspace): array
    {
        $row = $this->storedRowIn(self::REFERENCE_TABLE, $referenceUid, $workspace);

        $missed = [];
        foreach ($texts as $name => $expected) {
            if (!self::storedTextHolds($row[$name] ?? null, $expected)) {
                $missed[] = $name;
            }
        }

        return $missed;
    }

    /**
     * Take a failed replacement back — the new reference deleted, the
     * element's field set to the list it had — and say whether that worked.
     *
     * In a draft workspace where this call made the element's version, that
     * version is discarded instead, and with it the reference the call added:
     * the workspace is as it was (ADR-264). Otherwise the new reference — new
     * in the workspace there — is discarded by its own uid and the field named
     * by the rows it held before.
     *
     * @param array{element:int, field:string, references:list<int>} $plan
     * @param list<int>                                              $rows    the rows that held the element's references before the write
     * @param int                                                    $version the element version this call made in a draft workspace, or 0
     * @param array<string, array<string, int|string|null>>          $before  the workspace around the element before the version was made
     */
    private function restore(int $newUid, array $plan, array $rows, int $version, array $before, BackendUserAuthentication $user): string
    {
        if ($version > 0) {
            return trim($this->discardOwnVersion($version, $before, $user));
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(
            [self::CONTENT_TABLE => [$plan['element'] => [$plan['field'] => implode(',', $rows)]]],
            $newUid > 0 ? [self::REFERENCE_TABLE => [$newUid => ['delete' => 1]]] : [],
            $user,
        );
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();

        $live = $this->referenceUidsShownIn($plan['element'], $plan['field'], max(0, $user->workspace));

        if ($live === $plan['references']) {
            return 'The element was put back as it was.';
        }

        return sprintf(
            'Putting the element back did not complete: it now carries reference(s) %s in "%s" — check it by hand.',
            implode(', ', $live) ?: self::NONE,
            $plan['field'],
        );
    }

    /**
     * After core deleted the translated overlays: set each translated
     * element's field to the references it still carries, so its counter
     * matches its rows, and say what came of it — '' when there were none,
     * otherwise a sentence for the answer.
     *
     * The completeness is decided here, beside the sentence, because this is
     * where it is known (ADR-214): translations that are not settled leave
     * the write PARTIAL, whatever the element itself carries.
     *
     * @param array{field:string, translated:list<array{reference:int, element:int, language:int}>} $plan
     *
     * @return array{sentence: string, completeness: WriteCompleteness}
     */
    private function settleTranslations(array $plan, BackendUserAuthentication $user): array
    {
        if ($plan['translated'] === []) {
            return ['sentence' => '', 'completeness' => WriteCompleteness::COMPLETE];
        }

        $elements = array_values(array_filter(
            array_unique(array_map(static fn (array $t): int => $t['element'], $plan['translated'])),
            static fn (int $element): bool => $element > 0,
        ));
        $workspace = max(0, $user->workspace);
        $datamap   = [];
        foreach ($elements as $elementUid) {
            $datamap[$elementUid] = [$plan['field'] => implode(',', $this->referenceRowsShownIn($elementUid, $plan['field'], $workspace))];
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([self::CONTENT_TABLE => $datamap], [], $user);
        $dataHandler->process_datamap();

        $problems = [];
        foreach ($plan['translated'] as $translation) {
            if ($this->referenceShownIn($translation['reference'], $workspace) !== null) {
                $problems[] = sprintf('translated reference [%d] is still there', $translation['reference']);
            }
        }

        foreach ($elements as $elementUid) {
            $row   = $this->rowSeenIn(self::CONTENT_TABLE, $elementUid, $workspace);
            $count = count($this->referenceUidsShownIn($elementUid, $plan['field'], $workspace));
            if ($row === null || self::toInt($row[$plan['field']] ?? -1) !== $count) {
                $problems[] = sprintf('translated element [%d] does not count its %d reference(s)', $elementUid, $count);
            }
        }

        if ($problems === []) {
            return [
                'sentence'     => sprintf(' Its %d translated reference(s) were deleted with it.%s', count($plan['translated']), $this->complaintsNote($dataHandler)),
                'completeness' => WriteCompleteness::COMPLETE,
            ];
        }

        return [
            'sentence' => sprintf(
                ' The translations are not settled: %s.%s',
                implode('; ', $problems),
                $this->complaintsNote($dataHandler),
            ),
            'completeness' => WriteCompleteness::PARTIAL,
        ];
    }

    /**
     * The undeleted translated overlays of a reference on one field: the live
     * ones, or in a draft workspace those the workspace shows, each under its
     * live uid (ADR-274).
     *
     * @return list<array<string, mixed>>
     */
    private function translatedReferencesOf(int $referenceUid, string $field, int $workspace): array
    {
        if ($this->readsWorkspaceRows(self::REFERENCE_TABLE, $workspace)) {
            return array_values(array_filter(
                $this->translationsInWorkspace(self::REFERENCE_TABLE, $referenceUid, $workspace, 'l10n_parent', 'sys_language_uid'),
                static fn (array $row): bool => self::toStr($row['tablenames'] ?? '') === self::CONTENT_TABLE
                    && self::toStr($row['fieldname'] ?? '') === $field,
            ));
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from(self::REFERENCE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($referenceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::CONTENT_TABLE)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                ...$this->liveVersionConstraints($queryBuilder, self::REFERENCE_TABLE),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * An undeleted reference as the user's workspace shows it, under the uid it
     * is addressed by — the live row overlaid with its version, or a row new
     * in that workspace — or null: deleted there, a version row named by its
     * own uid, another workspace's row. In live the live row.
     *
     * @return array<string, mixed>|null
     */
    private function referenceShownIn(int $uid, int $workspace): ?array
    {
        return $this->rowSeenIn(self::REFERENCE_TABLE, $uid, $workspace);
    }

    /**
     * The references on one field of an element as the user's workspace shows
     * them, in their order there, each by the uid it is addressed under (its
     * live uid, or its own where it is new there). In live the live
     * references, in their stored order.
     *
     * @return list<int>
     */
    private function referenceUidsShownIn(int $elementUid, string $field, int $workspace): array
    {
        return array_keys($this->referenceRowsShownIn($elementUid, $field, $workspace));
    }

    /**
     * {@see self::referenceUidsShownIn()} with the row that holds each
     * reference in the workspace: its version there where it has one, else
     * the row itself. The rows are what the element's field is named with,
     * so a new reference lands where the workspace shows the others (ADR-264);
     * the keys are what a delete addresses (ADR-274). In live both are the
     * same.
     *
     * @return array<int, int> the addressed uid => the row's uid
     */
    private function referenceRowsShownIn(int $elementUid, string $field, int $workspace): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid', 't3ver_oid')
            ->from(self::REFERENCE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($elementUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::CONTENT_TABLE)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                ...$this->rowsShownIn($queryBuilder, self::REFERENCE_TABLE, $workspace, [
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                ]),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $shown = [];
        foreach ($rows as $row) {
            $shown[$this->liveUid($row)] = self::toInt($row['uid'] ?? 0);
        }

        return $shown;
    }

    /**
     * Take back the element version this call made, and say what came of it
     * in a sentence for the answer, '' where the call made none: the
     * workspace as it was, or what of it is not (ADR-264, ADR-274).
     *
     * @param array<string, array<string, int|string|null>> $before the workspace around the element before the version was made
     */
    private function discardOwnVersion(int $version, array $before, BackendUserAuthentication $user): string
    {
        if ($version < 1) {
            return '';
        }

        return ' ' . $this->takeBackSentence('the element', $this->discardVersion(self::CONTENT_TABLE, $version, $before, $user));
    }

    /**
     * The extensions a `tt_content` file field accepts, or null when it
     * accepts anything.
     *
     * @return list<string>|null
     */
    private function allowedExtensions(string $field): ?array
    {
        $column  = ($this->tcaColumnsFor(self::CONTENT_TABLE) ?? [])[$field] ?? null;
        $config  = is_array($column) ? ($column['config'] ?? null) : null;
        $allowed = is_array($config) ? self::toStr($config['allowed'] ?? '') : '';
        if ($allowed === '') {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part)),
            explode(',', $allowed),
        )));
    }
}
