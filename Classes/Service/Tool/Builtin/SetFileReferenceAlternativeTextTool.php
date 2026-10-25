<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

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
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Set the alternative text of ONE existing file reference, in place, through
 * the DataHandler, as the acting backend user (ADR-230).
 *
 * An alternative text belongs to one use of an image: the reference
 * (`sys_file_reference.alternative`), not the file. {@see SetFileAlternativeTextTool}
 * writes the file's default (`sys_file_metadata.alternative`), which every use
 * without a text of its own falls back to; this tool writes the one use. The
 * two are field-disjoint, as the two metadata writers are (ADR-192).
 *
 * It writes that one column of that one row and nothing else: not the file,
 * not the parent record, not the translations of the reference. The
 * reference keeps its language; a translated reference is written in its own
 * language, where the acting user may edit that language.
 *
 * What it refuses, and why:
 *
 * - **An empty text, also one of spaces only.** Siblings read `''` as
 *   "decorative"; here it is refused. Whether an image is decorative is a
 *   decision of its own, which an extension that owns that concept records,
 *   and an empty alternative text looks exactly like a forgotten one.
 * - **A reference that is gone, deleted, a workspace version, or that does
 *   not sit in a `type => file` column of a table the TCA declares**, and a
 *   parent record that is gone — all with the neutral refusal, so a refusal
 *   never confirms that a uid exists.
 * - **A parent the acting user may not edit**: edit rights on the page for a
 *   page's own file field, content-edit rights on the record's page
 *   otherwise, and the record-level rights {@see ActsOnAnExistingRecordTrait::mayEditRecord()}
 *   asks (`tables_modify`, language, `authMode`, edit lock). A denylisted
 *   table, and an `adminOnly` or `readOnly` one, are refused the same way.
 * - **A reference in a language the acting user may not edit**, said so.
 * - **A missing field-level grant** for `sys_file_reference:alternative` or
 *   for the parent's file field, said so — the DataHandler would drop the
 *   value in silence.
 * - **A draft workspace and a process without a backend environment**,
 *   through {@see WritesThroughDataHandlerTrait}.
 *
 * The DataHandler then asks its own questions on the reference row, content
 * edit on its page and `tables_modify` for `sys_file_reference` among them,
 * and the read-back reports a value that did not land as a failure.
 *
 * Effect: {@see ToolEffect::IDEMPOTENT_WRITE} — one named scalar set to a
 * given value converges on repeat.
 */
final readonly class SetFileReferenceAlternativeTextTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The plan, the viewer gate, the unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;
    // Language, label and record-level rights of the parent record.
    use ActsOnAnExistingRecordTrait;
    use FetchesSysFileRowTrait;
    // The plan's checks throw their refusal instead of returning it.
    use RefusesPlanStepsTrait;

    /** The neutral refusal for a reference, a record or a page that is not there or not the user's. */
    private const NOT_PERMITTED = 'File reference or its record not found, or not permitted.';

    private const PAGES_TABLE = 'pages';

    private const REFERENCE_TABLE = 'sys_file_reference';

    /** The one column this tool writes. */
    private const FIELD = 'alternative';

    /** The language value of a record shown in every language. */
    private const ALL_LANGUAGES = -1;

    /**
     * Upper bound for the text, as the sibling writers bound it: the column
     * is `text` and core declares no `max`, and an alternative text is a
     * sentence, not a document.
     */
    private const MAX_TEXT_LENGTH = 1000;

    public function __construct(
        private ConnectionPool $connectionPool,
        private TableReadAccessService $tableAccess,
        private ApprovalPreviewTranslator $translator,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'set_file_reference_alternative_text',
            'Set the alternative text (alt text) of ONE existing file reference (sys_file_reference): one use of an '
            . 'image on a content element, a page or any other record, identified by the reference uid. The text '
            . "applies to this use only; the file's own alternative text (set_file_alternative_text) is not changed. "
            . 'The reference keeps its language. Writes through the TYPO3 DataHandler as the acting backend user, in '
            . 'the live workspace only. An empty text is refused: marking an image as decorative is not an empty '
            . 'alternative text.',
            [
                'type'       => 'object',
                'properties' => [
                    'reference' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file_reference uid of the single image use to describe.',
                    ],
                    self::FIELD => [
                        'type'        => 'string',
                        'description' => 'The new alternative text. Must not be empty.',
                    ],
                ],
                'required' => ['reference', self::FIELD],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $this->writableActingUser($context, self::REFERENCE_TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([self::REFERENCE_TABLE => [$plan['reference'] => [self::FIELD => $plan['text']]]], [], $user);
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        // Read back before reporting success: an empty errorLog is no proof
        // the value landed, a hook can drop or rewrite it in silence.
        $row = $this->fetchRowByUid(self::REFERENCE_TABLE, $plan['reference'], self::FIELD);
        if (!self::storedTextHolds($row[self::FIELD] ?? null, $plan['text'])) {
            return ToolResult::error(sprintf(
                'The alternative text did not take on file reference [%d]: the DataHandler dropped or changed the value.',
                $plan['reference'],
            ));
        }

        return ToolResult::text(sprintf(
            'Set the alternative text of file reference [%d] (file [%d] "%s" in %s of %s [%d], language %d) to %s.',
            $plan['reference'],
            $plan['file'],
            $this->excerpt($plan['fileName']),
            $plan['field'],
            $plan['table'],
            $plan['record'],
            $plan['language'],
            $this->quoted($plan['text']),
        ))->withWriteTarget(new RecordReference(self::REFERENCE_TABLE, $plan['reference']), WriteKind::UPDATED, WriteCompleteness::COMPLETE);
    }

    /**
     * The before/after this call would produce (ADR-136, ADR-213).
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user, down to the neutral refusal string. NOT checked
     * here: the live-workspace and backend-environment refusals, which describe
     * the process performing the write.
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

        [$t, $q] = $this->translator->boundTo($user, $this->excerpt(...));
        $name    = $t(ApprovalPreviewLabel::FileFieldAlternative);

        // ADR-213, in the order of the editorial guidelines: what, which
        // record, where, its language, the field, the file, the text.
        $lines = [
            $t(ApprovalPreviewLabel::SetReferenceAlternativeTextHeading),
            match ($plan['table']) {
                self::PAGES_TABLE => $t(ApprovalPreviewLabel::ObjectPage, $q($plan['title'])),
                'tt_content'      => $t(ApprovalPreviewLabel::ObjectContent, $q($plan['title'])),
                default           => $t(ApprovalPreviewLabel::ObjectRecord, $this->translator->tableLabel($user, $plan['table']), $q($plan['title'])),
            },
        ];
        if ($plan['table'] !== self::PAGES_TABLE && $plan['page'] > 0) {
            $lines[] = $t(ApprovalPreviewLabel::LocationOnPage, $q($plan['pageTitle']));
        }

        $lines[] = $t(match (true) {
            $plan['language'] === 0                   => ApprovalPreviewLabel::LanguageDefault,
            $plan['language'] === self::ALL_LANGUAGES => ApprovalPreviewLabel::LanguageAll,
            default                                   => ApprovalPreviewLabel::LanguageTranslation,
        });
        $lines[] = $t(ApprovalPreviewLabel::FieldName, $this->translator->columnLabel($user, $plan['table'], $plan['field']));
        $lines[] = $t(ApprovalPreviewLabel::ObjectFile, $q($plan['fileName']));

        // NULL is not ''. NULL lets the file's own text show; '' is an
        // explicit empty override. The card says which one is replaced.
        if ($plan['current'] === null) {
            $lines[] = $t(ApprovalPreviewLabel::ReferenceAlternativeNoneOfItsOwn);
        }

        [$line, $technical] = $this->fieldChange($t, $q, $name, self::FIELD, $plan['current'] ?? '', $plan['text']);
        $lines[]            = $line;

        $details = [$t(ApprovalPreviewLabel::TechnicalRecord, $plan['table'], $plan['record'])];
        if ($plan['page'] > 0) {
            $details[] = $t(ApprovalPreviewLabel::TechnicalPage, $plan['page']);
        }

        $details[] = $t(ApprovalPreviewLabel::TechnicalLanguage, $plan['language']);
        $details[] = $t(ApprovalPreviewLabel::TechnicalFields, $plan['field']);
        $details[] = $t(ApprovalPreviewLabel::TechnicalFileReference, $plan['reference']);
        $details[] = $t(ApprovalPreviewLabel::TechnicalFile, $plan['file']);
        if ($technical !== null) {
            $details[] = $technical;
        }

        $lines[] = $this->translator->technical($user, $details);

        return $lines;
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the page permission, the record-level rights,
        // the language and the field-level grants are the acting user's own,
        // checked here and enforced a second time by the DataHandler.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    /**
     * The file reference the call writes and its one field (ADR-214).
     */
    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return PendingWriteTarget::fromArguments(self::REFERENCE_TABLE, $arguments['reference'] ?? null, [self::FIELD]);
    }

    public function getEffect(): ToolEffect
    {
        // Setting one named scalar field to a given value converges on repeat,
        // so a reaped-and-requeued run may safely repeat it.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * Everything the write needs, resolved and authorised — or the refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{reference:int, text:string, current:string|null, table:non-empty-string, record:int, title:string, field:string, page:int, pageTitle:string, language:int, file:int, fileName:string}|string
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
     * @return array{reference:int, text:string, current:string|null, table:non-empty-string, record:int, title:string, field:string, page:int, pageTitle:string, language:int, file:int, fileName:string}
     *
     * @throws PlanRefusedException
     */
    private function resolvePlan(array $arguments, BackendUserAuthentication $user): array
    {
        self::refuseIf($this->refuseUnknownArguments(
            $arguments,
            ['reference', self::FIELD],
            'sets the alternative text of one file reference',
        ));

        $referenceUid = self::toInt($arguments['reference'] ?? 0);
        if ($referenceUid < 1) {
            self::refuse('Refused: "reference" must be the positive sys_file_reference uid of exactly one reference.');
        }

        $text = $this->collectText($arguments);

        // A live, undeleted row: a workspace version is another workspace's
        // draft and is treated as not there (ADR-198).
        $reference = $this->fetchRowByUid(self::REFERENCE_TABLE, $referenceUid);
        if ($reference === null) {
            self::refuse(self::NOT_PERMITTED);
        }

        $table = self::toStr($reference['tablenames'] ?? '');
        $field = self::toStr($reference['fieldname'] ?? '');
        if ($table === '' || !$this->isFileField($table, $field) || !$this->mayWriteTable($table, $user)) {
            self::refuse(self::NOT_PERMITTED);
        }

        $record = $this->fetchRowByUid($table, self::toInt($reference['uid_foreign'] ?? 0));
        if ($record === null) {
            self::refuse(self::NOT_PERMITTED);
        }

        $page = $this->editablePageOf($table, $record, $user);

        // Asked after the page, so it names a reason only to a user who may
        // edit there, and before the record-level rights, which ask the
        // parent's language and would answer neutrally.
        $language = self::toInt($reference['sys_language_uid'] ?? 0);
        if (!$user->checkLanguageAccess($language)) {
            self::refuse(sprintf(
                'Refused: file reference [%d] is in language %d, which the acting backend user may not edit. Nothing was written.',
                $referenceUid,
                $language,
            ));
        }

        if (!$this->mayEditRecord($table, $record, $user)) {
            self::refuse(self::NOT_PERMITTED);
        }

        $ungranted = [
            ...array_map(static fn (string $column): string => $table . ':' . $column, $this->columnsTheUserMayNotSet($user, $table, [$field])),
            ...array_map(static fn (string $column): string => self::REFERENCE_TABLE . ':' . $column, $this->columnsTheUserMayNotSet($user, self::REFERENCE_TABLE, [self::FIELD])),
        ];
        if ($ungranted !== []) {
            self::refuse(sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was written.',
                implode(', ', $ungranted),
            ));
        }

        $fileUid = self::toInt($reference['uid_local'] ?? 0);
        $current = $reference[self::FIELD] ?? null;

        return [
            'reference' => $referenceUid,
            'text'      => $text,
            'current'   => $current === null ? null : self::toStr($current),
            'table'     => $table,
            'record'    => self::toInt($record['uid'] ?? 0),
            'title'     => $this->labelOf($table, $record),
            'field'     => $field,
            'page'      => self::toInt($page['uid'] ?? 0),
            'pageTitle' => self::toStr($page['title'] ?? ''),
            'language'  => $language,
            'file'      => $fileUid,
            'fileName'  => self::toStr($this->fetchFile($fileUid)['name'] ?? ''),
        ];
    }

    /**
     * The validated text, or the refusal thrown.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws PlanRefusedException
     */
    private function collectText(array $arguments): string
    {
        $raw = $arguments[self::FIELD] ?? null;
        if ($raw === null) {
            self::refuse(sprintf('Refused: "%s" is required — pass the new alternative text.', self::FIELD));
        }

        if (!is_string($raw) && !is_numeric($raw)) {
            self::refuse(sprintf('Refused: the value for "%s" must be a string.', self::FIELD));
        }

        $text = trim(self::toStr($raw));
        if ($text === '') {
            self::refuse(sprintf(
                'Refused: "%s" must not be empty. An empty alternative text looks like a forgotten one; marking an '
                . 'image as decorative is a separate decision this tool does not make.',
                self::FIELD,
            ));
        }

        $max = $this->textLimitOf(self::REFERENCE_TABLE, self::FIELD, self::MAX_TEXT_LENGTH);
        if (mb_strlen($text) > $max) {
            self::refuse(sprintf('Refused: "%s" is longer than %d characters.', self::FIELD, $max));
        }

        return $text;
    }

    /**
     * Whether `$field` is a `type => file` column of a table the TCA declares
     * — the only place a reference this tool writes may sit.
     */
    private function isFileField(string $table, string $field): bool
    {
        $column = ($this->tcaColumnsFor($table) ?? [])[$field] ?? null;
        $config = is_array($column) ? ($column['config'] ?? null) : null;

        return is_array($config) && ($config['type'] ?? null) === 'file';
    }

    /**
     * Whether the parent table is one a writer may touch for this user: not
     * on the read-side denylist, not `readOnly`, and not `adminOnly` unless
     * the user is an admin.
     */
    private function mayWriteTable(string $table, BackendUserAuthentication $user): bool
    {
        if ($this->tableAccess->isSensitiveTable($table)) {
            return false;
        }

        $ctrl = $this->ctrlOf($table);
        if ((bool) ($ctrl['readOnly'] ?? false)) {
            return false;
        }

        return $user->isAdmin() || !(bool) ($ctrl['adminOnly'] ?? false);
    }

    /**
     * The page the parent record is edited on, once the user's right to edit
     * there is established — or the neutral refusal thrown.
     *
     * A page's own file field is a page property, edited with the page-edit
     * right on that page; every other record is content of its page. A record
     * on the root level (pid 0) has no page, and only an admin edits there.
     *
     * @param non-empty-string     $table
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed> the page row, empty for a record on the root level
     *
     * @throws PlanRefusedException
     */
    private function editablePageOf(string $table, array $record, BackendUserAuthentication $user): array
    {
        if ($table === self::PAGES_TABLE) {
            if (!$user->doesUserHaveAccess($record, Permission::PAGE_EDIT)) {
                self::refuse(self::NOT_PERMITTED);
            }

            return $record;
        }

        $pid = self::toInt($record['pid'] ?? 0);
        if ($pid === 0) {
            if (!$user->isAdmin()) {
                self::refuse(self::NOT_PERMITTED);
            }

            return [];
        }

        $page = $this->fetchRowByUid(self::PAGES_TABLE, $pid);
        if ($page === null || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            self::refuse(self::NOT_PERMITTED);
        }

        return $page;
    }
}
