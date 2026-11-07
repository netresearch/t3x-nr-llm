<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\FieldFill;

use Netresearch\NrLlm\Domain\Enum\FieldFillOutcome;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\FieldFillTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\Event\AiLabelWriteEvent;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use Netresearch\NrLlm\Service\Tool\RecordTextReader;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes the answer of a finished fill run into its field (ADR-237).
 *
 * Only into a field that is still empty, as the user who saved, through the
 * DataHandler, so history, permissions and the page cache behave as for any
 * edit. Everything else leaves the record as it is and says so in the outcome.
 *
 * In the workspace the save happened in (ADR-265): the run's actor records it,
 * the resolver sets the user up there, and the DataHandler stores a version —
 * or changes a record new there in place. Live is not touched. A run that
 * recorded no workspace writes in live only, as before.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class FieldFillWriter implements FieldFillWriterInterface
{
    use SafeCastTrait;

    /** What the model answers when the record gives it nothing to write from. */
    public const NOTHING = 'NOTHING';

    public function __construct(
        private FieldFillSettings $settings,
        private RecordTextReader $source,
        private ActingBackendUserResolverInterface $actingUsers,
        private LanguageServiceFactory $languageServiceFactory,
        private EventDispatcherInterface $eventDispatcher,
        private ?LoggerInterface $logger = null,
    ) {}

    public function accepts(FieldFillTarget $target): bool
    {
        return $this->settings->isOptedIn($target->table, $target->field);
    }

    public function write(FieldFillTarget $target, string $answer, AiActorContext $actor, string $runUuid = ''): FieldFillOutcome
    {
        // Withdrawn while the model was working: the list is what allows the write.
        if (!$this->accepts($target)) {
            return FieldFillOutcome::NOT_WRITTEN;
        }

        $value = $this->clean($answer);
        if ($value === '' || strtoupper(trim($value, " .*_\t\n")) === self::NOTHING || mb_strlen($value) > $this->settings->maxLength($target->table, $target->field)) {
            return FieldFillOutcome::NOT_WRITTEN;
        }

        // The user as the run was started: in the workspace its actor recorded.
        $user = $this->actingUsers->resolve($actor);
        if (!$user instanceof BackendUserAuthentication) {
            return FieldFillOutcome::NOT_WRITTEN;
        }

        // A run that did not record its workspace, while the user's record
        // names a draft: the DataHandler would write there, a workspace nobody
        // chose for this run. Live only, as before ADR-265.
        $workspace = $user->workspace;
        if ($workspace !== 0 && $actor->workspaceId !== $workspace) {
            return FieldFillOutcome::NOT_WRITTEN;
        }

        // In a draft: the record as that workspace shows it, and null for a
        // table that keeps no versions, which would be written live.
        $row = $this->source->rowIn($target->table, $target->uid, $workspace);
        if ($row === null) {
            return FieldFillOutcome::NOT_WRITTEN;
        }

        if (!FieldFillPlanner::isEmpty($row[$target->field] ?? '')) {
            return FieldFillOutcome::NOT_EMPTY;
        }

        // The save made a draft; the fill joins it or nothing. Gone — discarded
        // or published since — it is not made again for the fill alone. Sent
        // on to review or publication since, core would pull it back to
        // editing with any write, and nobody chose that for an unattended fill.
        if ($workspace > 0 && (self::toInt($row['t3ver_wsid'] ?? 0) !== $workspace || self::toInt($row['t3ver_stage'] ?? 0) !== 0)) {
            return FieldFillOutcome::NOT_WRITTEN;
        }

        // The read-back decides: a foreign hook may log an error about a write
        // that landed, and core may drop a column without a word.
        $errors = $this->store($target, $value, $user);
        if ($errors !== []) {
            $this->logger?->warning('The DataHandler complained about a filled field', ['origin' => $target->origin(), 'errors' => $errors]);
        }

        $stored = $this->source->rowIn($target->table, $target->uid, $workspace);

        if (trim(self::toStr($stored[$target->field] ?? '')) !== $value) {
            return FieldFillOutcome::NOT_WRITTEN;
        }

        $this->announce($target, $runUuid, $workspace, $user);

        return FieldFillOutcome::WRITTEN;
    }

    /**
     * The content write has landed. Dispatch independently so a failed listener cannot suppress the other event or turn the original write into a failed one.
     */
    private function announce(
        FieldFillTarget $target,
        string $runUuid,
        int $workspace,
        BackendUserAuthentication $user,
    ): void {
        if ($runUuid === '') {
            return;
        }

        $write = new AfterAiRecordWrittenEvent(
            $runUuid,
            new RecordReference($target->table, $target->uid),
            WriteKind::UPDATED,
            $workspace,
        );
        foreach ([$write, new AiLabelWriteEvent($write, $user)] as $event) {
            try {
                $this->eventDispatcher->dispatch($event);
            } catch (Throwable $exception) {
                $this->logger?->error(
                    'A listener of an AI-write event failed for a filled field.',
                    [
                        'origin'    => $target->origin(),
                        'event'     => $event::class,
                        'exception' => $exception,
                    ],
                );
            }
        }
    }

    private function clean(string $answer): string
    {
        // Real tags only: strip_tags() would cut "under <12 years" at the bracket.
        $value = trim((string) preg_replace('#</?[a-zA-Z][^>]*>#', '', $answer));

        // Models like to quote what they were asked to write. One pair around
        // the whole answer, because a title at its start is a quote of its own.
        return (string) preg_replace('/^["\'„“«»]\s*([^"„“”«»]*?)\s*["\'“”«»]$/us', '$1', $value);
    }

    /**
     * The worker has no backend environment, and a foreign hook inside the write
     * reads the ambient user. Both are set for this write and put back, so no
     * other run in the process sees them (ADR-237, which narrows what ADR-135
     * says about a process that lacks them).
     *
     * @return list<mixed> the DataHandler's complaints
     */
    private function store(FieldFillTarget $target, string $value, BackendUserAuthentication $user): array
    {
        $language     = $this->languageServiceFactory->createFromUserPreferences($user);
        $hadUser      = array_key_exists('BE_USER', $GLOBALS);
        $hadLanguage  = array_key_exists('LANG', $GLOBALS);
        $previousUser = $GLOBALS['BE_USER'] ?? null;
        $previousLang = $GLOBALS['LANG'] ?? null;

        $GLOBALS['BE_USER'] = $user;
        $GLOBALS['LANG']    = $language;

        try {
            $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
            $dataHandler->start([$target->table => [$target->uid => [$target->field => $value]]], [], $user);
            $dataHandler->process_datamap();

            return array_values($dataHandler->errorLog);
        } catch (Throwable $exception) {
            // A foreign hook may fail after the row is written.
            return [$exception->getMessage()];
        } finally {
            ToolDataHandler::takeFailures();

            if ($hadUser) {
                $GLOBALS['BE_USER'] = $previousUser;
            } else {
                unset($GLOBALS['BE_USER']);
            }

            if ($hadLanguage) {
                $GLOBALS['LANG'] = $previousLang;
            } else {
                unset($GLOBALS['LANG']);
            }
        }
    }
}
