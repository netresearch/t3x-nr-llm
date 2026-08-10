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
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Create ONE hidden content element on a page, through the DataHandler, as the
 * acting backend user (ADR-146).
 *
 * The fourth writing tool and the first that CREATES a record. Everything about
 * it is arranged so that the creation is the smallest possible commitment:
 *
 * - **The element is always hidden.** "Draft" is the whole proposition — a
 *   model-drafted element must be read by a human in the page module before any
 *   visitor sees it, and the approval that let the tool run approved a draft,
 *   not a publication. There is no argument to switch this off; publishing is a
 *   separate act with a separate audience, and this tool does not perform it.
 * - **The content type is an allow-list intersected with the live TCA.** Four
 *   types of prose element, and only those an installation actually declares.
 *   A model cannot reach `list` (a plugin), `html` (raw output), `shortcut` or
 *   any of the types whose payload is configuration rather than text.
 * - **The field set is fixed.** Header, body text, column, language, position.
 *   Not a generic record API — that question is #692's, and it stays open.
 *
 * The element is created in the language it is asked for, WITHOUT a translation
 * parent. In a connected-mode installation that is a free-mode element, which
 * is a legitimate but different thing from a translation of an existing one —
 * {@see CreateTranslationDraftTool} is the tool for that, and the description
 * says so on the wire so a model can choose.
 *
 * `bodytext` reaches the DataHandler and its RTE transformation exactly as any
 * editor's input does, so it is bounded in length here and nowhere else
 * sanitised: an editor may write the same markup by hand, and a tool that
 * filtered it would be enforcing a rule the CMS itself does not have.
 */
final readonly class CreateContentElementDraftTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;

    /**
     * One string for "no such page", "deleted" and "you may not edit content
     * there", so a refusal never confirms that a page uid exists. Shared with
     * {@see UpdatePageMetadataTool} and the page-reading tools.
     */
    private const NOT_PERMITTED = 'Page not found or not permitted.';

    private const TABLE = 'tt_content';

    private const PAGES_TABLE = 'pages';

    /**
     * The content types this tool may create, before intersecting with the live
     * TCA. All four carry editorial prose and nothing else — no plugin, no raw
     * HTML, no reference to another record.
     */
    private const ALLOWED_TYPES = ['header', 'text', 'textmedia', 'bullets'];

    /** Upper bound for the header. The core column is `varchar(255)`. */
    private const MAX_HEADER_LENGTH = 255;

    /**
     * Upper bound for the body. The column is `text` and the TCA declares no
     * `max`, so nothing else bounds a model-chosen argument — and a drafted
     * element is a paragraph or two, not a document.
     */
    private const MAX_BODY_LENGTH = 20000;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'create_content_element_draft',
            'Create ONE new content element (tt_content) on a page. The element is always created HIDDEN, so a '
            . 'human must review and unhide it in the page module before it is visible. Writes through the TYPO3 '
            . 'DataHandler as the acting backend user, in the live workspace. Only prose content types are '
            . 'available (' . implode(', ', self::ALLOWED_TYPES) . '). To translate an EXISTING element, use '
            . 'create_translation_draft instead — this tool creates a standalone element in the language given.',
            [
                'type'       => 'object',
                'properties' => [
                    'page' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the page to create the element on.',
                    ],
                    'type' => [
                        'type'        => 'string',
                        'description' => 'The content type (CType). One of: ' . implode(', ', self::ALLOWED_TYPES) . '.',
                    ],
                    'header' => [
                        'type'        => 'string',
                        'description' => 'The element headline. Required — it is how a human recognises the draft.',
                    ],
                    'bodytext' => [
                        'type'        => 'string',
                        'description' => 'The body text. Omit for a "header" element.',
                    ],
                    'column' => [
                        'type'        => 'integer',
                        'description' => 'The backend layout column (colPos) to create in. Defaults to 0.',
                    ],
                    'language' => [
                        'type'        => 'integer',
                        'description' => 'The sys_language_uid to create the element in. Defaults to 0 (default language).',
                    ],
                    'after_content_uid' => [
                        'type'        => 'integer',
                        'description' => 'Place the new element directly after this content element. It must be on '
                            . 'the same page. Omit to place it first in the column.',
                    ],
                ],
                'required' => ['page', 'type', 'header'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $refusal = $this->refuseWithoutBackendEnvironment(self::TABLE)
            ?? $this->refuseOutsideLiveWorkspace($user);
        if ($refusal instanceof ToolResult) {
            return $refusal;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        $placeholder = StringUtility::getUniqueId('NEW');
        $record      = [
            'pid'              => $plan['destination'],
            'CType'            => $plan['type'],
            'header'           => $plan['header'],
            'colPos'           => $plan['column'],
            'sys_language_uid' => $plan['language'],
            // Never negotiable; see the class docblock.
            $this->hiddenField() => 1,
        ];
        if ($plan['bodytext'] !== null) {
            $record['bodytext'] = $plan['bodytext'];
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => [$placeholder => $record]], [], $user);
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        $newUid = self::toInt($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
        if ($newUid < 1) {
            return ToolResult::error(
                'The element was not created. The acting backend user is most likely missing the grant to '
                . 'create records in ' . self::TABLE . ' on that page.',
            );
        }

        // Read back before reporting success. A uid is proof that a row exists,
        // not that it carries what was asked for: the DataHandler SKIPS a field
        // the acting user lacks the `non_exclude_fields` grant for, silently and
        // without logging. For `hidden` that is not a reporting problem but a
        // safety one — the element would be live on the page, which is the one
        // outcome this tool exists to prevent.
        $stored = $this->fetchElement($newUid);
        if ($stored === null
            || self::toInt($stored['pid'] ?? 0) !== $plan['page']
            || self::toStr($stored['CType'] ?? '') !== $plan['type']
            || self::toInt($stored[$this->hiddenField()] ?? 0) !== 1
        ) {
            // Take it back. A half-made element nobody approved is worse than
            // no element, and leaving it for a human to find is not a remedy
            // when the failure mode is "it is already visible".
            $removed = $this->discard($newUid, $user);

            return ToolResult::error(sprintf(
                'Content element [%d] was created but did not carry what was asked for (page, type or hidden '
                . 'state differ), so it %s. The acting backend user is most likely missing the field-level '
                . '("exclude field") grant for %s:%s.',
                $newUid,
                $removed ? 'was deleted again' : 'COULD NOT BE DELETED and may be visible — remove it by hand',
                self::TABLE,
                $this->hiddenField(),
            ));
        }

        return ToolResult::text(sprintf(
            'Created hidden %s element [%d] "%s" on page [%d], column %d, language %d. It is not visible until a '
            . 'human unhides it.',
            $plan['type'],
            $newUid,
            $this->excerpt($plan['header']),
            $plan['page'],
            $plan['column'],
            $plan['language'],
        ));
    }

    /**
     * What this call would create, as the approver reads it (ADR-136).
     *
     * There is no "before" — the record does not exist yet — so the card shows
     * the whole of what would come into being, which is exactly the set of
     * arguments the model chose. That is the point: an approver judging a
     * creation has nothing to compare against and must be able to read the
     * result in full.
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

        return [
            sprintf('New %s element on page [%d] "%s":', $plan['type'], $plan['page'], $this->excerpt($plan['pageTitle'])),
            sprintf('header: %s', $this->quoted($plan['header'])),
            $plan['bodytext'] === null
                ? 'bodytext: (none)'
                : sprintf('bodytext: %s', $this->quoted($plan['bodytext'])),
            sprintf(
                'position: column %d, language %d, %s',
                $plan['column'],
                $plan['language'],
                $plan['afterUid'] > 0
                    ? sprintf('directly after element [%d] "%s"', $plan['afterUid'], $this->excerpt($plan['afterHeader']))
                    : 'first in the column',
            ),
            'visibility: hidden — a human must unhide it before anyone sees it',
        ];
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the page is authorised against the acting
        // user's own content-edit permission, and the DataHandler enforces the
        // same permission a second time inside the creation.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // A creation with no caller-supplied key: running it twice leaves two
        // elements, not one. A reaped run that may already have created the
        // element must fail terminally rather than draft it again.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * Whether the person LOOKING at the approval card may see this draft.
     *
     * The same resolution the write uses, run against the VIEWER.
     *
     * @param array<string, mixed> $arguments the model-chosen arguments of the pending call
     */
    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        return !is_string($this->plan($arguments, $viewer));
    }

    /**
     * Everything the creation needs, resolved and authorised — or the refusal
     * message that stops it.
     *
     * One method for both {@see self::execute()} and {@see self::previewCall()}:
     * the approver must read the element the write will actually produce.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{page:int, pageTitle:string, type:string, header:string, bodytext:string|null, column:int, language:int, afterUid:int, afterHeader:string, destination:int}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $known = ['page', 'type', 'header', 'bodytext', 'column', 'language', 'after_content_uid'];
        foreach (array_keys($arguments) as $key) {
            if (!in_array($key, $known, true)) {
                return sprintf(
                    'Refused: "%s" is not an argument of this tool. It creates one content element; allowed: %s.',
                    // The key is echoed back so the model can correct itself; it
                    // is a name the model itself chose, not instance data.
                    preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '',
                    implode(', ', $known),
                );
            }
        }

        $pageUid = self::toInt($arguments['page'] ?? 0);
        if ($pageUid < 1) {
            return 'Refused: "page" must be the positive uid of exactly one page.';
        }

        $available = $this->availableTypes();
        $type      = trim(self::toStr($arguments['type'] ?? ''));
        if (!in_array($type, $available, true)) {
            return sprintf(
                'Refused: "%s" is not a content type this tool creates. Allowed here: %s.',
                preg_replace('/[^A-Za-z0-9_-]/', '', $type) ?? '',
                implode(', ', $available),
            );
        }

        $header = $this->text($arguments, 'header', self::MAX_HEADER_LENGTH);
        if (is_string($header)) {
            return $header;
        }

        if ($header[0] === '') {
            return 'Refused: "header" is required and must not be empty — it is how a human recognises the draft.';
        }

        $bodytext = null;
        if (array_key_exists('bodytext', $arguments)) {
            $body = $this->text($arguments, 'bodytext', self::MAX_BODY_LENGTH);
            if (is_string($body)) {
                return $body;
            }

            $bodytext = $body[0];
        }

        $language = self::toInt($arguments['language'] ?? 0);
        if ($language < 0) {
            return 'Refused: "language" must be zero or a positive sys_language_uid.';
        }

        if (!$user->checkLanguageAccess($language)) {
            return sprintf('Refused: you may not edit content in language %d.', $language);
        }

        $column = self::toInt($arguments['column'] ?? 0);
        if ($column < 0) {
            return 'Refused: "column" must be zero or a positive backend-layout column (colPos).';
        }

        $page = $this->fetchPage($pageUid);
        if ($page === null || !$user->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
            return self::NOT_PERMITTED;
        }

        $afterUid    = self::toInt($arguments['after_content_uid'] ?? 0);
        $afterHeader = '';
        if ($afterUid > 0) {
            $anchor = $this->fetchElement($afterUid);
            if ($anchor === null || self::toInt($anchor['pid'] ?? 0) !== $pageUid) {
                return sprintf(
                    'Refused: content element [%d] is not on page [%d], so it cannot anchor the new element.',
                    $afterUid,
                    $pageUid,
                );
            }

            $afterHeader = self::toStr($anchor['header'] ?? '');
        }

        return [
            'page'        => $pageUid,
            'pageTitle'   => self::toStr($page['title'] ?? ''),
            'type'        => $type,
            'header'      => $header[0],
            'bodytext'    => $bodytext,
            'column'      => $column,
            'language'    => $language,
            'afterUid'    => $afterUid,
            'afterHeader' => $afterHeader,
            // The DataHandler's own convention: a positive pid is the page, a
            // negative one is "directly after the record with that uid".
            'destination' => $afterUid > 0 ? -$afterUid : $pageUid,
        ];
    }

    /**
     * Delete an element this tool created but could not vouch for, reporting
     * whether it is gone.
     *
     * Through the DataHandler under the same acting user, so the row goes to
     * `deleted = 1` and the removal is in `sys_log` next to the creation rather
     * than appearing out of nowhere.
     */
    private function discard(int $uid, BackendUserAuthentication $user): bool
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [self::TABLE => [$uid => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        return $this->fetchElement($uid) === null;
    }

    /**
     * A validated text argument WRAPPED in a one-element list, or a refusal
     * message.
     *
     * Wrapped for the same reason {@see SetFileAlternativeTextTool::collectValue()}
     * wraps: both a valid value and a refusal are strings, and a headline that
     * happened to read like a refusal must not be treated as one.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list{string}|string
     */
    private function text(array $arguments, string $field, int $max): array|string
    {
        $raw = $arguments[$field] ?? null;
        if ($raw === null) {
            return sprintf('Refused: "%s" is required.', $field);
        }

        if (!is_string($raw) && !is_numeric($raw)) {
            return sprintf('Refused: the value for "%s" must be a string.', $field);
        }

        $text = trim(self::toStr($raw));
        if (mb_strlen($text) > $max) {
            return sprintf('Refused: the value for "%s" exceeds %d characters.', $field, $max);
        }

        return [$text];
    }

    /**
     * The allow-listed content types the live TCA actually declares, so a tool
     * cannot offer `textmedia` on an installation without fluid_styled_content.
     *
     * Falls back to the full allow-list when no TCA is loaded, matching
     * {@see UpdatePageMetadataTool::editableFields()} — the refusal for a
     * missing backend environment is the one that fires then.
     *
     * @return list<string>
     */
    private function availableTypes(): array
    {
        $column = $this->tcaColumnsFor(self::TABLE)['CType'] ?? null;
        $config = is_array($column) ? ($column['config'] ?? null) : null;
        $items  = is_array($config) ? ($config['items'] ?? null) : null;
        if (!is_array($items)) {
            return self::ALLOWED_TYPES;
        }

        $declared = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item['value'] ?? null)) {
                $declared[] = $item['value'];
            }
        }

        $available = array_values(array_filter(
            self::ALLOWED_TYPES,
            static fn(string $type): bool => in_array($type, $declared, true),
        ));

        return $available === [] ? self::ALLOWED_TYPES : $available;
    }

    /**
     * The name of the table's "hidden" column as the installation declares it.
     *
     * Read from the TCA rather than hardcoded because it is the field that
     * makes this a DRAFT tool: an installation that renamed it must not end up
     * with a visible element and a success message that claims otherwise.
     */
    private function hiddenField(): string
    {
        $tca  = $GLOBALS['TCA'] ?? null;
        $ctrl = is_array($tca) && is_array($tca[self::TABLE] ?? null) ? ($tca[self::TABLE]['ctrl'] ?? null) : null;
        $cols = is_array($ctrl) ? ($ctrl['enablecolumns'] ?? null) : null;
        $name = is_array($cols) ? ($cols['disabled'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : 'hidden';
    }

    /**
     * A content element row, or null when no undeleted element carries that uid.
     *
     * @return array<string, mixed>|null
     */
    private function fetchElement(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select('uid', 'pid', 'colPos', 'header', 'CType', 'sys_language_uid', $this->hiddenField())
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * A page row, or null when no undeleted page carries that uid.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPage(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::PAGES_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select('*')
            ->from(self::PAGES_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
