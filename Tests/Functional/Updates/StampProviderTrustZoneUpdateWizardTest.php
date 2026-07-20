<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Updates;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Updates\StampProviderTrustZoneUpdateWizard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(StampProviderTrustZoneUpdateWizard::class)]
final class StampProviderTrustZoneUpdateWizardTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_provider';

    #[Test]
    public function existingProvidersAreStampedFromTheirAdapterAndDeclaredRowsAreLeftAlone(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connection = $connectionPool->getConnectionForTable(self::TABLE);

        $connection->insert(self::TABLE, $this->providerRow('local-ollama', 'ollama', ''));
        $connection->insert(self::TABLE, $this->providerRow('cloud-openai', 'openai', ''));
        $connection->insert(self::TABLE, $this->providerRow('already-judged', 'openai', 'externalEu'));

        $wizard = new StampProviderTrustZoneUpdateWizard($connectionPool);

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame('local', $this->zoneOf($connection, 'local-ollama'));
        self::assertSame('externalGlobal', $this->zoneOf($connection, 'cloud-openai'));
        // An operator decision already recorded must never be overwritten.
        self::assertSame('externalEu', $this->zoneOf($connection, 'already-judged'));

        // Idempotent: nothing left to do, and a second run changes nothing.
        self::assertFalse($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());
        self::assertSame('externalEu', $this->zoneOf($connection, 'already-judged'));
    }

    /**
     * @return array<string, int|string>
     */
    private function providerRow(string $identifier, string $adapterType, string $trustZone): array
    {
        return [
            'pid'          => 0,
            'identifier'   => $identifier,
            'name'         => $identifier,
            'adapter_type' => $adapterType,
            'trust_zone'   => $trustZone,
            'is_active'    => 1,
            'tstamp'       => time(),
            'crdate'       => time(),
        ];
    }

    private function zoneOf(Connection $connection, string $identifier): string
    {
        $builder = $connection->createQueryBuilder();
        $builder->getRestrictions()->removeAll();
        $value = $builder
            ->select('trust_zone')
            ->from(self::TABLE)
            ->where($builder->expr()->eq('identifier', $builder->createNamedParameter($identifier)))
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : '';
    }
}
