<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Updates;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Pin MCP servers that are already in use to "no approval required" (ADR-134).
 *
 * `tx_nrllm_mcp_server.requires_approval` ships defaulted to 1, so a NEW server
 * asks a human before any of its tools runs — the safe side of a question this
 * codebase cannot answer for a remote body. The schema update gives every
 * PRE-EXISTING row that same 1, which would silently turn a working integration
 * into one that stops on every call and waits for someone who is not watching.
 * This wizard writes an explicit 0 on those rows, so the upgrade changes
 * nothing, and its description tells the operator to re-enable approval per
 * server after reviewing what those servers actually do.
 *
 * The same shape as {@see StampProviderTrustZoneUpdateWizard} and
 * {@see DataClassEnforcementDefaultUpdateWizard}: the new default is the safe
 * one, and the wizard exists only so that safety is not applied retroactively
 * to something that already worked (ADR-113/115).
 *
 * "Already in use" is `last_imported > 0`, not merely "a row exists". The harm
 * being prevented is an integration that runs today quietly stopping tomorrow,
 * and a server whose catalogue was never imported offers no tools, so there is
 * nothing of the sort to preserve. A server created but not yet imported keeps
 * the safe default — the fail-closed direction, and the one an operator can
 * still change with a tick. This is also what keeps {@see self::updateNecessary()}
 * honest: it stays false for a fresh install that has just configured its first
 * server, so the wizard never offers to disable approval for something new.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[UpgradeWizard('nrLlm_mcpServerApprovalForExisting')]
final readonly class McpServerApprovalDefaultUpdateWizard implements UpgradeWizardInterface
{
    private const TABLE = 'tx_nrllm_mcp_server';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Keep existing MCP servers running without human approval';
    }

    public function getDescription(): string
    {
        return 'Tools imported from an MCP server now pause for a human before an agent run '
            . 'executes them, and a newly configured server has that switched on. Servers that '
            . 'were already importing tools before this setting existed would start suspending '
            . 'every run without anybody having decided so. This wizard sets "requires approval" '
            . 'to off on those servers, leaving them exactly as they behaved before the upgrade. '
            . 'Review each MCP server afterwards and switch approval back on wherever its tools '
            . 'can change something — the extension cannot inspect what a remote tool does.';
    }

    public function updateNecessary(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        // Hidden and deleted servers count: a later un-delete must not bring
        // back a row that suddenly behaves differently from its siblings.
        $queryBuilder->getRestrictions()->removeAll();
        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(...$this->pinnableRows($queryBuilder))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int)$count > 0;
    }

    public function executeUpdate(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->update(self::TABLE)
            ->set('requires_approval', 0, true, ParameterType::INTEGER)
            ->where(...$this->pinnableRows($queryBuilder))
            ->executeStatement();

        return true;
    }

    /**
     * @return array<int, class-string>
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    /**
     * The rows this wizard touches, shared so the count and the update can never
     * disagree — which is also what makes a second run a no-op: after the first
     * one no row is left with a non-zero flag.
     *
     * @return array<int, CompositeExpression|string>
     */
    private function pinnableRows(QueryBuilder $queryBuilder): array
    {
        return [
            $queryBuilder->expr()->neq('requires_approval', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            $queryBuilder->expr()->gt('last_imported', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
        ];
    }
}
