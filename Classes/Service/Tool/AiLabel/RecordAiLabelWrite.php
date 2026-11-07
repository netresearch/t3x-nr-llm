<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\AiLabel;

use B13\AiLabel\Configuration\ApplicableTablesProvider;
use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use B13\AiLabel\Service\AiLabelApi;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Event\AiLabelWriteEvent;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Service\Tool\RecordTextReader;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Optional adapter: AI Label owns its state, history, review and presentation.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class RecordAiLabelWrite
{
    use SafeCastTrait;

    public function __construct(
        private AiLabelApi $api,
        private ApplicableTablesProvider $tables,
        private RecordTextReader $records,
        private LanguageServiceFactory $languages,
        private ConnectionPool $connectionPool,
    ) {}

    #[AsEventListener(identifier: 'nr-llm/ai-label-write')]
    public function __invoke(AiLabelWriteEvent $event): void
    {
        $write = $event->write;
        $table = $write->record->table;
        // AI-written file descriptions say nothing about the image's origin.
        if (!in_array($table, ['pages', 'tt_content'], true) || !in_array($write->kind, [WriteKind::CREATED, WriteKind::UPDATED], true) || !$this->tables->isTableApplicable($table)) {
            return;
        }

        if ($event->user->getUserId() <= 0 || $event->user->workspace !== $write->workspace) {
            throw new RuntimeException(
                'AI Label needs the user and workspace of the content write.',
                1794080001,
            );
        }

        $row = $this->records->rowIn($table, $write->record->uid, $write->workspace);
        // Do not recreate a discarded draft, or label live from a draft run.
        if ($row === null || $write->workspace > 0 && self::toInt($row['t3ver_wsid'] ?? 0) !== $write->workspace) {
            throw new RuntimeException(
                'The AI-written record no longer exists in its workspace.',
                1794080002,
            );
        }

        if (!array_key_exists('tx_ailabel_metadata', $row)) {
            throw new RuntimeException(
                'AI Label is loaded but its record field is missing. Update the database schema.',
                1794080003,
            );
        }

        $previous = AiMetadata::fromJsonString(self::toStr($row['tx_ailabel_metadata']));
        $origin   = $this->originOf($event, $row, $previous);
        // Every AI text write invalidates review, including an approved tool call.
        $metadata = (new AiMetadata())->withOrigin($origin);
        $uid      = $this->physicalUid($event, self::toInt($row['uid']));
        $this->store($event, $uid, $metadata);

        $stored = $this->records->rowIn($table, $uid, $write->workspace);
        $actual = AiMetadata::fromJsonString(
            self::toStr($stored['tx_ailabel_metadata'] ?? ''),
        );
        if ($stored === null || self::toInt($stored['t3ver_wsid'] ?? 0) !== $write->workspace || $actual->getOrigin() !== $origin || $actual->getReviewedBy() !== 0 || $actual->getReviewedTimestamp() !== 0) {
            throw new RuntimeException(
                'The AI Label metadata write did not take. Check field permissions and save hooks.',
                1794080004,
            );
        }
    }

    private function store(
        AiLabelWriteEvent $event,
        int $uid,
        AiMetadata $metadata,
    ): void {
        $hadUser            = array_key_exists('BE_USER', $GLOBALS);
        $hadLanguage        = array_key_exists('LANG', $GLOBALS);
        $previousUser       = $GLOBALS['BE_USER'] ?? null;
        $previousLanguage   = $GLOBALS['LANG'] ?? null;
        $language           = $this->languages->createFromUserPreferences($event->user);
        $GLOBALS['BE_USER'] = $event->user;
        $GLOBALS['LANG']    = $language;
        try {
            $this->api->aiMetadataUpdate(
                $event->write->record->table,
                $uid,
                $metadata,
                $event->user,
            );
        } finally {
            if ($hadUser) {
                $GLOBALS['BE_USER'] = $previousUser;
            } else {
                unset($GLOBALS['BE_USER']);
            }

            if ($hadLanguage) {
                $GLOBALS['LANG'] = $previousLanguage;
            } else {
                unset($GLOBALS['LANG']);
            }
        }
    }

    /**
     * The existing physical draft, including hidden, scheduled or expired ones.
     * A backend metadata save must not apply frontend visibility restrictions.
     * Addressing the physical uid prevents deletion from auto-creating a new draft.
     */
    private function physicalUid(AiLabelWriteEvent $event, int $liveUid): int
    {
        if ($event->write->workspace === 0) {
            return $liveUid;
        }

        $table      = $event->write->record->table;
        $connection = $this->connectionPool->getConnectionForTable($table);
        $uid        = $connection
            ->executeQuery(
                sprintf(
                    'SELECT uid FROM %s WHERE t3ver_wsid = ? AND t3ver_oid = ? AND deleted = 0',
                    $connection->quoteIdentifier($table),
                ),
                [$event->write->workspace, $liveUid],
            )
            ->fetchOne();
        if (is_numeric($uid) && (int) $uid > 0) {
            return (int) $uid;
        }

        // A record new in the draft has no live counterpart (t3ver_oid = 0).
        $uid = $connection
            ->executeQuery(
                sprintf(
                    'SELECT uid FROM %s WHERE uid = ? AND t3ver_wsid = ? AND t3ver_oid = 0 AND deleted = 0',
                    $connection->quoteIdentifier($table),
                ),
                [$liveUid, $event->write->workspace],
            )
            ->fetchOne();
        if (!is_numeric($uid) || (int) $uid <= 0) {
            throw new RuntimeException(
                'The draft disappeared before its AI Label could be stored.',
                1794080005,
            );
        }

        return (int) $uid;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function originOf(
        AiLabelWriteEvent $event,
        array $row,
        AiMetadata $previous,
    ): AiOrigin {
        $generated = $event->generated && $event->write->kind === WriteKind::CREATED && $event->write->record->table === 'tt_content' && ($row['CType'] ?? '') === 'text' && trim(self::toStr($row['bodytext'] ?? '')) !== '';

        return $generated || $previous->isAiCreated() ? AiOrigin::Generated : AiOrigin::Manipulated;
    }
}
