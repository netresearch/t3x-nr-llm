<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Hook;

use Netresearch\NrLlm\Hook\ModelIdentityChangeHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A discovered reasoning profile describes one model id on one provider
 * (ADR-275). Editing either through the record editor forgets it; editing
 * anything else, or the operator fields, keeps it.
 */
#[CoversClass(ModelIdentityChangeHook::class)]
final class ModelIdentityChangeHookTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_model';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function changingTheModelIdForgetsTheDiscoveredProfile(): void
    {
        $uid = $this->discoveredModel($this->provider(), 'gpt-6-sol');

        $this->save($uid, ['model_id' => 'gpt-6-luna']);

        $row = $this->row($uid);
        self::assertSame('gpt-6-luna', $row['model_id']);
        self::assertSame('', $row['reasoning_discovered']);
        self::assertSame('', $row['reasoning_efforts_discovered']);
        self::assertSame('', $row['reasoning_source']);
        self::assertSame(0, (int) $row['reasoning_confirmed_at']);
        // The operator's own statement about the record stays.
        self::assertSame('yes', $row['reasoning_override']);
    }

    #[Test]
    public function movingTheRecordToAnotherProviderForgetsTheDiscoveredProfile(): void
    {
        $uid   = $this->discoveredModel($this->provider(), 'gpt-6-sol');
        $other = $this->provider();

        $this->save($uid, ['provider_uid' => $other]);

        $row = $this->row($uid);
        self::assertSame($other, (int) $row['provider_uid']);
        self::assertSame('', $row['reasoning_discovered']);
        self::assertSame(0, (int) $row['reasoning_confirmed_at']);
    }

    #[Test]
    public function aSaveThatKeepsTheIdAndTheProviderKeepsTheDiscoveredProfile(): void
    {
        $provider = $this->provider();
        $uid      = $this->discoveredModel($provider, 'gpt-6-sol');

        // The record editor submits every field; unchanged ones are dropped
        // by DataHandler before the hook sees them.
        $this->save($uid, ['name' => 'Renamed', 'model_id' => 'gpt-6-sol', 'provider_uid' => $provider]);

        $row = $this->row($uid);
        self::assertSame('Renamed', $row['name']);
        self::assertSame('yes', $row['reasoning_discovered']);
        self::assertSame('low,high', $row['reasoning_efforts_discovered']);
        self::assertSame('discovery', $row['reasoning_source']);
        self::assertSame(1762682400, (int) $row['reasoning_confirmed_at']);
    }

    private function provider(): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_nrllm_provider');
        $connection->insert('tx_nrllm_provider', [
            'pid'          => 0,
            'identifier'   => 'provider-' . bin2hex(random_bytes(4)),
            'name'         => 'Provider',
            'adapter_type' => 'openai',
        ]);

        return (int) $connection->lastInsertId();
    }

    private function discoveredModel(int $provider, string $modelId): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid'                          => 0,
            'identifier'                   => 'model-' . bin2hex(random_bytes(4)),
            'name'                         => 'Model',
            'provider_uid'                 => $provider,
            'model_id'                     => $modelId,
            'reasoning_override'           => 'yes',
            'reasoning_discovered'         => 'yes',
            'reasoning_efforts_discovered' => 'low,high',
            'reasoning_source'             => 'discovery',
            'reasoning_confirmed_at'       => 1762682400,
        ]);

        return (int) $connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(int $uid, array $fields): void
    {
        $dataHandler          = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start([self::TABLE => [$uid => $fields]], []);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $uid): array
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }
}
