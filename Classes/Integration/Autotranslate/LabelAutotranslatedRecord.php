<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Integration\Autotranslate;

use B13\AiLabel\Configuration\ApplicableTablesProvider;
use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use ThieleUndKlose\Autotranslate\Event\AfterTranslateEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Labels a record autotranslate machine-translated through nr-llm as
 * AI-modified in b13/ai-label (ADR-289), so AI Label's frontend notice shows.
 *
 * The rule is the one nr-llm's own translations follow: AI-modified, an
 * AI-generated origin is kept, and the human review is cleared. The label
 * joins the translated columns and is stored with the text in autotranslate's
 * write, which is plain SQL; a write through AI Label's API would be a second
 * DataHandler run, with its own history entry and save hooks, made before the
 * text it labels is stored.
 *
 * Registered only when AI Label is loaded (AutotranslateCompilerPass).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class LabelAutotranslatedRecord
{
    use SafeCastTrait;

    public const FIELD = 'tx_ailabel_metadata';

    public function __construct(
        private AutotranslateSettings $settings,
        private ApplicableTablesProvider $tables,
        private ConnectionPool $connectionPool,
    ) {}

    #[AsEventListener(identifier: 'nr-llm/label-autotranslated-record')]
    public function __invoke(AfterTranslateEvent $event): void
    {
        $table = $event->getTable();
        if (MachineTranslatedText::columnConfig($table, self::FIELD) === null || !$this->tables->isTableApplicable($table) || !$this->settings->routesThroughNrLlm() || !MachineTranslatedText::in($event)) {
            return;
        }

        $previous = AiMetadata::fromJsonString($this->storedMetadata($table, $event->getLocalizedUid()));
        $origin   = $previous->isAiCreated() ? AiOrigin::Generated : AiOrigin::Manipulated;

        $event->setTranslatedColumn(
            self::FIELD,
            json_encode((new AiMetadata())->withOrigin($origin)->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    private function storedMetadata(string $table, int $uid): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return self::toStr($queryBuilder
            ->select(self::FIELD)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne());
    }
}
