<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\PageTsConfigReader;
use Netresearch\NrLlm\Service\Tool\PageTsConfigReaderInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Return the rootline-merged Page TSconfig effective on a page.
 *
 * Inspired by the typo3-tsconfig tool of EXT:typo3_ai_mate (konradmichalik,
 * GPL-2.0-or-later); own in-process implementation over
 * {@see PageTsConfigReader}, which resolves it for the run's acting user
 * rather than the ambient one (#1017).
 *
 * Security contract (see {@see ToolInterface} and ADR-042): ADMIN-only —
 * TSconfig can expose backend structure and module configuration. Values
 * under credential-ish keys are redacted for defence in depth, and the
 * output is capped: without a `path` only the top-level keys are listed,
 * with a `path` the subtree renders up to a hard line cap.
 *
 * The page must be a live page, or, in a run started in a draft workspace,
 * one that workspace shows: never another workspace's draft and never a
 * version row named by its own uid (ADR-263).
 */
final readonly class GetTsConfigTool implements ToolInterface, ToolEffectInterface
{
    use DeclaresReadEffectTrait;

    use RendersTypoScriptTreeTrait;
    use SafeCastTrait;
    use ReadsTheRunsWorkspaceTrait;

    private const NOT_FOUND = 'Page not found.';

    public function __construct(
        private ConnectionPool $connectionPool,
        // Page TSconfig as the ACTING user sees it, never the ambient one
        // (#1017). Defaulted: the reader is stateless.
        private PageTsConfigReaderInterface $pageTsConfig = new PageTsConfigReader(),
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

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $pageUid = self::toInt($arguments['pageUid'] ?? 0);
        // The run's workspace (ADR-263): the acting user's where the run
        // recorded it, live otherwise — for the page check and the rootline.
        $workspace = $this->workspaceToRead($context, $context->actingBackendUser());
        if ($pageUid < 1 || !$this->pageExists($pageUid, $workspace)) {
            return ToolResult::text(self::NOT_FOUND);
        }

        $path = trim(self::toStr($arguments['path'] ?? ''));

        try {
            $tsConfig = $this->pageTsConfig->forPage($pageUid, $context->actingBackendUser(), $workspace);
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
            $lastSegment = substr((string) strrchr('.' . $path, '.'), 1);
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
     * Whether a (non-deleted) page exists as the run's workspace shows it — a
     * live page, or in a draft workspace one that workspace shows. A missing
     * page returns the same neutral string as a resolution failure.
     */
    private function pageExists(int $pageUid, int $workspace): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        // Admin-only tool: hidden and timed-out pages are inspectable too;
        // only soft-deleted rows stay excluded.
        $queryBuilder->getRestrictions()->removeAll()
            ->add(new DeletedRestriction());
        if ($workspace > 0) {
            // The page or its version in the run's draft workspace, never
            // another workspace's draft (ADR-263).
            $queryBuilder->where(
                $this->shownInWorkspace($queryBuilder, 'pages', $workspace),
                $this->isRecordInWorkspace($queryBuilder, $pageUid),
            );
        } else {
            // A live page: never a version row or a page new in a draft
            // workspace, whose TSconfig is that workspace's draft.
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, 0));
            $queryBuilder->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
            );
        }

        $uid = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->executeQuery()
            ->fetchOne();

        return $uid !== false;
    }

    public function getGroup(): string
    {
        return 'configuration';
    }
}
