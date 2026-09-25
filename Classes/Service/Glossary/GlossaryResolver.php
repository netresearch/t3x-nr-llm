<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Glossary;

use Netresearch\NrLlm\Domain\ValueObject\GlossaryTerms;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Reads the site glossaries from tx_nrllm_glossary (ADR-208).
 *
 * Plain DBAL rather than the Extbase repository: the default restrictions
 * apply here (deleted AND hidden), which is the point — a glossary an editor
 * switched off must not reach a translation, while the backend module, which
 * reads through {@see \Netresearch\NrLlm\Domain\Repository\GlossaryRepository},
 * still lists it.
 *
 * Should two records claim the same site and language pair, the one first in
 * the module's manual order wins (`sorting`, then `uid`). Terms are not merged
 * across records: which term applies must be answerable by opening one record.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class GlossaryResolver implements GlossaryResolverInterface
{
    private const TABLE = 'tx_nrllm_glossary';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function resolve(string $siteIdentifier, string $sourceLanguage, string $targetLanguage): ?ResolvedGlossary
    {
        $source = self::baseLanguage($sourceLanguage);
        $target = self::baseLanguage($targetLanguage);
        if ($siteIdentifier === '' || $source === '' || $target === '') {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('uid', 'source_language', 'target_language', 'entries', 'deepl_glossary_id', 'deepl_entries_hash')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('source_language', $queryBuilder->createNamedParameter($source)),
                $queryBuilder->expr()->eq('target_language', $queryBuilder->createNamedParameter($target)),
            )
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return null;
        }

        $terms = GlossaryTerms::fromText(self::string($row['entries'] ?? ''));
        if ($terms->isEmpty()) {
            return null;
        }

        $uid = $row['uid'] ?? 0;

        return new ResolvedGlossary(
            uid: is_numeric($uid) ? (int)$uid : 0,
            sourceLanguage: $source,
            targetLanguage: $target,
            terms: $terms,
            deeplGlossaryId: self::string($row['deepl_glossary_id'] ?? ''),
            deeplEntriesHash: self::string($row['deepl_entries_hash'] ?? ''),
        );
    }

    public function storeDeepLGlossary(int $uid, string $deeplGlossaryId, string $entriesHash): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['deepl_glossary_id' => $deeplGlossaryId, 'deepl_entries_hash' => $entriesHash],
            ['uid' => $uid],
            ['deepl_glossary_id' => Connection::PARAM_STR, 'deepl_entries_hash' => Connection::PARAM_STR],
        );
    }

    public function isDeepLGlossaryReferenced(string $deeplGlossaryId, int $exceptUid): bool
    {
        if ($deeplGlossaryId === '') {
            return false;
        }

        // Every record counts, hidden and deleted ones included: an editor can
        // switch a hidden record back on, and a deleted one can be restored
        // from the recycler, with the id it still carries.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('deepl_glossary_id', $queryBuilder->createNamedParameter($deeplGlossaryId)),
                $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($exceptUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int)$count > 0;
    }

    /**
     * `de-DE`, `DE`, `de_de` → `de`. Anything that does not start with two
     * letters yields '' and so matches no record.
     */
    public static function baseLanguage(string $languageCode): string
    {
        return preg_match('/^([a-zA-Z]{2})(?:[-_].*)?$/', trim($languageCode), $matches) === 1
            ? strtolower($matches[1])
            : '';
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
