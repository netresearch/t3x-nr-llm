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
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\RecordCreatorInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
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
 * - **The text is machine-translated (ADR-209).** After `localize` created the
 *   record, every text field the source holds — the `input` and `text` columns
 *   core copies, read from the TCA for the record's type — is translated from
 *   the SOURCE row, not from the copy core prefixed with "[Translate to …:]",
 *   through {@see TranslationServiceInterface::translateWithTranslator()} with
 *   the record's site, so the site glossary applies. Rich text goes as HTML.
 *   The translations are written in one DataHandler pass, as the acting user;
 *   when any of them fails, none is written and the result says so: the draft
 *   then holds the copied source text, which is what it held before this step
 *   existed.
 *
 * Not re-implemented here, deliberately: whether the target language exists for
 * the record's site, and whether the source is a well-formed default-language
 * record. Core's {@see DataHandler::localize()} checks both, and its complaints
 * are surfaced through {@see WritesThroughDataHandlerTrait::refuseOnDataHandlerErrors()}.
 * The permission bar is NOT left to core: `localize()` asks only for
 * {@see Permission::PAGE_SHOW}, which is far too weak for a write.
 */
final readonly class CreateTranslationDraftTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, EditorActionInterface, RecordCreatorInterface
{
    use SafeCastTrait;
    use ErrorMessageSanitizerTrait;
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

    /**
     * The translators the tool offers, by their registry identifier (ADR-209).
     * Spelled out rather than read off the translator classes: the tool module
     * does not depend on the specialized one (ADR-090).
     */
    private const TRANSLATORS = ['deepl', 'llm'];

    /**
     * TranslationService's own choice when no translator and no configuration
     * is named, and a tool call carries no configuration.
     */
    private const DEFAULT_TRANSLATOR = 'llm';

    /**
     * How long an identical translation is answered from the cache (ADR-209):
     * a day, so re-running a discarded draft costs nothing while an edited
     * glossary or source text is a new request anyway.
     */
    private const TRANSLATION_CACHE_TTL = 86400;

    public function __construct(
        private ConnectionPool $connectionPool,
        private TranslationServiceInterface $translationService,
        private SiteFinder $siteFinder,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'create_translation_draft',
            "Translate ONE existing page or content element into another language, using TYPO3's own localize "
            . 'command. The new translation is always created HIDDEN, so a human must review and unhide it '
            . 'before it is visible; nothing is published. The text fields of the new record (headline, body, '
            . 'page title, description and the other text columns) are then MACHINE-TRANSLATED from the source '
            . "record by the chosen translator, applying the glossary of the record's site; rich text keeps its "
            . 'HTML. Pass no translated text yourself. If the machine translation fails, the translation keeps '
            . 'the copied source text and the result says so. Writes through the DataHandler as the acting backend '
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
                    'translator' => [
                        'type'        => 'string',
                        'enum'        => self::TRANSLATORS,
                        'description' => 'Which machine translator translates the text: "deepl" or "llm" (the '
                            . 'configured language model). Defaults to "' . self::DEFAULT_TRANSLATOR . '". "deepl" '
                            . 'is refused when DeepL is not configured on this installation.',
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

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
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

        // The record exists, hidden and connected, whatever happens to its
        // text now — so a failed translation is reported in the answer rather
        // than as a failed call: the write target must reach the provenance
        // event (ADR-187) for a record that was in fact created.
        $translated = $this->translateTexts($plan, $newUid, $user);

        // The new uid leads, as it does for create_page_draft: the source uid
        // is the other number in the answer (NEXT-167).
        return ToolResult::text(sprintf(
            'New translation uid: %d. Created hidden translation [%d] of %s [%d] "%s" in language %d%s. %s It is '
            . 'not visible until a human unhides it.',
            $newUid,
            $newUid,
            $plan['table'],
            $plan['uid'],
            $this->excerpt($plan['label']),
            $plan['language'],
            $plan['existingUid'] > 0 ? sprintf(', replacing translation [%d]', $plan['existingUid']) : '',
            $translated,
        ))->withWriteTarget(new RecordReference($plan['table'], $newUid), WriteKind::CREATED);
    }

    /**
     * Machine-translate the source's text fields into the new record, or say
     * plainly why the text stayed as core copied it (ADR-209).
     *
     * All fields are translated before anything is written, and they are
     * written in one DataHandler pass: a failure half-way leaves no record that
     * is part translated and part source text, and "the text was not
     * translated" stays an exact sentence.
     *
     * @param array{table:non-empty-string, uid:int, label:string, language:int, existingUid:int, existingLabel:string, translator:string, translatorName:string, texts:array<string, array{text:string, html:bool}>, withheld:list<string>} $plan
     *
     * @return string the sentence the result carries
     */
    private function translateTexts(array $plan, int $newUid, BackendUserAuthentication $user): string
    {
        if ($plan['texts'] === []) {
            return 'The source holds no text to translate.';
        }

        $languages = $this->languageCodes($plan['table'], $plan['uid'], $plan['language']);
        if (is_string($languages)) {
            return $this->notTranslated($languages);
        }

        $options = (new TranslationOptions())
            ->withTranslator($plan['translator'])
            ->withSite($languages['site'])
            ->withCacheTtl(self::TRANSLATION_CACHE_TTL)
            ->withBeUserUid(max(0, self::toInt(is_array($user->user) ? ($user->user['uid'] ?? 0) : 0)))
            ->withCallerSource('nr_llm', 'create_translation_draft');

        $values = [];
        $cached = 0;
        foreach ($plan['texts'] as $field => $text) {
            try {
                $result = $this->translationService->translateWithTranslator(
                    $text['text'],
                    $languages['target'],
                    $languages['source'],
                    $text['html'] ? $options->withTagHandling('html') : $options,
                );
            } catch (Throwable $e) {
                return $this->notTranslated(sprintf(
                    '%s failed on "%s": %s',
                    $plan['translatorName'],
                    $field,
                    $this->excerpt($this->sanitizeErrorMessage($e->getMessage())),
                ));
            }

            if (trim($result->translatedText) === '') {
                return $this->notTranslated(sprintf('%s returned no text for "%s"', $plan['translatorName'], $field));
            }

            $values[$field] = $result->translatedText;
            $cached += ($result->metadata['cached'] ?? false) === true ? 1 : 0;
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([$plan['table'] => [$newUid => $values]], [], $user);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            return $this->notTranslated('TYPO3 refused the write: ' . $this->summariseErrors($dataHandler->errorLog));
        }

        // Read back: an empty errorLog is not proof that anything landed. Rich
        // text passes the RTE transformation on its way in, so it is checked
        // for having landed at all rather than byte for byte.
        $stored  = $this->fetchRecord($plan['table'], $newUid) ?? [];
        $missing = [];
        foreach ($values as $field => $value) {
            $landed = trim(self::toStr($stored[$field] ?? ''));
            if ($plan['texts'][$field]['html'] ? $landed === '' : $landed !== trim($value)) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            return $this->notTranslated(sprintf('the translated text of %s did not land', implode(', ', $missing)));
        }

        return sprintf(
            'Machine-translated %d text field(s) (%s) from "%s" to "%s" with %s%s%s — a human must review the text.',
            count($values),
            implode(', ', array_keys($values)),
            $languages['source'],
            $languages['target'],
            $plan['translatorName'],
            $cached > 0 ? sprintf(' (%d from the translation cache)', $cached) : '',
            $plan['withheld'] !== [] ? sprintf('; NOT translated, because you may not edit them: %s', implode(', ', $plan['withheld'])) : '',
        );
    }

    /**
     * The sentence for a draft whose text stayed as core copied it.
     */
    private function notTranslated(string $reason): string
    {
        return sprintf(
            'The text was NOT machine-translated: %s. The translation holds the source text as the localize command '
            . 'copied it.',
            rtrim($reason, '.'),
        );
    }

    /**
     * The site of the record and the two ISO 639-1 codes of the pair, or the
     * reason they cannot be named.
     *
     * Asked only after `localize` succeeded, which means core has already
     * confirmed that the site defines the target language — so this is not a
     * second implementation of that check, and the preview never needs it.
     *
     * @param non-empty-string $table
     *
     * @return array{site:string, source:string, target:string}|string
     */
    private function languageCodes(string $table, int $uid, int $language): array|string
    {
        $pageUid = $uid;
        if ($table !== self::PAGES_TABLE) {
            $pageUid = self::toInt(($this->fetchRecord($table, $uid) ?? [])['pid'] ?? 0);
        }

        try {
            $site   = $this->siteFinder->getSiteByPageId($pageUid);
            $source = $site->getLanguageById(self::DEFAULT_LANGUAGE)->getLocale()->getLanguageCode();
            $target = $site->getLanguageById($language)->getLocale()->getLanguageCode();
        } catch (Throwable $e) {
            return "the languages of the record's site could not be resolved: " . $this->excerpt($e->getMessage());
        }

        $source = strtolower($source);
        $target = strtolower($target);
        if (preg_match('/^[a-z]{2}$/', $source) !== 1 || preg_match('/^[a-z]{2}$/', $target) !== 1) {
            return sprintf('the site names no two-letter language code for the pair ("%s", "%s")', $source, $target);
        }

        return ['site' => $site->getIdentifier(), 'source' => $source, 'target' => $target];
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
        $lines[] = $plan['texts'] === []
            ? 'text: the source holds no text to translate'
            : sprintf(
                'text: MACHINE-TRANSLATED by %s — %s; the glossary of the record\'s site applies',
                $plan['translatorName'],
                implode(', ', array_keys($plan['texts'])),
            );
        if ($plan['withheld'] !== []) {
            $lines[] = sprintf('not translated, because you may not edit them: %s', implode(', ', $plan['withheld']));
        }

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
     * `localize` creates the translation in the table of its source record. The generic `create_record_draft` steps back from it (ADR-197).
     */
    public function getCreatedTables(): array
    {
        return self::TABLES;
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
     * @return array{table:non-empty-string, uid:int, label:string, language:int, existingUid:int, existingLabel:string, translator:string, translatorName:string, texts:array<string, array{text:string, html:bool}>, withheld:list<string>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        $unknown = $this->refuseUnknownArguments(
            $arguments,
            ['table', 'uid', 'language', 'overwrite', 'translator'],
            'translates one record',
        );
        if ($unknown !== null) {
            return $unknown;
        }

        $translator = $arguments['translator'] ?? self::DEFAULT_TRANSLATOR;
        if (!is_string($translator) || !in_array($translator, self::TRANSLATORS, true)) {
            return sprintf('Refused: "translator" must be one of %s.', implode(', ', self::TRANSLATORS));
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

        $translatorName = $this->availableTranslatorName($translator);
        if ($translatorName === null) {
            return sprintf(
                'Refused: the translator "%s" is not configured on this installation. Use "%s", or configure it first.',
                $translator,
                $translator === self::DEFAULT_TRANSLATOR ? 'deepl' : self::DEFAULT_TRANSLATOR,
            );
        }

        $texts    = $this->translatableTexts($table, $source);
        $withheld = $this->columnsTheUserMayNotSet($user, $table, array_keys($texts));
        foreach ($withheld as $column) {
            unset($texts[$column]);
        }

        return [
            'table'          => $table === self::PAGES_TABLE ? self::PAGES_TABLE : self::CONTENT_TABLE,
            'uid'            => $uid,
            'label'          => self::toStr($source[$this->labelField($table)] ?? ''),
            'language'       => $language,
            'existingUid'    => $existingUid,
            'existingLabel'  => $existingLabel,
            'translator'     => $translator,
            'translatorName' => $translatorName,
            'texts'          => $texts,
            'withheld'       => $withheld,
        ];
    }

    /**
     * The display name of a translator this installation can use, or null.
     * Named on the approval card and in the result, so the approver reads
     * which service the text is sent to.
     */
    private function availableTranslatorName(string $identifier): ?string
    {
        try {
            $translator = $this->translationService->getTranslator($identifier);
        } catch (Throwable) {
            return null;
        }

        return $translator->isAvailable() ? sprintf('%s (%s)', $translator->getName(), $identifier) : null;
    }

    /**
     * The source's text to translate, by field: every `input` and `text` column
     * of the record's type that core copies into a translation and that holds
     * text (ADR-209). Read from the live TCA, with the type's
     * `columnsOverrides` applied, because whether `bodytext` is rich text or
     * raw markup is a property of the content type, not of the column.
     *
     * Left out, and why:
     * - `l10n_mode = exclude`: core does not copy it; the translation shows
     *   the source's value.
     * - `l10n_display = defaultAsReadonly`: the form shows the source's value,
     *   read-only, so a translated value would be invisible to the editor.
     * - `readOnly`: nobody edits it, so neither does a tool.
     * - a `text` column with a `renderType` (a code editor, a table wizard):
     *   what it holds is code or structure, not prose — `bodytext` of the
     *   `html` content type is the case in point.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, array{text:string, html:bool}>
     */
    private function translatableTexts(string $table, array $source): array
    {
        $columns = $this->tcaColumnsFor($table) ?? [];
        $ctrl    = $this->ctrl($table);

        $typeField = self::toStr($ctrl['type'] ?? '');
        $type      = $typeField !== '' && !str_contains($typeField, ':') ? self::toStr($source[$typeField] ?? '') : '';
        $allTca    = $GLOBALS['TCA'] ?? null;
        $tca       = is_array($allTca) ? ($allTca[$table] ?? null) : null;
        $typeDef   = is_array($tca) && is_array($tca['types'] ?? null) ? ($tca['types'][$type] ?? null) : null;
        $overrides = is_array($typeDef) && is_array($typeDef['columnsOverrides'] ?? null) ? $typeDef['columnsOverrides'] : [];

        $texts = [];
        foreach ($columns as $field => $column) {
            if (!is_string($field) || !is_array($column) || !is_array($column['config'] ?? null)) {
                continue;
            }

            if (self::toStr($column['l10n_mode'] ?? '') === 'exclude'
                || str_contains(self::toStr($column['l10n_display'] ?? ''), 'defaultAsReadonly')
            ) {
                continue;
            }

            $config   = $column['config'];
            $override = $overrides[$field] ?? null;
            if (is_array($override) && is_array($override['config'] ?? null)) {
                $config = array_replace_recursive($config, $override['config']);
            }

            $fieldType = self::toStr($config['type'] ?? '');
            if (!in_array($fieldType, ['input', 'text'], true)
                || (bool)($config['readOnly'] ?? false)
                || ($fieldType === 'text' && self::toStr($config['renderType'] ?? '') !== '')
            ) {
                continue;
            }

            $value = $source[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $texts[$field] = ['text' => $value, 'html' => (bool)($config['enableRichtext'] ?? false)];
        }

        return $texts;
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
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
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
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
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
                // A live translation; another workspace's draft of one is not
                // what `overwrite` deletes (ADR-198).
                ...$this->liveVersionConstraints($queryBuilder, $table),
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
