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
use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * One compact system status block (ADR-048).
 *
 * TYPO3 / PHP / database versions, application context, composer mode, OS
 * family and timezone — the first thing to establish in any "why does X
 * behave differently here" conversation.
 *
 * Security contract (see {@see ToolInterface}): admin-only; no paths and no
 * hostnames egress — versions and flags only.
 */
final readonly class GetSystemStatusTool implements ToolInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_system_status',
            'One compact status block: TYPO3, PHP and database versions, application context, '
            . 'composer mode, OS family and timezone.',
            [
                'type'       => 'object',
                'properties' => [],
            ],
        );
    }

    public function execute(array $arguments): ToolResult
    {
        $typo3 = new Typo3Version();

        $lines   = [];
        $lines[] = sprintf('TYPO3: %s', $typo3->getVersion());
        $lines[] = sprintf('PHP: %s', PHP_VERSION);
        $lines[] = sprintf('Database: %s', $this->databaseVersion());
        $lines[] = sprintf('Application context: %s', (string)Environment::getContext());
        $lines[] = sprintf('Composer mode: %s', Environment::isComposerMode() ? 'yes' : 'no (classic)');
        $lines[] = sprintf('OS family: %s', PHP_OS_FAMILY);
        $lines[] = sprintf('Timezone: %s', date_default_timezone_get());

        return ToolResult::text(implode("\n", $lines));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: version fingerprinting is reconnaissance data.
        return true;
    }

    public function getGroup(): string
    {
        return 'system';
    }

    private function databaseVersion(): string
    {
        try {
            $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

            return $connection->getServerVersion();
        } catch (Throwable) {
            return '(unavailable)';
        }
    }
}
