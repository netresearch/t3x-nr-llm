<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command\Fixture;

use Netresearch\NrLlm\Domain\Enum\ToolAuditScope;
use Netresearch\NrLlm\Service\Tool\ToolAuditRepositoryInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * In-memory tool audit repository for command tests: captures the cutoff a
 * purge was asked to run so the command's retention flow can be exercised
 * without a database.
 */
final class InMemoryToolAuditRepository implements ToolAuditRepositoryInterface
{
    /** The cutoff timestamp the last purgeOlderThan() was asked to delete below. */
    public ?int $purgeCutoff = null;

    /** The row count purgeOlderThan() reports as deleted. */
    public int $purgeReturns = 0;

    public function record(ToolAuditScope $scope, string $name, bool $previous, bool $enabled, ?BackendUserAuthentication $actor): void
    {
        // Not needed by the command tests.
    }

    public function purgeOlderThan(int $timestamp): int
    {
        $this->purgeCutoff = $timestamp;

        return $this->purgeReturns;
    }
}
