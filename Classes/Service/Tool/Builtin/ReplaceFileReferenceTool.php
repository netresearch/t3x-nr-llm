<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
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
 * - **A draft workspace and a process without a backend environment**, through
 *   {@see WritesThroughDataHandlerTrait}.
 *
 * A reference, an element and a file that do not exist, and those the user may
 * not touch, all return one neutral string.
 */
final readonly class ReplaceFileReferenceTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The plan, the viewer gate, the unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;
    // Language and record-level rights of the element.
    use ActsOnAnExistingRecordTrait;
    use FetchesSysFileRowTrait;

    /** The neutral refusal, shared in shape with the other FAL writers. */
    private const NOT_PERMITTED = 'File reference, content element or file not found, or not permitted.';

    private const CONTENT_TABLE = 'tt_content';

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
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'replace_file_reference',
            'Change ONE existing file reference (sys_file_reference) on a content element: "replace" points it at '
            . 'another EXISTING file at the same position, "remove" deletes the reference. The file itself is never '
            . "changed, moved or deleted. A replacement does not carry over the old reference's title, alternative "
            . 'text, description or crop — they described the old file; give new texts to set them. Writes through '
            . 'the TYPO3 DataHandler as the acting backend user, in the live workspace only. To add a file, use '
            . 'attach_file_to_content_element.',
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

        return $plan['file'] === null ? $this->remove($plan, $user) : $this->replace($plan, $user);
    }

    /**
     * The before/after this call would produce (ADR-136).
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

        $count = count($plan['references']);
        $lines = [
            sprintf(
                'tt_content [%d] "%s" on page [%d], field %s, reference [%d] (%d of %d):',
                $plan['element'],
                $this->excerpt($plan['header']),
                $plan['page'],
                $plan['field'],
                $plan['reference'],
                $plan['position'] + 1,
                $count,
            ),
        ];

        if ($plan['file'] === null) {
            $lines[] = sprintf('remove: file [%d] "%s" — the file itself stays', $plan['oldFile'], $this->excerpt($plan['oldFileName']));
            $lines[] = sprintf('references in %s: %d → %d', $plan['field'], $count, $count - 1);
            if ($plan['translated'] !== []) {
                $lines[] = $this->translatedLine($plan['translated'], 'which core deletes with it');
            }

            return $lines;
        }

        $lines[] = sprintf(
            'file: [%d] "%s" → [%d] "%s"',
            $plan['oldFile'],
            $this->excerpt($plan['oldFileName']),
            $plan['file'],
            $this->excerpt($plan['fileName']),
        );
        foreach (self::TEXT_FIELDS as $name) {
            $lines[] = array_key_exists($name, $plan['texts'])
                ? sprintf('%s: %s', $name, $this->quoted($plan['texts'][$name]))
                : sprintf("%s: the file's own (not carried over from the old reference)", $name);
        }

        if ($plan['translated'] !== []) {
            $lines[] = $this->translatedLine(
                $plan['translated'],
                'which core deletes with the old reference; the translations get no reference to the new file',
            );
        }

        return $lines;
    }

    /**
     * The card line for the translated references core deletes along: where
     * they sit, and what happens to the translated elements afterwards.
     *
     * @param non-empty-list<array{reference:int, element:int, language:int}> $translated
     */
    private function translatedLine(array $translated, string $whatCoreDoes): string
    {
        $elements = array_values(array_unique(array_filter(
            array_map(static fn(array $t): int => $t['element'], $translated),
            static fn(int $element): bool => $element > 0,
        )));
        $orphans = count(array_filter($translated, static fn(array $t): bool => $t['element'] === 0));

        $where = [];
        if ($elements !== []) {
            $where[] = 'on translated element(s) ' . implode(', ', array_map(static fn(int $element): string => '[' . $element . ']', $elements));
        }

        if ($orphans > 0) {
            $where[] = sprintf('(%d of them on an element that is gone)', $orphans);
        }

        return sprintf(
            'with %d translated reference(s) %s %s, %s%s',
            count($translated),
            implode(', ', array_map(static fn(array $t): string => '[' . $t['reference'] . ']', $translated)),
            implode(' ', $where),
            $whatCoreDoes,
            $elements === [] ? '' : "; each translated element's reference count is then updated",
        );
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
     * @return array{reference:int, element:int, header:string, page:int, field:string, language:int, references:list<int>, position:int, oldFile:int, oldFileName:string, file:int|null, fileName:string, texts:array<string, string>, translated:list<array{reference:int, element:int, language:int}>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['reference', 'action', 'file', ...self::TEXT_FIELDS],
            'replaces the file of one file reference on a content element, or removes the reference',
        );
        if ($unknown !== null) {
            return $unknown;
        }

        $referenceUid = self::toInt($arguments['reference'] ?? 0);
        if ($referenceUid < 1) {
            return 'Refused: "reference" must be the positive sys_file_reference uid of exactly one reference.';
        }

        $action = $arguments['action'] ?? null;
        if ($action !== 'replace' && $action !== 'remove') {
            return 'Refused: "action" must be "replace" or "remove".';
        }

        $fileUid = null;
        $texts   = [];
        if ($action === 'replace') {
            $fileUid = self::toInt($arguments['file'] ?? 0);
            if ($fileUid < 1) {
                return 'Refused: "replace" needs "file", the positive sys_file uid of exactly one existing file.';
            }

            foreach (self::TEXT_FIELDS as $name) {
                if (!array_key_exists($name, $arguments)) {
                    continue;
                }

                if (!is_string($arguments[$name]) && !is_numeric($arguments[$name])) {
                    return sprintf('Refused: the value for "%s" must be a string.', $name);
                }

                $text = trim(self::toStr($arguments[$name]));
                if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
                    return sprintf('Refused: "%s" is longer than %d characters.', $name, self::MAX_TEXT_LENGTH);
                }

                $texts[$name] = $text;
            }
        } else {
            foreach (['file', ...self::TEXT_FIELDS] as $name) {
                if (array_key_exists($name, $arguments)) {
                    return sprintf('Refused: "%s" belongs to "replace"; "remove" takes only "reference".', $name);
                }
            }
        }

        $reference = $this->liveReference($referenceUid);
        $field     = self::toStr($reference['fieldname'] ?? '');
        if ($reference === null
            || self::toStr($reference['tablenames'] ?? '') !== self::CONTENT_TABLE
            || !in_array($field, self::WRITABLE_FIELDS, true)
        ) {
            return self::NOT_PERMITTED;
        }

        $element = $this->fetchRowByUid(self::CONTENT_TABLE, self::toInt($reference['uid_foreign'] ?? 0));
        $page    = $element === null ? null : $this->fetchRowByUid(self::PAGES_TABLE, self::toInt($element['pid'] ?? 0));
        if ($element === null || $page === null
            || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)
            || !$this->mayEditRecord(self::CONTENT_TABLE, $element, $user)
            || (!$user->isAdmin() && !$user->check('tables_modify', self::REFERENCE_TABLE))
        ) {
            return self::NOT_PERMITTED;
        }

        $oldFile = $this->fetchFile(self::toInt($reference['uid_local'] ?? 0));

        $newFile = null;
        if ($fileUid !== null) {
            $newFile = $this->fetchFile($fileUid);
            if ($newFile === null
                || !$this->storageGate->isFileAccessible($user, self::toInt($newFile['storage'] ?? 0), self::toStr($newFile['identifier'] ?? ''))
            ) {
                return self::NOT_PERMITTED;
            }

            if ($fileUid === self::toInt($reference['uid_local'] ?? 0)) {
                return sprintf('Refused: reference [%d] already points at file [%d]; there is nothing to replace.', $referenceUid, $fileUid);
            }

            $extension = strtolower(self::toStr($newFile['extension'] ?? ''));
            $allowed   = $this->allowedExtensions($field);
            if ($allowed !== null && !in_array($extension, $allowed, true)) {
                return sprintf(
                    'Refused: field "%s" does not accept a .%s file. It accepts: %s.',
                    $field,
                    $extension === '' ? '(none)' : $extension,
                    implode(', ', $allowed),
                );
            }
        }

        $ungranted = [
            ...array_map(
                static fn(string $column): string => self::CONTENT_TABLE . ':' . $column,
                $this->columnsTheUserMayNotSet($user, self::CONTENT_TABLE, [$field]),
            ),
            ...array_map(
                static fn(string $column): string => self::REFERENCE_TABLE . ':' . $column,
                $this->columnsTheUserMayNotSet($user, self::REFERENCE_TABLE, array_keys($texts)),
            ),
        ];
        if ($ungranted !== []) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s. Nothing was written.',
                implode(', ', $ungranted),
            );
        }

        // Core deletes the translated overlays of a default-language
        // reference together with it (`deleteL10nOverlayRecords()`), so they
        // are part of this act: the translated element each sits on must be
        // the acting user's to change (its language, lock, content type and
        // field grant; `tables_modify` for the references is asked above) —
        // refused up front, as the other ADR-198 writers refuse a translation
        // core carries along.
        $translated = [];
        foreach ($this->translatedReferencesOf($referenceUid, $field) as $overlay) {
            $translatedElement = $this->fetchRowByUid(self::CONTENT_TABLE, self::toInt($overlay['uid_foreign'] ?? 0));
            $overlayLanguage   = self::toInt($overlay['sys_language_uid'] ?? 0);
            if ($translatedElement === null) {
                // An orphan: its element is gone or not live. Core deletes it
                // with the reference all the same, and there is no element to
                // settle — only its language is the user's question.
                if (!$user->checkLanguageAccess($overlayLanguage)) {
                    return sprintf(
                        'Refused: reference [%d] has a translated reference in language %d, which the acting backend user '
                        . 'may not edit, and core deletes it with this one. Nothing was written.',
                        $referenceUid,
                        $overlayLanguage,
                    );
                }

                $translated[] = ['reference' => self::toInt($overlay['uid'] ?? 0), 'element' => 0, 'language' => $overlayLanguage];

                continue;
            }

            $translatedPage = $this->fetchRowByUid(self::PAGES_TABLE, self::toInt($translatedElement['pid'] ?? 0));
            if ($translatedPage === null
                || !$user->doesUserHaveAccess($translatedPage, Permission::CONTENT_EDIT)
                || !$this->mayEditRecord(self::CONTENT_TABLE, $translatedElement, $user)
                || $this->columnsTheUserMayNotSet($user, self::CONTENT_TABLE, [$field]) !== []
            ) {
                return sprintf(
                    'Refused: reference [%d] has a translated reference in language %d which the acting backend user may '
                    . 'not change, and core deletes it with this one. Nothing was written.',
                    $referenceUid,
                    self::toInt($overlay['sys_language_uid'] ?? 0),
                );
            }

            $translated[] = [
                'reference' => self::toInt($overlay['uid'] ?? 0),
                'element'   => self::toInt($translatedElement['uid'] ?? 0),
                'language'  => self::toInt($overlay['sys_language_uid'] ?? 0),
            ];
        }

        $elementUid = self::toInt($element['uid'] ?? 0);
        $references = $this->liveReferenceUids($elementUid, $field);
        $position   = array_search($referenceUid, $references, true);

        return [
            'reference'   => $referenceUid,
            'element'     => $elementUid,
            'header'      => self::toStr($element['header'] ?? ''),
            'page'        => self::toInt($page['uid'] ?? 0),
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
        ];
    }

    /**
     * A new reference at the old one's position, then the old one deleted —
     * one DataHandler run, the datamap first and read back before the
     * cmdmap, as {@see SetPageSocialImageTool} does it.
     *
     * @param array{reference:int, element:int, header:string, page:int, field:string, language:int, references:list<int>, position:int, oldFile:int, oldFileName:string, file:int|null, fileName:string, texts:array<string, string>, translated:list<array{reference:int, element:int, language:int}>} $plan
     */
    private function replace(array $plan, BackendUserAuthentication $user): ToolResult
    {
        $placeholder = StringUtility::getUniqueId('NEW');
        $list        = array_map(
            static fn(int $uid): string => $uid === $plan['reference'] ? $placeholder : (string)$uid,
            $plan['references'],
        );

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
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

        $newUid  = self::toInt($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult || $newUid < 1) {
            return ToolResult::error(
                ($refused->content ?? 'The new reference was not created, and the DataHandler reported no error.')
                . ' ' . $this->restore($newUid, $plan, $user),
            );
        }

        // Read back BEFORE the old reference is deleted: the new row must
        // point at the element, the field and the file, and sit where the old
        // one sat. Otherwise the element goes back to the list it had.
        $expected = array_map(static fn(int $uid): int => $uid === $plan['reference'] ? $newUid : $uid, $plan['references']);
        $mismatch = $this->mismatch($newUid, $plan, $expected, [$plan['reference']]);
        if ($mismatch !== null) {
            return ToolResult::error($mismatch . ' ' . $this->restore($newUid, $plan, $user));
        }

        $dataHandler->process_cmdmap();
        if ($this->liveReferenceUids($plan['element'], $plan['field']) !== $expected) {
            return ToolResult::error(sprintf(
                'Reference [%d] was created, but the old reference [%d] was not removed from "%s": the element now '
                . 'carries both, and one has to be removed by hand.%s',
                $newUid,
                $plan['reference'],
                $plan['field'],
                $dataHandler->errorLog === [] ? '' : ' TYPO3 reported: ' . $this->summariseErrors($dataHandler->errorLog),
            ));
        }

        return ToolResult::text(sprintf(
            'Replaced file [%d] "%s" with file [%d] "%s" in %s of tt_content [%d] "%s" (reference [%d] is now [%d]).%s',
            $plan['oldFile'],
            $this->excerpt($plan['oldFileName']),
            (int)$plan['file'],
            $this->excerpt($plan['fileName']),
            $plan['field'],
            $plan['element'],
            $this->excerpt($plan['header']),
            $plan['reference'],
            $newUid,
            $this->settleTranslations($plan, $user),
        ))->withWriteTarget(new RecordReference(self::REFERENCE_TABLE, $newUid), WriteKind::CREATED);
    }

    /**
     * The reference deleted and the element's field set to the references
     * that remain, as the page module removes one.
     *
     * @param array{reference:int, element:int, header:string, page:int, field:string, language:int, references:list<int>, position:int, oldFile:int, oldFileName:string, file:int|null, fileName:string, texts:array<string, string>, translated:list<array{reference:int, element:int, language:int}>} $plan
     */
    private function remove(array $plan, BackendUserAuthentication $user): ToolResult
    {
        $remaining = array_values(array_filter($plan['references'], static fn(int $uid): bool => $uid !== $plan['reference']));

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            [self::CONTENT_TABLE => [$plan['element'] => [$plan['field'] => implode(',', $remaining)]]],
            [self::REFERENCE_TABLE => [$plan['reference'] => ['delete' => 1]]],
            $user,
        );
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();

        // The datamap and the cmdmap have both run whatever either reported,
        // so the answer says what the element carries now.
        $refused  = $this->refuseOnDataHandlerErrors($dataHandler);
        $mismatch = $this->mismatch(0, $plan, $remaining, [$plan['reference']]);
        if ($refused instanceof ToolResult || $mismatch !== null) {
            return ToolResult::error(sprintf(
                '%s%s tt_content [%d] now carries reference(s) %s in "%s".',
                $refused instanceof ToolResult ? $refused->content . ' ' : '',
                $mismatch ?? '',
                $plan['element'],
                implode(', ', $this->liveReferenceUids($plan['element'], $plan['field'])) ?: '(none)',
                $plan['field'],
            ));
        }

        return ToolResult::text(sprintf(
            'Removed reference [%d] to file [%d] "%s" from %s of tt_content [%d] "%s"; %d reference(s) remain. The file '
            . 'itself is unchanged.%s',
            $plan['reference'],
            $plan['oldFile'],
            $this->excerpt($plan['oldFileName']),
            $plan['field'],
            $plan['element'],
            $this->excerpt($plan['header']),
            count($remaining),
            $this->settleTranslations($plan, $user),
        ))->withWriteTarget(new RecordReference(self::REFERENCE_TABLE, $plan['reference']), WriteKind::DELETED);
    }

    /**
     * What the write left different from the plan, or null.
     *
     * @param array{element:int, field:string, file:int|null} $plan
     * @param list<int>                                       $expected the live references the field must hold, in order
     * @param list<int>                                       $gone     references that must no longer be live
     */
    private function mismatch(int $newUid, array $plan, array $expected, array $gone): ?string
    {
        if ($newUid > 0) {
            $row = $this->liveReference($newUid);
            if ($row === null
                || self::toInt($row['uid_foreign'] ?? 0) !== $plan['element']
                || self::toStr($row['tablenames'] ?? '') !== self::CONTENT_TABLE
                || self::toStr($row['fieldname'] ?? '') !== $plan['field']
                || self::toInt($row['uid_local'] ?? 0) !== $plan['file']
            ) {
                return 'The new reference was not stored against the element, field and file asked for.';
            }

            // Only the new row: the old one is still live until the cmdmap runs.
            $live = array_values(array_diff($this->liveReferenceUids($plan['element'], $plan['field']), $gone));
        } else {
            $live = $this->liveReferenceUids($plan['element'], $plan['field']);
        }

        if ($live !== $expected) {
            return sprintf('The references in "%s" of tt_content [%d] are not in the order asked for afterwards.', $plan['field'], $plan['element']);
        }

        $element = $this->fetchRowByUid(self::CONTENT_TABLE, $plan['element']);
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
     * Take a failed replacement back — the new reference deleted, the
     * element's field set to the list it had — and say whether that worked.
     *
     * @param array{element:int, field:string, references:list<int>} $plan
     */
    private function restore(int $newUid, array $plan, BackendUserAuthentication $user): string
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(
            [self::CONTENT_TABLE => [$plan['element'] => [$plan['field'] => implode(',', $plan['references'])]]],
            $newUid > 0 ? [self::REFERENCE_TABLE => [$newUid => ['delete' => 1]]] : [],
            $user,
        );
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();

        $live = $this->liveReferenceUids($plan['element'], $plan['field']);

        return $live === $plan['references']
            ? 'The element was put back as it was.'
            : sprintf(
                'Putting the element back did not complete: it now carries reference(s) %s in "%s" — check it by hand.',
                implode(', ', $live) ?: '(none)',
                $plan['field'],
            );
    }

    /**
     * After core deleted the translated overlays: set each translated
     * element's field to the references it still carries, so its counter
     * matches its rows, and say what came of it — '' when there were none,
     * otherwise a sentence for the answer.
     *
     * @param array{field:string, translated:list<array{reference:int, element:int, language:int}>} $plan
     */
    private function settleTranslations(array $plan, BackendUserAuthentication $user): string
    {
        if ($plan['translated'] === []) {
            return '';
        }

        $elements = array_values(array_filter(
            array_unique(array_map(static fn(array $t): int => $t['element'], $plan['translated'])),
            static fn(int $element): bool => $element > 0,
        ));
        $datamap  = [];
        foreach ($elements as $elementUid) {
            $datamap[$elementUid] = [$plan['field'] => implode(',', $this->liveReferenceUids($elementUid, $plan['field']))];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::CONTENT_TABLE => $datamap], [], $user);
        $dataHandler->process_datamap();

        $problems = [];
        foreach ($plan['translated'] as $translation) {
            if ($this->liveReference($translation['reference']) !== null) {
                $problems[] = sprintf('translated reference [%d] is still there', $translation['reference']);
            }
        }

        foreach ($elements as $elementUid) {
            $row   = $this->fetchRowByUid(self::CONTENT_TABLE, $elementUid);
            $count = count($this->liveReferenceUids($elementUid, $plan['field']));
            if ($row === null || self::toInt($row[$plan['field']] ?? -1) !== $count) {
                $problems[] = sprintf('translated element [%d] does not count its %d reference(s)', $elementUid, $count);
            }
        }

        return $problems === []
            ? sprintf(' Its %d translated reference(s) were deleted with it.', count($plan['translated']))
            : sprintf(
                ' The translations are not settled: %s.%s',
                implode('; ', $problems),
                $dataHandler->errorLog === [] ? '' : ' TYPO3 reported: ' . $this->summariseErrors($dataHandler->errorLog),
            );
    }

    /**
     * The live translated overlays of a reference on one field.
     *
     * @return list<array<string, mixed>>
     */
    private function translatedReferencesOf(int $referenceUid, string $field): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
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

        return $rows;
    }

    /**
     * A live, undeleted reference row, or null.
     *
     * @return array<string, mixed>|null
     */
    private function liveReference(int $uid): ?array
    {
        $row = $this->fetchRowByUid(self::REFERENCE_TABLE, $uid);

        return $row !== null && self::toInt($row['t3ver_wsid'] ?? 0) === 0 ? $row : null;
    }

    /**
     * The live references on one field of an element, in their stored order.
     *
     * @return list<int>
     */
    private function liveReferenceUids(int $elementUid, string $field): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REFERENCE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('uid')
            ->from(self::REFERENCE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($elementUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::CONTENT_TABLE)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_map(static fn(array $row): int => self::toInt($row['uid'] ?? 0), $rows));
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
            static fn(string $part): string => strtolower(trim($part)),
            explode(',', $allowed),
        )));
    }
}
