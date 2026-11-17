<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolAuditScope;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The append-only trail of the administrator switches in the Tools module.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface ToolAuditRepositoryInterface
{
    /**
     * Append one row: the administrator switched a tool or a group from one
     * state to another. The caller records a real change only.
     */
    public function record(ToolAuditScope $scope, string $name, bool $previous, bool $enabled, ?BackendUserAuthentication $actor): void;

    /**
     * Delete rows created strictly before the given UNIX timestamp (retention
     * purge, ADR-064); returns the number of rows deleted.
     */
    public function purgeOlderThan(int $timestamp): int;
}
