<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Updates;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Updates\ProviderApiTimeoutUpdateWizard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;

#[CoversClass(ProviderApiTimeoutUpdateWizard::class)]
final class ProviderApiTimeoutUpdateWizardTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_provider';

    #[Test]
    public function executeUpdateMigratesOnlyOldDefaultRows(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'identifier' => 'legacy-default',
            'name' => 'Legacy default timeout',
            'adapter_type' => 'openai',
            'api_timeout' => 30,
        ], ['api_timeout' => Connection::PARAM_INT]);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'identifier' => 'custom-timeout',
            'name' => 'Custom timeout',
            'adapter_type' => 'openai',
            'api_timeout' => 45,
        ], ['api_timeout' => Connection::PARAM_INT]);

        $wizard = new ProviderApiTimeoutUpdateWizard($this->getConnectionPool());

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame(120, $this->apiTimeoutOf('legacy-default'));
        self::assertSame(45, $this->apiTimeoutOf('custom-timeout'));
        self::assertFalse($wizard->updateNecessary());
    }

    #[Test]
    public function executeUpdateMigratesHiddenAndDeletedRows(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'identifier' => 'hidden-legacy',
            'name' => 'Hidden legacy row',
            'adapter_type' => 'openai',
            'api_timeout' => 30,
            'hidden' => 1,
            'deleted' => 1,
        ], ['api_timeout' => Connection::PARAM_INT]);

        $wizard = new ProviderApiTimeoutUpdateWizard($this->getConnectionPool());

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame(120, $this->apiTimeoutOf('hidden-legacy'));
    }

    #[Test]
    public function updateNotNecessaryWithoutLegacyRows(): void
    {
        $wizard = new ProviderApiTimeoutUpdateWizard($this->getConnectionPool());

        self::assertFalse($wizard->updateNecessary());
    }

    private function apiTimeoutOf(string $identifier): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $value = $queryBuilder
            ->select('api_timeout')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'identifier',
                    $queryBuilder->createNamedParameter($identifier),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$value;
    }
}
