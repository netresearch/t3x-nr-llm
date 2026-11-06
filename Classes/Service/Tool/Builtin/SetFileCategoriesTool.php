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
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\Vision\CategoryVocabulary;
use Netresearch\NrLlm\Service\Tool\Vision\VisionSettings;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Set the categories of ONE managed file from a configured category tree,
 * through the DataHandler, as the acting backend user (ADR-268).
 *
 * The tool follows {@see SetFileAlternativeTextTool} — the same file
 * resolution, storage gate and neutral refusal, the default language only, the
 * workspace the run was started in, the read-back, the approval card — and
 * differs where a relation differs from a text:
 *
 * - **It chooses from existing records only.** The allowed categories are the
 *   ones {@see CategoryVocabulary} names for the acting user: below the root an
 *   operator configured in `vision.categories.rootUid`, live, not hidden, inside
 *   the user's category mounts. A uid outside that list refuses the whole call
 *   and the refusal names the list, as ADR-194 does for the items of a select.
 *   Nothing here creates a category. Without a root every call is refused.
 * - **It sets the tree's part of the field and keeps the rest.** The call names
 *   the complete set the file should carry from the list. Categories the file
 *   carries that are not in the list — another tree, or one the user may not
 *   assign — stay, because the call could not have named them. An empty list
 *   removes the file's categories from the list and nothing else.
 * - **The refusal comes before the card** ({@see RefusesBeforeApprovalInterface}):
 *   a call naming a category outside the list is refused whatever the approver
 *   decides, so it does not wait for a decision.
 * - **The read-back reads the relation**, not the count column the DataHandler
 *   keeps beside it: the categories of the metadata record as the user's
 *   workspace shows it, compared as a set.
 *
 * Effect: {@see ToolEffect::IDEMPOTENT_WRITE} — setting a relation to a given
 * set converges on repeat. Every call is suspended for approval (ADR-134).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class SetFileCategoriesTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface, EditorActionInterface, RefusesBeforeApprovalInterface
{
    use SafeCastTrait;
    use WritesThroughDataHandlerTrait;
    use ResolvesOneFalAssetTrait;

    /** The same neutral string as the other file writers. */
    private const NOT_PERMITTED = 'Asset not found or not permitted.';

    private const FILE_TABLE = 'sys_file';

    private const METADATA_TABLE = 'sys_file_metadata';

    private const CATEGORY_TABLE = 'sys_category';

    private const RELATION_TABLE = 'sys_category_record_mm';

    private const FIELD = VisionSettings::CATEGORIES;

    public function __construct(
        private ConnectionPool $connectionPool,
        private FalStorageGate $storageGate,
        private ApprovalPreviewTranslator $translator,
        private CategoryVocabulary $vocabulary,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'set_file_categories',
            'Set the categories (sys_category) of ONE managed file (sys_file), identified by its uid, choosing only '
            . 'from the categories the request lists by uid and title. These are existing categories below the '
            . 'category an administrator configured for files; no category is created. Pass the complete set the '
            . 'file should carry from that list: a listed category left out is removed, and categories the file '
            . 'carries that are not listed are kept. A uid that is not listed refuses the whole call, and the '
            . 'refusal names the allowed categories. Writes sys_file_metadata through the TYPO3 DataHandler as the '
            . 'acting backend user, in the default language only, in the workspace the request was started in. '
            . "The file must carry a metadata record and lie in a permitted storage inside the acting user's file "
            . 'mounts.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The sys_file uid of the single file to tag.',
                    ],
                    self::FIELD => [
                        'type'        => 'array',
                        'items'       => ['type' => 'integer'],
                        'description' => 'The sys_category uids the file should carry from the listed categories. An empty list removes them all.',
                    ],
                ],
                'required' => ['uid', self::FIELD],
            ],
        );
    }

    public function refusalBeforeApproval(array $arguments, ToolExecutionContext $context): ?ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            // Unknown here; execute() refuses it with the neutral string.
            return null;
        }

        $chosen = $this->collectCategories($arguments);
        if (is_string($chosen)) {
            return ToolResult::error($chosen);
        }

        if ($this->vocabulary->rootUid() < 1) {
            return ToolResult::error('Refused: no category tree is configured for files, so no category can be set. An administrator names its root in the extension setting vision.categories.rootUid.');
        }

        $allowed = $this->vocabulary->forUser($user);
        if ($allowed === []) {
            return ToolResult::error('Refused: there is no category below the configured root that the acting user may assign.');
        }

        $outside = array_values(array_filter($chosen, static fn (int $uid): bool => !isset($allowed[$uid])));
        if ($outside !== []) {
            return ToolResult::error(sprintf(
                'Refused: %s %s not among the categories that may be set. Allowed, as uid: title: %s.',
                implode(', ', $outside),
                count($outside) === 1 ? 'is' : 'are',
                $this->listed($allowed),
            ));
        }

        return null;
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $refusal = $this->refusalBeforeApproval($arguments, $context);
        if ($refusal instanceof ToolResult) {
            return $refusal;
        }

        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $refusal = $this->refuseWithoutBackendEnvironment(self::METADATA_TABLE)
            ?? $this->refuseUnpinnedWorkspace($context, $user)
            ?? $this->refuseUnversionedTableInDraftWorkspace($context, self::METADATA_TABLE);
        if ($refusal instanceof ToolResult) {
            return $refusal;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        [$file, $metadataUid, , $after] = $plan;
        $fileUid                        = self::toInt($file['uid'] ?? 0);

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([self::METADATA_TABLE => [$metadataUid => [self::FIELD => implode(',', $after)]]], [], $user);
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        // Read back before reporting success: the DataHandler drops a field the
        // user holds no grant for without a word.
        $stored = $this->storedCategories($fileUid, max(0, $user->workspace));
        if ($stored === null || !$this->sameSet($stored, $after)) {
            return ToolResult::error(sprintf(
                'The categories did not take on file [%d]. The acting backend user is most likely missing the '
                . 'field-level ("exclude field") grant for %s:%s.',
                $fileUid,
                self::METADATA_TABLE,
                self::FIELD,
            ));
        }

        return ToolResult::text(sprintf(
            'Set the categories of file [%d] "%s" to %s.%s',
            $fileUid,
            $this->excerpt(self::toStr($file['name'] ?? '')),
            $after === [] ? 'none' : $this->quoted(implode(', ', $this->titlesOf($after))),
            $this->draftWorkspaceNote($user),
        ))->withWriteTarget(new RecordReference(self::METADATA_TABLE, $metadataUid), WriteKind::UPDATED, WriteCompleteness::COMPLETE);
    }

    /**
     * The before/after this call would produce (ADR-136), authorised exactly
     * like {@see self::execute()}.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $refusal = $this->refusalBeforeApproval($arguments, $context);
        if ($refusal instanceof ToolResult) {
            return [$refusal->content];
        }

        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return [self::NOT_PERMITTED];
        }

        $unpinned = $this->refuseUnpinnedWorkspace($context, $user);
        if ($unpinned instanceof ToolResult) {
            return [$unpinned->content];
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return [$plan];
        }

        [$file, $metadataUid, $before, $after] = $plan;
        [$t, $q]                               = $this->translator->boundTo($user, $this->excerpt(...));

        $none   = $t(ApprovalPreviewLabel::ValueNone);
        [$line] = $this->fieldChange(
            $t,
            $q,
            $t(ApprovalPreviewLabel::FileFieldCategories),
            self::FIELD,
            $before === [] ? $none : implode(', ', $this->titlesOf($before)),
            $after === [] ? $none : implode(', ', $this->titlesOf($after)),
        );

        return [
            $t(ApprovalPreviewLabel::SetFileCategoriesHeading),
            $t(ApprovalPreviewLabel::ObjectFile, $q(self::toStr($file['name'] ?? ''))),
            ...array_filter([$this->workspaceLine($t, $q, $context, $user)], is_string(...)),
            $t(ApprovalPreviewLabel::LanguageDefault),
            $line,
            $this->translator->technical($user, [
                $t(ApprovalPreviewLabel::TechnicalFile, self::toInt($file['uid'] ?? 0)),
                $t(ApprovalPreviewLabel::TechnicalRecord, self::METADATA_TABLE, $metadataUid),
                $t(ApprovalPreviewLabel::TechnicalFields, self::FIELD),
                // The uids bind the approval to the records, not to their
                // titles, which two categories may share.
                $t(ApprovalPreviewLabel::TechnicalCategoryUids, $this->uidList($before), $this->uidList($after)),
            ]),
        ];
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // The storage gate and the category mounts are the acting user's own.
        return false;
    }

    public function getGroup(): string
    {
        return 'editing';
    }

    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return PendingWriteTarget::fromArguments(self::FILE_TABLE, $arguments['uid'] ?? null, [self::FIELD]);
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * A vision action (ADR-235): the model sees the image and picks from the
     * list the prompt names. Offered only while a root is configured, because
     * the field is off without one.
     */
    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.set_file_categories.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.set_file_categories.description',
            'nrllm-editor-action-file-categories',
            [self::FILE_TABLE],
            [self::FIELD],
        );
    }

    /**
     * Whether the person looking at the card may see this file's categories:
     * the resolution the write uses, run against the viewer (ADR-136).
     *
     * @param array<string, mixed> $arguments
     */
    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        $uid = self::toInt($arguments['uid'] ?? 0);

        return $uid >= 1 && $this->resolveFalAsset($viewer, $uid) !== null;
    }

    /**
     * The file, its metadata uid, and its categories before and after, or a
     * refusal. `$after` keeps every current category the list does not hold,
     * then the chosen ones.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{array<string, mixed>, int, list<int>, list<int>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return 'Refused: "uid" must be the positive sys_file uid of exactly one file.';
        }

        $chosen = $this->collectCategories($arguments);
        if (is_string($chosen)) {
            return $chosen;
        }

        $target = $this->resolveFalAsset($user, $uid);
        $before = $target === null ? null : $this->storedCategories($uid, max(0, $user->workspace));
        if ($target === null || $before === null) {
            return self::NOT_PERMITTED;
        }

        $allowed = $this->vocabulary->forUser($user);
        $kept    = array_values(array_filter($before, static fn (int $category): bool => !isset($allowed[$category])));

        return [$target[0], self::toInt($target[1]['uid'] ?? 0), $before, array_values(array_unique([...$kept, ...$chosen]))];
    }

    /**
     * The chosen uids, or a refusal. An argument the tool does not know refuses
     * the whole call.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<int>|string
     */
    private function collectCategories(array $arguments): array|string
    {
        foreach (array_keys($arguments) as $key) {
            if ($key !== 'uid' && $key !== self::FIELD) {
                return sprintf(
                    'Refused: "%s" is not an argument of this tool. It sets only "%s" on one file.',
                    preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '',
                    self::FIELD,
                );
            }
        }

        $raw = $arguments[self::FIELD] ?? null;
        if (!is_array($raw)) {
            return sprintf('Refused: "%s" is required and must be a list of sys_category uids (an empty list removes them).', self::FIELD);
        }

        $uids = [];
        foreach ($raw as $value) {
            $uid = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
            if ($uid < 1) {
                return sprintf('Refused: every entry of "%s" must be a positive sys_category uid.', self::FIELD);
            }

            $uids[$uid] = $uid;
        }

        return array_values($uids);
    }

    /**
     * The categories of a file's default-language metadata record as
     * `$workspace` shows it, in their order on the record, or null when the
     * file carries no metadata record there. In a draft workspace a version
     * holds relations of its own, so the version's uid is asked.
     *
     * @return list<int>|null
     */
    private function storedCategories(int $fileUid, int $workspace): ?array
    {
        $metadata = $this->fetchFalMetadata($fileUid, $workspace, 'pid');
        if ($metadata === null) {
            return null;
        }

        $recordUid    = self::toInt($metadata['_ORIG_uid'] ?? $metadata['uid'] ?? 0);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::RELATION_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid_local')
            ->from(self::RELATION_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::METADATA_TABLE)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter(self::FIELD)),
            )
            ->orderBy('sorting_foreign')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_unique(array_map(self::toInt(...), $rows)));
    }

    /**
     * The title of each uid, in order; a uid no category carries keeps its
     * number.
     *
     * @param list<int> $uids
     *
     * @return list<string>
     */
    private function titlesOf(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::CATEGORY_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('uid', 'title')
            ->from(self::CATEGORY_TABLE)
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $titles = [];
        foreach ($rows as $row) {
            $titles[self::toInt($row['uid'] ?? 0)] = trim(self::toStr($row['title'] ?? ''));
        }

        return array_map(
            static fn (int $uid): string => ($titles[$uid] ?? '') !== '' ? $titles[$uid] : '#' . $uid,
            $uids,
        );
    }

    /**
     * @param array<int, string> $allowed
     */
    private function listed(array $allowed): string
    {
        $entries = [];
        foreach ($allowed as $uid => $title) {
            $entries[] = sprintf('%d: %s', $uid, $title);
        }

        return implode('; ', $entries);
    }

    /**
     * @param list<int> $uids
     */
    private function uidList(array $uids): string
    {
        return $uids === [] ? '-' : implode(',', $uids);
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     */
    private function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
