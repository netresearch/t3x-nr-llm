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
 * Localize ONE page or content element into a language, as a hidden draft,
 * through the DataHandler and as the acting backend user (ADR-146).
 *
 * The fifth writing tool. It performs core's own `localize` command rather than
 * copying fields between records: connected-mode translations, the translation
 * parent, the language field, inline children and every hook an installation
 * has on localisation are core's business, and a tool that reproduced them
 * would be a second implementation that drifts.
 *
 * What it adds on top of the command, and why:
 *
 * - **The result is hidden.** `localize` copies the source's visibility, so a
 *   translation of a live page would go live in the moment it is created. A
 *   machine-drafted translation must be read by a human first — that is the
 *   whole proposition of a draft, and it is not switchable.
 * - **An existing translation stops the call.** Core refuses a second
 *   localisation too, but only after the fact and in a log message; refusing it
 *   up front names the translation that is in the way, and the approver reads
 *   that on the card.
 * - **`overwrite` is explicit and destructive, and says so.** It deletes the
 *   existing translation through the DataHandler — recoverable (`deleted = 1`)
 *   and in the log — before localising afresh. A human approves that specific
 *   sentence on the approval card; there is no way to reach it by accident.
 * - **Only `pages` and `tt_content`.** The two tables an editor thinks in. A
 *   generic "localize any record" tool is the question #692 keeps open.
 *
 * Not re-implemented here, deliberately: whether the target language exists for
 * the record's site, and whether the source is a well-formed default-language
 * record. Core's {@see DataHandler::localize()} checks both, and its complaints
 * are surfaced through {@see WritesThroughDataHandlerTrait::refuseOnDataHandlerErrors()}.
 * The permission bar is NOT left to core: `localize()` asks only for
 * {@see Permission::PAGE_SHOW}, which is far too weak for a write.
 */
final readonly class CreateTranslationDraftTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface
{
    use SafeCastTrait;
    // The errands, not the decisions (ADR-135).
    use WritesThroughDataHandlerTrait;
    // The shape the three ADR-146 writers share.
    use PlansOneEditorialWriteTrait;

    /**
     * One string for "no such record", "deleted", "on a page you may not edit"
     * and "in a language you may not touch", so a refusal never confirms that a
     * uid exists.
     */
    private const NOT_PERMITTED = 'Record not found or not permitted.';

    private const PAGES_TABLE = 'pages';

    private const CONTENT_TABLE = 'tt_content';

    /** The two tables this tool translates; see the class docblock. */
    private const TABLES = [self::PAGES_TABLE, self::CONTENT_TABLE];

    private const DEFAULT_LANGUAGE = 0;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'create_translation_draft',
            "Translate ONE existing page or content element into another language, using TYPO3's own localize "
            . 'command. The new translation is always created HIDDEN, so a human must review and unhide it '
            . 'before it is visible; nothing is published. Writes through the DataHandler as the acting backend '
            . 'user, in the live workspace. The source must be a default-language record. If a translation in '
            . 'that language already exists the call is refused, unless "overwrite" is set — which DELETES the '
            . 'existing translation first.',
            [
                'type'       => 'object',
                'properties' => [
                    'table' => [
                        'type'        => 'string',
                        'description' => 'The table of the record to translate: ' . implode(' or ', self::TABLES) . '.',
                    ],
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the single default-language record to translate.',
                    ],
                    'language' => [
                        'type'        => 'integer',
                        'description' => 'The sys_language_uid to translate into. Must be configured for the '
                            . "record's site and must not be 0.",
                    ],
                    'overwrite' => [
                        'type'        => 'boolean',
                        'description' => 'Delete an existing translation in that language and create a fresh one. '
                            . 'Destructive: any work already done in that translation is discarded. Defaults to false.',
                    ],
                ],
                'required' => ['table', 'uid', 'language'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        // `pages` stands in for "a TCA is loaded at all", which is what the
        // check is for; running it before the plan means a bare worker process
        // is named rather than sent into a query it cannot make.
        $user = $this->writableActingUser($context, self::PAGES_TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        if ($plan['existingUid'] > 0) {
            $discarded = $this->discardExisting($plan['table'], $plan['existingUid'], $user);
            if ($discarded instanceof ToolResult) {
                return $discarded;
            }
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$plan['table'] => [$plan['uid'] => ['localize' => $plan['language']]]], $user);
        $dataHandler->process_cmdmap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        // `localize()` records the record it produced here, the same map `copy`
        // uses. Nothing else reports the new uid.
        $newUid = self::toInt($dataHandler->copyMappingArray[$plan['table']][$plan['uid']] ?? 0);
        if ($newUid < 1) {
            return ToolResult::error(sprintf(
                'No translation was created for %s [%d] in language %d.',
                $plan['table'],
                $plan['uid'],
                $plan['language'],
            ));
        }

        $hidden = $this->hide($plan['table'], $newUid, $user);
        if ($hidden instanceof ToolResult) {
            return $hidden;
        }

        // Read back before reporting success. The translation must be in the
        // language asked for, must point at the source, and must be hidden —
        // reporting a DRAFT that is in fact visible is the one failure this
        // tool must never produce.
        $stored = $this->fetchRecord($plan['table'], $newUid);
        if ($stored === null
            || self::toInt($stored[$this->languageField($plan['table'])] ?? -1) !== $plan['language']
            || self::toInt($stored[$this->parentField($plan['table'])] ?? 0) !== $plan['uid']
            || self::toInt($stored[$this->hiddenField($plan['table'])] ?? 0) !== 1
        ) {
            return ToolResult::error(sprintf(
                'Translation [%d] of %s [%d] was created but is not a hidden, connected translation in language '
                . '%d. Review it before using it.',
                $newUid,
                $plan['table'],
                $plan['uid'],
                $plan['language'],
            ));
        }

        return ToolResult::text(sprintf(
            'Created hidden translation [%d] of %s [%d] "%s" in language %d%s. It is not visible until a human '
            . 'unhides it.',
            $newUid,
            $plan['table'],
            $plan['uid'],
            $this->excerpt($plan['label']),
            $plan['language'],
            $plan['existingUid'] > 0 ? sprintf(', replacing translation [%d]', $plan['existingUid']) : '',
        ))->withWriteTarget(new RecordReference($plan['table'], $newUid));
    }

    /**
     * What this call would produce, as the approver reads it (ADR-136).
     *
     * The destructive case gets its own line rather than a footnote: an
     * approver who skims must not be able to miss that an existing translation
     * is about to be discarded.
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
            sprintf('Translate %s [%d] "%s" into language %d:', $plan['table'], $plan['uid'], $this->excerpt($plan['label']), $plan['language']),
        ];

        if ($plan['existingUid'] > 0) {
            $lines[] = sprintf(
                'DISCARDS the existing translation [%d] "%s" — its content is deleted and replaced.',
                $plan['existingUid'],
                $this->excerpt($plan['existingLabel']),
            );
        }

        $lines[] = 'creates: a copy of the source record, connected to it as its translation';
        $lines[] = 'visibility: hidden — a human must unhide it before anyone sees it';

        return $lines;
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        // Usable by a non-admin: the source page is authorised against the
        // acting user's own permissions and the target language against their
        // own language access.
        return false;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // Not repeatable. Without `overwrite` a second run refuses, so a reaped
        // run that already succeeded would report failure for a write that
        // happened; with it, a second run discards a translation a human may
        // have started editing between the two attempts. Both are wrong answers
        // an at-least-once runtime must not produce on its own.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * The human-facing declaration (ADR-152).
     *
     * Both tables, because the call names one of them: which record type the
     * action addresses is the caller's choice, not a property of the tool.
     */
    public function getEditorAction(): EditorAction
    {
        return new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.create_translation_draft.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.create_translation_draft.description',
            'nrllm-editor-action-create-translation',
            self::TABLES,
        );
    }

    /**
     * Everything the localisation needs, resolved and authorised — or the
     * refusal message that stops it.
     *
     * One method for both {@see self::execute()} and {@see self::previewCall()}:
     * whether an existing translation is about to be discarded is the single
     * most important thing on the card, and it must be decided once.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{table:non-empty-string, uid:int, label:string, language:int, existingUid:int, existingLabel:string}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['table', 'uid', 'language', 'overwrite'],
            'translates one record',
        );
        if ($unknown !== null) {
            return $unknown;
        }

        $table = trim(self::toStr($arguments['table'] ?? ''));
        if (!in_array($table, self::TABLES, true)) {
            return sprintf(
                'Refused: "%s" is not a table this tool translates. Allowed: %s.',
                preg_replace('/[^A-Za-z0-9_]/', '', $table) ?? '',
                implode(', ', self::TABLES),
            );
        }

        $uid = self::toInt($arguments['uid'] ?? 0);
        if ($uid < 1) {
            return 'Refused: "uid" must be the positive uid of exactly one record.';
        }

        $language = self::toInt($arguments['language'] ?? 0);
        if ($language < 1) {
            return 'Refused: "language" must be a positive sys_language_uid — 0 is the default language, which is '
                . 'the source of a translation rather than a target.';
        }

        if (!$user->checkLanguageAccess($language)) {
            return sprintf('Refused: you may not edit content in language %d.', $language);
        }

        $overwrite = $arguments['overwrite'] ?? false;
        if (!is_bool($overwrite)) {
            return 'Refused: "overwrite" must be true or false.';
        }

        $source = $this->fetchRecord($table, $uid);
        if ($source === null) {
            return self::NOT_PERMITTED;
        }

        if (self::toInt($source[$this->languageField($table)] ?? 0) !== self::DEFAULT_LANGUAGE) {
            return sprintf(
                'Refused: %s [%d] is itself a translation. Translate the default-language record it belongs to.',
                $table,
                $uid,
            );
        }

        // The acting user's own permission on the page the record lives on. A
        // page translates under PAGE_EDIT, a content element under
        // CONTENT_EDIT — core's own localize() asks only for PAGE_SHOW.
        $pageUid = $table === self::PAGES_TABLE ? $uid : self::toInt($source['pid'] ?? 0);
        $page    = $this->fetchRecord(self::PAGES_TABLE, $pageUid);
        if ($page === null) {
            return self::NOT_PERMITTED;
        }

        $needed = $table === self::PAGES_TABLE ? Permission::PAGE_EDIT : Permission::CONTENT_EDIT;
        if (!$user->doesUserHaveAccess($page, $needed)) {
            return self::NOT_PERMITTED;
        }

        $existing      = $this->fetchTranslation($table, $uid, $language);
        $existingUid   = $existing === null ? 0 : self::toInt($existing['uid'] ?? 0);
        $existingLabel = $existing === null ? '' : self::toStr($existing[$this->labelField($table)] ?? '');

        if ($existingUid > 0 && !$overwrite) {
            return sprintf(
                'Refused: %s [%d] already has a translation in language %d (uid %d). Edit that translation, or '
                . 'pass "overwrite": true to delete it and create a fresh one.',
                $table,
                $uid,
                $language,
                $existingUid,
            );
        }

        return [
            'table'         => $table === self::PAGES_TABLE ? self::PAGES_TABLE : self::CONTENT_TABLE,
            'uid'           => $uid,
            'label'         => self::toStr($source[$this->labelField($table)] ?? ''),
            'language'      => $language,
            'existingUid'   => $existingUid,
            'existingLabel' => $existingLabel,
        ];
    }

    /**
     * Delete the translation that is in the way, or the refusal that stopped it.
     *
     * Through the DataHandler, so the record goes to `deleted = 1` rather than
     * out of the database: an approver who realises afterwards that this was
     * the wrong call can have it back, and the deletion is in `sys_log` under
     * the acting user's name.
     *
     * @param non-empty-string $table
     */
    private function discardExisting(string $table, int $uid, BackendUserAuthentication $user): ?ToolResult
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        // Read back: a delete the DataHandler declined without complaining would
        // otherwise let the localisation run into core's "already localized"
        // refusal, and the caller would read the wrong reason.
        if ($this->fetchRecord($table, $uid) !== null) {
            return ToolResult::error(sprintf(
                'The existing translation [%d] could not be deleted, so no new one was created.',
                $uid,
            ));
        }

        return null;
    }

    /**
     * Hide the fresh translation, or the refusal that stopped it.
     *
     * A separate DataHandler pass because `localize` is a command and this is a
     * field write; running them in one call would mean writing a field of a
     * record whose uid does not exist yet.
     */
    private function hide(string $table, int $uid, BackendUserAuthentication $user): ?ToolResult
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => [$this->hiddenField($table) => 1]]], [], $user);
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);

        return $refused instanceof ToolResult
            ? ToolResult::error(sprintf(
                'Translation [%d] was created but could not be hidden, so it may be visible. Review it now: %s',
                $uid,
                $this->summariseErrors($dataHandler->errorLog),
            ))
            : null;
    }

    /**
     * The live translation of a record in a language, or null when there is
     * none.
     *
     * @return array<string, mixed>|null
     */
    private function fetchTranslation(string $table, int $uid, int $language): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select('uid', $this->labelField($table))
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq(
                    $this->parentField($table),
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT),
                ),
                $queryBuilder->expr()->eq(
                    $this->languageField($table),
                    $queryBuilder->createNamedParameter($language, Connection::PARAM_INT),
                ),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * A record row, or null when no undeleted row carries that uid.
     *
     * The whole row, because {@see BackendUserAuthentication::doesUserHaveAccess()}
     * reads the permission columns off a page row.
     *
     * @param non-empty-string $table
     *
     * @return array<string, mixed>|null
     */
    private function fetchRecord(string $table, int $uid): ?array
    {
        return $this->fetchRowByUid($table, $uid);
    }

    /**
     * The table's language field as the installation declares it.
     */
    private function languageField(string $table): string
    {
        return $this->ctrlString($table, 'languageField', 'sys_language_uid');
    }

    /**
     * The table's translation-parent field as the installation declares it —
     * `l10n_parent` on pages, `l18n_parent` on tt_content in a stock install.
     */
    private function parentField(string $table): string
    {
        return $this->ctrlString($table, 'transOrigPointerField', 'l10n_parent');
    }

    /**
     * The table's label field, used for the human-readable name on the card.
     */
    private function labelField(string $table): string
    {
        return $this->ctrlString($table, 'label', $table === self::PAGES_TABLE ? 'title' : 'header');
    }

    /**
     * The table's "hidden" column as the installation declares it. Read from
     * the TCA rather than hardcoded because it is the field that makes this a
     * DRAFT tool.
     */
    private function hiddenField(string $table): string
    {
        $ctrl = $this->ctrl($table);
        $cols = is_array($ctrl['enablecolumns'] ?? null) ? $ctrl['enablecolumns'] : [];
        $name = $cols['disabled'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'hidden';
    }

    /**
     * One `ctrl` entry as a non-empty string, or the fallback.
     */
    private function ctrlString(string $table, string $key, string $fallback): string
    {
        $value = $this->ctrl($table)[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    /**
     * A table's `ctrl` section from the live TCA, narrowed step by step because
     * `$GLOBALS` is untyped.
     *
     * @return array<array-key, mixed>
     */
    private function ctrl(string $table): array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !is_array($tca[$table] ?? null)) {
            return [];
        }

        $ctrl = $tca[$table]['ctrl'] ?? null;

        return is_array($ctrl) ? $ctrl : [];
    }
}
