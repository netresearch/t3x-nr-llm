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
use TYPO3\CMS\Core\Localization\LanguageService;
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
 * marker. It is NOT covered by the ADR-112 write fence — that arms only on the
 * queued path, and no shipped entry point reaches it (ADR-135).
 *
 * The pause carries a field-by-field before/after ({@see self::previewCall()},
 * ADR-136): the arguments alone are the NEW values, and an approver deciding
 * whether a change is right needs to see what it replaces.
 */
final readonly class UpdatePageMetadataTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;

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

    /** How many DataHandler complaints are echoed back, and how long each may be. */
    private const MAX_ERRORS = 5;

    private const MAX_ERROR_LENGTH = 200;

    /**
     * How much of a value the approval preview shows. A `text` column holds up
     * to {@see self::MAX_VALUE_LENGTH} characters, and a card that pastes two of
     * them per field is unreadable — the approver needs to see WHICH text is
     * being replaced, not the whole of both.
     */
    private const PREVIEW_EXCERPT_LENGTH = 120;

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
        $dataHandler->start(['pages' => [$uid => $values]], [], $user);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            return ToolResult::error(sprintf(
                'The update was refused by TYPO3: %s',
                $this->summariseErrors($dataHandler->errorLog),
            ));
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
        ));
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
     * The globals the DataHandler declares as its prerequisites and does not
     * establish itself; `start()` sets only its own `$BE_USER`, so a hook
     * running inside the write still reads the ambient one.
     *
     * @return list<string>
     */
    private function missingBackendEnvironment(): array
    {
        $missing = [];
        if ($this->pagesColumns() === null) {
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
        $columns = $this->pagesColumns();
        if ($columns === null) {
            return self::ALLOWED_FIELDS;
        }

        return array_values(array_filter(
            self::ALLOWED_FIELDS,
            static fn(string $field): bool => isset($columns[$field]),
        ));
    }

    /**
     * The `pages` column definitions from the live TCA, or null when no TCA is
     * loaded. Narrowed step by step because `$GLOBALS` is untyped.
     *
     * @return array<array-key, mixed>|null
     */
    private function pagesColumns(): ?array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !is_array($tca['pages'] ?? null)) {
            return null;
        }

        $columns = $tca['pages']['columns'] ?? null;

        return is_array($columns) ? $columns : null;
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
            $max  = $this->maxLengthFor($key);
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
     * The TCA's own bound for the field where it declares one, otherwise the
     * tool's bound for the unbounded `text` columns.
     */
    private function maxLengthFor(string $field): int
    {
        $column = $this->pagesColumns()[$field] ?? null;
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
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Only the deleted restriction: a hidden or timed-out page is still a
        // page an editor may fix the description of.
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select('*')
            ->from('pages')
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

    /**
     * A preview value as it appears on the card: quoted, or the explicit
     * `(empty)` marker — an empty pair of quotes reads like a rendering bug, and
     * "this field is currently empty" is information the approver needs.
     */
    private function quoted(string $value): string
    {
        return $value === '' ? '(empty)' : '"' . $this->excerpt($value) . '"';
    }

    /**
     * One line's worth of a value: whitespace collapsed (a `text` column carries
     * newlines, and a preview line must stay one line) and truncated.
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
     * ever shown to a caller that already passed the edit-permission check, so
     * they cannot disclose the existence of a page the caller may not see.
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
