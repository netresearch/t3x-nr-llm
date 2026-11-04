<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * List the redirects of EXT:redirects (ADR-257): the read side of the three
 * redirect writers, so the model finds the uid it changes and sees what
 * already covers a path before it adds a redirect.
 *
 * Admin-only, like the writers. Disabled redirects are listed and marked;
 * QR codes and short URLs (TYPO3 14) are left out, because the writers leave
 * them alone. At most 100 rows per call, newest first.
 */
final readonly class ListRedirectsTool implements ToolInterface, ToolEffectInterface
{
    use DeclaresReadEffectTrait;
    use SafeCastTrait;

    private const TABLE = 'sys_redirect';

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 100;

    private const MAX_VALUE_LENGTH = 200;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'list_redirects',
            'List the redirects of the TYPO3 redirects module: uid, source host and path, target, status code and '
            . 'whether a redirect is disabled. Filter by host or by text in the path or target. Use it to find the uid '
            . 'for update_redirect or disable_redirect, or to check what already covers a path. Admin only.',
            [
                'type'       => 'object',
                'properties' => [
                    'source_host' => [
                        'type'        => 'string',
                        'description' => 'Only redirects for this host (* for the ones that apply to every host).',
                    ],
                    'search' => [
                        'type'        => 'string',
                        'description' => 'Only redirects whose source path or target contains this text.',
                    ],
                    'limit' => [
                        'type'        => 'integer',
                        'description' => 'How many to return, 1 to 100; 50 when omitted.',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication || !$user->isAdmin()) {
            return ToolResult::error('Listing redirects requires an administrator.');
        }

        $columns = $this->columns();
        if ($columns === null) {
            return ToolResult::text('EXT:redirects (typo3/cms-redirects) is not installed in this TYPO3, so there are no redirects.');
        }

        $limit = self::toInt($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder
            ->select('uid', 'source_host', 'source_path', 'is_regexp', 'target', 'target_statuscode', 'disabled')
            ->from(self::TABLE)
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit + 1);

        if (isset($columns['redirect_type'])) {
            $queryBuilder->andWhere($queryBuilder->expr()->in(
                'redirect_type',
                $queryBuilder->createNamedParameter(['default', ''], Connection::PARAM_STR_ARRAY),
            ));
        }

        $host = trim(self::toStr($arguments['source_host'] ?? ''));
        if ($host !== '') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('source_host', $queryBuilder->createNamedParameter($host)));
        }

        $search = trim(self::toStr($arguments['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $queryBuilder->escapeLikeWildcards($search) . '%';
            $queryBuilder->andWhere($queryBuilder->expr()->or(
                $queryBuilder->expr()->like('source_path', $queryBuilder->createNamedParameter($like)),
                $queryBuilder->expr()->like('target', $queryBuilder->createNamedParameter($like)),
            ));
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        if ($rows === []) {
            return ToolResult::text('No redirects match.');
        }

        $more  = count($rows) > $limit;
        $lines = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $lines[] = sprintf(
                '[%d] %s%s%s → %s (%d)%s',
                self::toInt($row['uid'] ?? 0),
                self::toStr($row['source_host'] ?? ''),
                $this->bounded(self::toStr($row['source_path'] ?? '')),
                self::toInt($row['is_regexp'] ?? 0) === 1 ? ' (regular expression)' : '',
                $this->bounded(self::toStr($row['target'] ?? '')),
                self::toInt($row['target_statuscode'] ?? 0),
                self::toInt($row['disabled'] ?? 0) === 1 ? ' — disabled' : '',
            );
        }

        if ($more) {
            $lines[] = sprintf('More redirects match; narrow the filter or raise the limit (at most %d).', self::MAX_LIMIT);
        }

        return ToolResult::text(implode("\n", $lines));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        return true;
    }

    public function getGroup(): string
    {
        return 'configuration';
    }

    /**
     * The `sys_redirect` columns of the live TCA, or null without EXT:redirects.
     *
     * @return array<array-key, mixed>|null
     */
    private function columns(): ?array
    {
        $tca     = $GLOBALS['TCA'] ?? null;
        $table   = is_array($tca) ? ($tca[self::TABLE] ?? null) : null;
        $columns = is_array($table) ? ($table['columns'] ?? null) : null;

        return is_array($columns) ? $columns : null;
    }

    private function bounded(string $value): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($flat) > self::MAX_VALUE_LENGTH ? mb_substr($flat, 0, self::MAX_VALUE_LENGTH) . '…' : $flat;
    }
}
