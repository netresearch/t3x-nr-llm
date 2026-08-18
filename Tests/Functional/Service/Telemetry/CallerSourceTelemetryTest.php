<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Telemetry;

use Netresearch\NrLlm\Service\Telemetry\TelemetryRecord;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Caller-source attribution (ADR-177): the two identity columns reach the
 * database, and an unannotated record keeps the '' defaults so its row is
 * indistinguishable from one written before the feature existed.
 */
#[CoversClass(TelemetryRepository::class)]
final class CallerSourceTelemetryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_telemetry';

    private TelemetryRepository $repository;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        // Private in the container by design; instantiate it directly with the
        // real ConnectionPool, as the sibling telemetry tests do.
        $this->repository = new TelemetryRepository($this->connectionPool);
    }

    #[Test]
    public function anAnnotatedRecordStoresItsCallerIdentity(): void
    {
        $this->repository->record($this->record('corr-annotated', 'ai_seo_helper', 'requestAi'));

        $row = $this->row('corr-annotated');

        self::assertSame('ai_seo_helper', $row['source_extension']);
        self::assertSame('requestAi', $row['source_operation']);
    }

    #[Test]
    public function anUnannotatedRecordStoresEmptyStrings(): void
    {
        $this->repository->record($this->record('corr-plain', '', ''));

        $row = $this->row('corr-plain');

        self::assertSame('', $row['source_extension']);
        self::assertSame('', $row['source_operation']);
    }

    #[Test]
    public function anOverlongIdentityIsTruncatedInsteadOfFailingTheWrite(): void
    {
        $this->repository->record($this->record('corr-long', str_repeat('x', 200), str_repeat('y', 200)));

        $row = $this->row('corr-long');

        self::assertIsString($row['source_extension']);
        self::assertSame(64, \strlen($row['source_extension']));
        self::assertIsString($row['source_operation']);
        self::assertSame(64, \strlen($row['source_operation']));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $correlationId): array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['correlation_id' => $correlationId])
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function record(string $correlationId, string $sourceExtension, string $sourceOperation): TelemetryRecord
    {
        return new TelemetryRecord(
            correlationId: $correlationId,
            operation: 'chat',
            provider: 'openai',
            model: 'gpt-4o',
            configurationIdentifier: 'primary',
            beUser: 1,
            success: true,
            errorClass: '',
            latencyMs: 100,
            cacheHit: false,
            fallbackAttempts: 0,
            servedConfigurationIdentifier: 'primary',
            servedProvider: 'openai',
            servedModel: 'gpt-4o',
            providerRetries: 0,
            sourceExtension: $sourceExtension,
            sourceOperation: $sourceOperation,
        );
    }
}
