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
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Clear the hidden flag of ONE page or content element, through the
 * DataHandler, as the acting backend user (ADR-198).
 *
 * The counterpart of the draft writers: they create hidden records so a human
 * reads them first, and this is the act that makes one visible. It is the one
 * writer whose whole effect is a change of audience, so it does exactly one
 * thing — `hidden` from 1 to 0 on one row — and nothing else changes with it.
 * `starttime`, `endtime` and `fe_group` stay what they are; the approval card
 * names them where they still restrict the record, so "published" never reads
 * as "visible to everyone now" when it is not.
 *
 * What it refuses, and why:
 *
 * - **Any table but `pages` and `tt_content`** (ADR-198).
 * - **A record the acting user may not edit.** `PAGE_EDIT` on a page,
 *   `CONTENT_EDIT` on the page of a content element, and the record-level
 *   rights ({@see ActsOnAnExistingRecordTrait::mayEditRecord()}) — against the
 *   EXPLICIT acting user (ADR-083). A missing record and a forbidden one
 *   return the same neutral string.
 * - **A user without the field-level grant for the hidden column.** It is an
 *   `exclude` column on both tables, and the DataHandler would drop it in
 *   silence; asked before the write, and read back after it.
 * - **A draft workspace and a process without a backend environment**, through
 *   {@see WritesThroughDataHandlerTrait}.
 *
 * A record that is not hidden is not written: the call reports that and names
 * no write target, so a repeated call converges without a second history row.
 */
final readonly class PublishRecordTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The plan, the viewer gate, the unknown-argument refusal and the row lookup.
    use PlansOneEditorialWriteTrait;
    // Table, language, translations and record-level rights of an existing row.
    use ActsOnAnExistingRecordTrait;

    /**
     * One string for "no such record", "deleted" and "you may not edit it",
     * so a refusal never confirms that a uid exists.
     */
    private const NOT_PERMITTED = 'Record not found or not permitted.';

    private const PAGES_TABLE = 'pages';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'publish_record',
            'Publish ONE hidden page or content element by clearing its hidden flag — the step after a human has '
            . 'reviewed a draft. Nothing else changes: start and stop times and access groups stay as they are. '
            . 'Writes through the TYPO3 DataHandler as the acting backend user, in the live workspace only.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'enum'        => self::EXISTING_RECORD_TABLES,
                        'description' => 'pages for a page, tt_content for a content element.',
                    ],
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the single record to publish.',
                    ],
                ],
                'required' => ['table', 'uid'],
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

        if (!$plan['hidden']) {
            // Nothing to write, and nothing is named as written: a repeated
            // call converges without a history row of its own.
            return ToolResult::text(sprintf(
                '%s [%d] "%s" is not hidden; nothing was written.',
                $plan['table'],
                $plan['uid'],
                $this->excerpt($plan['label']),
            ));
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$plan['table'] => [$plan['uid'] => [$plan['hiddenColumn'] => 0]]], [], $user);
        $dataHandler->process_datamap();

        // Read back whatever the error log says: an empty one is no proof,
        // and a non-empty one does not mean the flag stayed. "Published" about
        // a record that is still hidden would send a human to look for a page
        // nobody can see; "refused" about one that is live would hide that it is.
        $complaints = $dataHandler->errorLog === [] ? '' : ' TYPO3 reported: ' . $this->summariseErrors($dataHandler->errorLog);
        $stored     = $this->fetchRowByUid($plan['table'], $plan['uid'], 'uid', $plan['hiddenColumn']);
        if ($stored === null || self::toInt($stored[$plan['hiddenColumn']] ?? 1) !== 0) {
            return ToolResult::error(sprintf(
                'The hidden flag of %s [%d] did not clear.%s The acting backend user is most likely missing the '
                . 'field-level ("exclude field") grant for %s:%s, or a hook of the installation kept it set.',
                $plan['table'],
                $plan['uid'],
                $complaints,
                $plan['table'],
                $plan['hiddenColumn'],
            ));
        }

        return ToolResult::text(sprintf(
            'Cleared the hidden flag of %s [%d] "%s" on page [%d].%s%s',
            $plan['table'],
            $plan['uid'],
            $this->excerpt($plan['label']),
            $plan['page'],
            $plan['restrictions'] === [] ? '' : ' What may still restrict it: ' . implode('; ', $plan['restrictions']) . '.',
            $complaints,
        ))->withWriteTarget(new RecordReference($plan['table'], $plan['uid']), WriteKind::UPDATED);
    }

    /**
     * The one change this call would make, and what still restricts the record
     * afterwards (ADR-136).
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

        $lines = [
            sprintf(
                '%s [%d] "%s" on page [%d] "%s", language %d:',
                $plan['table'],
                $plan['uid'],
                $this->excerpt($plan['label']),
                $plan['page'],
                $this->excerpt($plan['pageTitle']),
                $plan['language'],
            ),
            $plan['hidden']
                ? sprintf('%s: 1 → 0 (hidden → published)', $plan['hiddenColumn'])
                : sprintf('%s: already 0 — nothing to write', $plan['hiddenColumn']),
        ];

        foreach ($plan['restrictions'] as $restriction) {
            $lines[] = 'may still restrict it: ' . $restriction;
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
        // Usable by a non-admin: the page permission and the record-level
        // rights are the acting user's own, checked here and enforced a second
        // time by the DataHandler.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // Setting one flag to 0 converges: a repeat finds it at 0 and writes
        // nothing.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    /**
     * Everything the write needs, resolved and authorised — or the refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{table:'pages'|'tt_content', uid:int, label:string, page:int, pageTitle:string, language:int, hiddenColumn:non-empty-string, hidden:bool, restrictions:list<string>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments($arguments, ['table', 'uid'], 'clears the hidden flag of one record');
        if ($unknown !== null) {
            return $unknown;
        }

        $table = $this->existingRecordTable($arguments);
        if ($table === null) {
            return 'Refused: "table" must be "pages" or "tt_content".';
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return 'Refused: "uid" must be the positive uid of exactly one record.';
        }

        $row  = $this->fetchRowByUid($table, $uid);
        $page = $row === null ? null : ($table === self::PAGES_TABLE ? $row : $this->fetchRowByUid(self::PAGES_TABLE, self::toInt($row['pid'] ?? 0)));
        if ($row === null || $page === null
            || !$user->doesUserHaveAccess($page, $table === self::PAGES_TABLE ? Permission::PAGE_EDIT : Permission::CONTENT_EDIT)
            || !$this->mayEditRecord($table, $row, $user)
        ) {
            return self::NOT_PERMITTED;
        }

        $hiddenColumn = $this->disabledColumnOf($table);
        if ($hiddenColumn === null) {
            return sprintf('Refused: %s declares no hidden column in this installation, so there is nothing to clear.', $table);
        }

        if ($this->columnsTheUserMayNotSet($user, $table, [$hiddenColumn]) !== []) {
            return sprintf(
                'Refused: the acting backend user holds no field-level ("exclude field") grant for %s:%s, so the '
                . 'DataHandler would leave the record hidden. Nothing was written.',
                $table,
                $hiddenColumn,
            );
        }

        return [
            'table'        => $table,
            'uid'          => $uid,
            'label'        => $this->labelOf($table, $row),
            'page'         => self::toInt($page['uid'] ?? 0),
            'pageTitle'    => self::toStr($page['title'] ?? ''),
            'language'     => $this->languageOf($table, $row),
            'hiddenColumn' => $hiddenColumn,
            'hidden'       => (bool)($row[$hiddenColumn] ?? false),
            'restrictions' => $this->restrictionsOf($table, $row),
        ];
    }

    /**
     * What may keep the record from visitors even with the hidden flag
     * cleared: a start time or a stop time that is set, an access group, and
     * for a translation a default-language record that is hidden itself. Each
     * as one English line — the card is compared byte for byte on resume
     * (ADR-184), so a date is written in UTC, and whether a time lies ahead
     * or behind is left to the reader: that answer changes with the clock and
     * would change the card between the pause and the resume.
     *
     * @param non-empty-string     $table
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function restrictionsOf(string $table, array $row): array
    {
        $enable = $this->ctrlOf($table)['enablecolumns'] ?? null;
        $enable = is_array($enable) ? $enable : [];

        $restrictions = [];
        foreach (['starttime' => 'start time', 'endtime' => 'stop time'] as $key => $label) {
            $column = $enable[$key] ?? null;
            $moment = is_string($column) ? self::toInt($row[$column] ?? 0) : 0;
            if ($moment > 0) {
                $restrictions[] = sprintf('%s %s', $label, gmdate('Y-m-d\TH:i:s\Z', $moment));
            }
        }

        $group = $enable['fe_group'] ?? null;
        if (is_string($group) && !in_array(self::toStr($row[$group] ?? ''), ['', '0'], true)) {
            $restrictions[] = sprintf('frontend user groups %s', self::toStr($row[$group]));
        }

        $parentUid = $this->translationParentOf($table, $row);
        $hidden    = $this->disabledColumnOf($table);
        if ($parentUid > 0 && $hidden !== null) {
            $parent = $this->fetchRowByUid($table, $parentUid, 'uid', $hidden);
            if ($parent !== null && (bool)($parent[$hidden] ?? false)) {
                $restrictions[] = sprintf('its default-language record [%d] is hidden', $parentUid);
            }
        }

        return $restrictions;
    }
}
