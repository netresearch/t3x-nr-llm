<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\Enum\ToolAuditScope;
use Netresearch\NrLlm\Service\Tool\ToolAuditRepositoryInterface;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * A tool audit trail whose insert fails, as a lost database connection or a
 * missing table would make it fail.
 */
final class FailingToolAuditRepository implements ToolAuditRepositoryInterface
{
    public function record(ToolAuditScope $scope, string $name, bool $previous, bool $enabled, ?BackendUserAuthentication $actor): void
    {
        throw new RuntimeException('The audit insert failed.', 1795001001);
    }

    public function purgeOlderThan(int $timestamp): int
    {
        return 0;
    }
}
