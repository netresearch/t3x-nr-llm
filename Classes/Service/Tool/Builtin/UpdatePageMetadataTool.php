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

/**
 * Set descriptive metadata on ONE page, through the DataHandler, as the acting
 * backend user (ADR-135).
 *
 * The first writing tool of this extension. It is deliberately not a generic
 * record editor: the allow-list below is a fixed set of descriptive text fields,
 * so the blast radius of a model that was steered by injected prose is "a page
 * carries a wrong sentence", never "a page moved, disappeared, changed its URL
 * or became visible to the wrong audience".
 *
 * What it refuses, and why:
 *
 * - **A field outside the allow-list.** The call is refused whole rather than
 *   partially applied — a half-written record is harder to reason about than a
 *   refusal, and the model can retry with a corrected argument.
 * - **An empty value for a required field.** The DataHandler drops it as
 *   silently as a missing field grant, so the read-back below would blame the
 *   wrong cause. A required field cannot be emptied through this tool anyway.
 * - **More than one page.** Exactly one `uid` per call, so every write has one
 *   reviewable subject on the approval card.
 * - **Anything but the live workspace.** A draft write goes through the
 *   workspace publishing machinery, which has its own review semantics; the
 *   tool does not silently take part in them.
 * - **A missing backend environment.** The DataHandler declares `$GLOBALS['TCA']`
 *   and `$GLOBALS['LANG']` as prerequisites, and `start()` only sets its OWN
 *   `$BE_USER` — foreign hooks running inside the write still read
 *   `$GLOBALS['BE_USER']`. On a request-bound run these exist; in a process
 *   without a backend environment they do not. The tool refuses and names the
 *   reason instead of mutating globals it does not own (ADR-135).
 * - **A page the acting user may not edit.** Checked against the EXPLICIT acting
 *   user (ADR-083), never the ambient one. A page that does not exist and a page
 *   the user may not edit return the SAME neutral string, so the model cannot
 *   probe the page tree for existence.
 *
 * Success is verified rather than assumed: an empty `errorLog` is no proof the
 * values landed, because the DataHandler SKIPS a field the acting user holds no
 * `non_exclude_fields` grant for without logging anything. The row is re-read
 * and any field that did not take is reported as an error.
 *
 * Effect: {@see ToolEffect::IDEMPOTENT_WRITE} — setting a scalar field to a
 * given value converges on repeat. Because the effect is a write, every call is
 * suspended for human approval (ADR-134); the tool carries no separate approval
 * marker. The ADR-112 write fence DOES cover it: every executing segment claims
 * a lease (ADR-141), so the guard arms on the interactive path too, and it
 * refuses a side-effecting tool it cannot fence rather than running it unfenced.
 * ADR-135 recorded the opposite while that was still true; read ADR-141 for the
 * current guarantee.
 *
 * The pause carries a field-by-field before/after ({@see self::previewCall()},
 * ADR-136): the arguments alone are the NEW values, and an approver deciding
 * whether a change is right needs to see what it replaces.
 */
final readonly class UpdatePageMetadataTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface
{
    use SafeCastTrait;
    // The errands, not the decisions: the environment and workspace guards, the
    // bounded DataHandler complaints, the TCA narrowing and the preview
    // formatting. Everything this tool DECIDES stays below (ADR-135).
    use WritesThroughDataHandlerTrait;

    /**
     * One string for "no such page" and for "you may not edit it", so a refusal
     * never confirms that a uid exists.
     */
    private const NOT_PERMITTED = 'Page not found or not permitted.';

    /**
     * The fields a model may set.
     *
     * Every entry is a plain `input`/`text` column carrying descriptive prose and
     * nothing else: no relation, no routing, no visibility, no access control.
     * The SEO group exists only when EXT:seo is installed, which is why the list
     * is intersected with the live TCA in {@see self::editableFields()} rather
     * than trusted as written.
     *
     * Deliberately NOT here: `slug`, `shortcut`, `url`, `target` and
     * `canonical_link` (they decide where a URL points); `doktype`, `hidden`,
     * `nav_hide`, `starttime`, `endtime` and `fe_group` (publication and
     * audience); `perms_*`, `editlock`, `TSconfig` and `backend_layout*`
     * (permission and configuration surface); `no_index` / `no_follow` (a
     * "metadata" label over an action that can deindex a site); `author` and
     * `author_email` (a claim about a person, plus personal data); the image
     * fields and `media` (FAL relations are a different risk class than a scalar
     * set); `sys_language_uid`, `l10n_parent`, `l18n_cfg` (translation topology).
     *
     * @var list<string>
     */
    private const ALLOWED_FIELDS = [
        'title',
        'subtitle',
        'nav_title',
        'abstract',
        'description',
        'keywords',
        'seo_title',
        'og_title',
        'og_description',
        'twitter_title',
        'twitter_description',
    ];

    /**
     * Upper bound for a field the TCA does not bound itself (the `text` columns).
     * A model-chosen argument is untrusted input, and an unbounded one would let
     * a single call write an arbitrarily large blob.
     */
    private const MAX_VALUE_LENGTH = 2000;

    /** The one table this tool writes. */
    private const TABLE = 'pages';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        $properties = [
            'uid' => [
                'type'        => 'integer',
                'description' => 'The uid of the single page to update.',
            ],
        ];

        foreach ($this->editableFields() as $field) {
            $properties[$field] = [
                'type'        => 'string',
                'description' => sprintf('New value for the page field "%s". Omit to leave it unchanged.', $field),
            ];
        }

        return ToolSpec::function(
            'update_page_metadata',
            'Set descriptive metadata on ONE page (' . implode(', ', $this->editableFields()) . '). '
            . 'Writes through the TYPO3 DataHandler as the acting backend user, in the live workspace only. '
            . 'Any other page field is refused, and the whole call is refused rather than partially applied.',
            [
                'type'       => 'object',
                'properties' => $properties,
                'required'   => ['uid'],
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

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return ToolResult::error('Refused: "uid" must be the positive uid of exactly one page.');
        }

        $values = $this->collectValues($arguments);
        if (is_string($values)) {
            return ToolResult::error($values);
        }

        $page = $this->fetchPage($uid);
        if ($page === null) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        // Against the EXPLICIT acting user (ADR-083). BackendUtility::readPageAccess
        // would reach for $GLOBALS['BE_USER'] internally, which is exactly the
        // ambient read this runtime removed.
        if (!$user->doesUserHaveAccess($page, Permission::PAGE_EDIT)
            || !$user->checkLanguageAccess(self::toInt($page['sys_language_uid'] ?? 0))
        ) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([self::TABLE => [$uid => $values]], [], $user);
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        // Read back before reporting success. An empty errorLog is NOT proof the
        // values landed: the DataHandler SKIPS a field the acting user lacks the
        // `non_exclude_fields` grant for, silently and without logging. Reporting
        // a write that did not happen is the worst outcome for a tool whose whole
        // point is that a human approved a specific change.
        $notApplied = $this->fieldsThatDidNotTake($uid, $values);
        if ($notApplied !== []) {
            return ToolResult::error(sprintf(
                'The update did not take on page [%d] for: %s. The acting backend user is most likely '
                . 'missing the field-level ("exclude field") grant for them.',
                $uid,
                implode(', ', $notApplied),
            ));
        }

        return ToolResult::text(sprintf(
            'Updated page [%d]: %s.',
            $uid,
            implode(', ', array_keys($values)),
        ))->withWriteTarget(new RecordReference(self::TABLE, $uid));
    }

    /**
     * The field-by-field before/after this call would produce (ADR-136).
     *
     * Called by the loop when the run suspends for approval — before anything
     * ran, in the RUN's actor context — so the "before" is the row as it stood
     * at the pause. It is a snapshot and not a reservation: the ADR decides
     * explicitly that a human editing the page in between is not fenced off, and
     * that this tool writes absolute values, so a stale "before" changes what the
     * approver read, never what the write does.
     *
     * Authorised exactly like {@see self::execute()} and against the same
     * EXPLICIT acting user: a page the run's user may not edit yields the same
     * neutral string here, so a preview can never disclose a page the run itself
     * could not have touched.
     *
     * NOT checked here, deliberately: the live-workspace and backend-environment
     * refusals. Both describe the PROCESS that performs the write, and that is
     * the approver's request, not this one — asserting them now would show the
     * approver a verdict from the wrong process.
     *
     * The row is read through the same {@see self::fetchPage()} the write path
     * uses. It cannot be shared with the execution's read: the two happen in
     * different requests, minutes or days apart, which is precisely why the
     * "before" is a snapshot.
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
            return ['Refused: "uid" must be the positive uid of exactly one page.'];
        }

        $values = $this->collectValues($arguments);
        if (is_string($values)) {
            return [$values];
        }

        $page = $this->fetchPage($uid);
        if ($page === null
            || !$user->doesUserHaveAccess($page, Permission::PAGE_EDIT)
            || !$user->checkLanguageAccess(self::toInt($page['sys_language_uid'] ?? 0))
        ) {
            return [self::NOT_PERMITTED];
        }

        $lines = [sprintf('Page [%d] "%s" — %d field(s):', $uid, $this->excerpt(self::toStr($page['title'] ?? '')), count($values))];
        foreach ($values as $field => $new) {
            $old = self::toStr($page[$field] ?? '');
            $lines[] = $old === $new
                ? sprintf('%s: unchanged (%s)', $field, $this->quoted($new))
                : sprintf('%s: %s → %s', $field, $this->quoted($old), $this->quoted($new));
        }

        return $lines;
    }

    /**
     * The READ side of the preview (ADR-136): the same record check as
     * {@see self::previewCall()}, applied to the person looking at the approval
     * card instead of to the run's acting user.
     *
     * `PAGE_EDIT` and not `PAGE_SHOW` on purpose. The card exists to release a
     * write to this page, and the strict reading of the disclosure — an approver
     * whose remit is operations, not editing — is the one that decides here: you
     * see what the write replaces only where you could have written it yourself.
     *
     * A refusal is never a probe: nothing distinguishes "no such page" from "not
     * permitted", exactly as in `previewCall()`, and the card says the same thing
     * either way.
     *
     * @param array<string, mixed> $arguments
     */
    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return false;
        }

        $page = $this->fetchPage($uid);

        return $page !== null
            && $viewer->doesUserHaveAccess($page, Permission::PAGE_EDIT)
            && $viewer->checkLanguageAccess(self::toInt($page['sys_language_uid'] ?? 0));
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
        // Usable by a non-admin: execute() authorises the ACTING user's own page
        // permissions, and the DataHandler enforces them a second time, so an
        // editor writes exactly the pages and fields the backend already grants
        // them.
        return false;
    }

    public function getGroup(): string
    {
        // A group of its own, not `content`: a configuration that already grants
        // the read-only `content` group must not inherit write capability
        // because a new tool joined that group.
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // Setting named scalar fields to given values converges on repeat, so a
        // reaped-and-requeued run may safely repeat it.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * The human-facing declaration (ADR-152). The wire name and the spec
     * description above are written for the model; these are written for the
     * editor who is offered the action.
     */
    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.description',
            'nrllm-editor-action-page-metadata',
            [self::TABLE],
        );
    }

    /**
     * The allow-list intersected with the live TCA. `seo_title` and the
     * Open Graph / Twitter fields only exist when EXT:seo is installed; offering
     * them anyway would produce a call that can only fail.
     *
     * With no TCA loaded the full list is returned — the spec must not silently
     * shrink to nothing on a CLI process, and `execute()` refuses such a process
     * outright.
     *
     * @return list<string>
     */
    private function editableFields(): array
    {
        $columns = $this->tcaColumnsFor(self::TABLE);
        if ($columns === null) {
            return self::ALLOWED_FIELDS;
        }

        return array_values(array_filter(
            self::ALLOWED_FIELDS,
            static fn(string $field): bool => isset($columns[$field]),
        ));
    }

    /**
     * The validated field/value map, or a refusal message.
     *
     * The whole call is refused on the first unknown key: applying the known
     * half of a call the model got wrong leaves a record nobody asked for.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, string>|string
     */
    private function collectValues(array $arguments): array|string
    {
        $editable = $this->editableFields();

        $values = [];
        foreach ($arguments as $key => $value) {
            if ($key === 'uid') {
                continue;
            }

            if (!in_array($key, $editable, true)) {
                return sprintf(
                    'Refused: "%s" is not an editable page metadata field. Allowed: %s.',
                    // The key is echoed back so the model can correct itself; it
                    // is a name the model itself chose, not instance data.
                    preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '',
                    implode(', ', $editable),
                );
            }

            if (!is_string($value) && !is_numeric($value)) {
                return sprintf('Refused: the value for "%s" must be a string.', $key);
            }

            $text = trim(self::toStr($value));
            if ($text === '' && $this->isRequired($key)) {
                return sprintf(
                    'Refused: "%s" is required and cannot be emptied through this tool. '
                    . 'Send a non-empty value, or omit the field to leave it unchanged.',
                    $key,
                );
            }

            $max = $this->maxLengthFor($key);
            if (mb_strlen($text) > $max) {
                return sprintf('Refused: the value for "%s" exceeds %d characters.', $key, $max);
            }

            $values[$key] = $text;
        }

        if ($values === []) {
            return sprintf('Refused: name at least one field to set. Allowed: %s.', implode(', ', $editable));
        }

        return $values;
    }

    /**
     * Whether the live TCA declares the field required (`pages.title` is).
     *
     * An empty value for such a field is the SECOND thing the DataHandler drops
     * as silently as a missing `non_exclude_fields` grant:
     * `validateValueForRequired()` rejects it and `checkValueForInput()` /
     * `checkValueForText()` return an empty result without touching `errorLog`.
     * The read-back below would then blame the field-level grant — a cause an
     * admin cannot have — and, where the stored value happens to equal the
     * rejected one, would report success for a write that was refused. Refusing
     * the emptiness where the values are collected names the real reason and
     * costs nothing: a required field cannot be emptied through this tool
     * whatever the tool does.
     *
     * Only required fields are refused. Clearing an optional one (`abstract`,
     * `description`) is a write the DataHandler performs and the read-back
     * verifies, so it stays available.
     */
    private function isRequired(string $field): bool
    {
        $columns = $this->tcaColumnsFor(self::TABLE) ?? [];
        $column  = $columns[$field] ?? null;
        $config  = is_array($column) ? ($column['config'] ?? null) : null;

        // The DataHandler's own truthiness test, mirrored.
        return is_array($config) && (bool)($config['required'] ?? false);
    }

    /**
     * The TCA's own bound for the field where it declares one, otherwise the
     * tool's bound for the unbounded `text` columns.
     */
    private function maxLengthFor(string $field): int
    {
        $column = $this->tcaColumnsFor(self::TABLE)[$field] ?? null;
        $config = is_array($column) ? ($column['config'] ?? null) : null;
        $max    = is_array($config) ? ($config['max'] ?? null) : null;

        return is_int($max) && $max > 0 ? $max : self::MAX_VALUE_LENGTH;
    }

    /**
     * The page row, or null when no live, non-deleted page carries that uid.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPage(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        // Only the deleted restriction: a hidden or timed-out page is still a
        // page an editor may fix the description of.
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The allow-listed fields whose stored value is not the requested one after
     * the write — a field the DataHandler dropped without complaining.
     *
     * @param array<string, string> $values
     *
     * @return list<string>
     */
    private function fieldsThatDidNotTake(int $uid, array $values): array
    {
        $stored = $this->fetchPage($uid);
        if ($stored === null) {
            // The row vanished between the write and the read-back. Nothing was
            // verified, so nothing may be claimed.
            return array_keys($values);
        }

        $notApplied = [];
        foreach ($values as $field => $value) {
            if (self::toStr($stored[$field] ?? '') !== $value) {
                $notApplied[] = $field;
            }
        }

        return $notApplied;
    }
}
