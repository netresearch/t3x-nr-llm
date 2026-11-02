<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Term;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Netresearch\NrLlm\Domain\ValueObject\TermLines;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolver;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function in_array;
use function is_string;

/**
 * Reads the term lists from tx_nrllm_term_list (ADR-238).
 *
 * Plain DBAL with the default restrictions, like the glossary: a hidden list
 * must not stop anything, while the module, which reads through the Extbase
 * repository, still lists it.
 *
 * A table that does not exist yet cannot hold a list, so it reads as "no list"
 * with a warning. That is the state between a code update and the database
 * schema update, and stopping every model call for it would help nobody. Any
 * other failure reaches {@see TermListGuardrail}, which blocks.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TermListResolver implements TermListResolverInterface
{
    private const TABLE = 'tx_nrllm_term_list';

    public function __construct(
        private ConnectionPool $connectionPool,
        private LoggerInterface $logger,
    ) {}

    public function languageOfUser(int $userUid): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $language     = $queryBuilder
            ->select('lang')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return is_string($language) ? $language : '';
    }

    public function forConfiguration(int $configurationUid): array
    {
        try {
            $rows = $this->connectionPool->getQueryBuilderForTable(self::TABLE)
                ->select('name', 'configurations', 'site_identifier', 'language', 'forbidden_terms', 'required_terms', 'message')
                ->from(self::TABLE)
                ->orderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (TableNotFoundException $missing) {
            $this->logger->warning(
                'tx_nrllm_term_list does not exist, so no term list is applied. Run the database schema update.',
                ['exception' => $missing],
            );

            return [];
        }

        $lists = [];
        foreach ($rows as $row) {
            $configurations = GeneralUtility::intExplode(',', $this->string($row['configurations'] ?? ''), true);
            if (!in_array($configurationUid, $configurations, true)) {
                continue;
            }

            $lists[] = new TermListRule(
                name: $this->string($row['name'] ?? ''),
                siteIdentifier: $this->string($row['site_identifier'] ?? ''),
                language: GlossaryResolver::baseLanguage($this->string($row['language'] ?? '')),
                forbiddenTerms: TermLines::parse($this->string($row['forbidden_terms'] ?? '')),
                requiredTerms: TermLines::parse($this->string($row['required_terms'] ?? '')),
                message: $this->string($row['message'] ?? ''),
            );
        }

        return $lists;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
