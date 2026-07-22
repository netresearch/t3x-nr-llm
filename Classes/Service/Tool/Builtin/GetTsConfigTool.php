<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Return the rootline-merged Page TSconfig effective on a page.
 *
 * Inspired by the typo3-tsconfig tool of EXT:typo3_ai_mate (konradmichalik,
 * GPL-2.0-or-later); own in-process implementation over
 * {@see BackendUtility::getPagesTSconfig()}.
 *
 * Security contract (see {@see ToolInterface} and ADR-042): ADMIN-only —
 * TSconfig can expose backend structure and module configuration. Values
 * under credential-ish keys are redacted for defence in depth, and the
 * output is capped: without a `path` only the top-level keys are listed,
 * with a `path` the subtree renders up to a hard line cap.
 */
final readonly class GetTsConfigTool implements ToolInterface
{
    use RendersTypoScriptTreeTrait;
    use SafeCastTrait;

    private const NOT_FOUND = 'Page not found.';

    public function __construct(
        protected ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_tsconfig',
            'Return the rootline-merged Page TSconfig effective on a page. Without "path" only the '
            . 'top-level keys are listed; with a dotted "path" (e.g. "TCEFORM.tt_content") that '
            . 'subtree is rendered. Credential-like values are redacted.',
            [
                'type'       => 'object',
                'properties' => [
                    'pageUid' => [
                        'type'        => 'integer',
                        'description' => 'The page uid to resolve the Page TSconfig for.',
                    ],
                    'path' => [
                        'type'        => 'string',
                        'description' => 'Optional dotted path to drill into (e.g. "mod.web_layout").',
                    ],
                ],
                'required' => ['pageUid'],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $pageUid = self::toInt($arguments['pageUid'] ?? 0);
        if ($pageUid < 1 || !$this->pageExists($pageUid)) {
            return ToolResult::text(self::NOT_FOUND);
        }

        $path = trim(self::toStr($arguments['path'] ?? ''));

        try {
            $tsConfig = BackendUtility::getPagesTSconfig($pageUid);
        } catch (Throwable) {
            // Neutral by design — resolution internals must not egress.
            return ToolResult::text(self::NOT_FOUND);
        }

        if ($tsConfig === []) {
            return ToolResult::text(sprintf('No Page TSconfig for page %d.', $pageUid));
        }

        if ($path === '') {
            $lines = $this->renderTopLevelKeys($tsConfig);
            array_unshift($lines, sprintf(
                'Page TSconfig for page %d — top-level keys (%d). Pass "path" to drill down:',
                $pageUid,
                count($tsConfig),
            ));

            return ToolResult::text(implode("\n", $lines));
        }

        [$value, $subtree] = $this->drillPath($tsConfig, $path);
        if ($value === null && $subtree === null) {
            return ToolResult::text(sprintf('No Page TSconfig at path "%s".', $path));
        }

        $lines = [sprintf('Page TSconfig for page %d at "%s":', $pageUid, $path)];
        if ($value !== null) {
            $lastSegment = substr((string)strrchr('.' . $path, '.'), 1);
            $lines[]     = sprintf('%s = %s', $path, $this->redactSecretValue($lastSegment, $value));
        }
        if ($subtree !== null) {
            $this->renderTree($subtree, $lines, 0);
        }

        return ToolResult::text(implode("\n", $lines));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: TSconfig exposes backend structure and configuration.
        return true;
    }

    /**
     * Whether a live (non-deleted) page row exists — a missing page returns
     * the same neutral string as a resolution failure.
     */
    private function pageExists(int $pageUid): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Admin-only tool: hidden and timed-out pages are inspectable too;
        // only soft-deleted rows stay excluded.
        $queryBuilder->getRestrictions()->removeAll()
            ->add(new DeletedRestriction());

        $uid = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return $uid !== false;
    }

    public function getGroup(): string
    {
        return 'configuration';
    }
}
