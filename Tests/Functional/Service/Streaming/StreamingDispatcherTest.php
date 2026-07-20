<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Streaming;

use Generator;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepository;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * End-to-end proof that the streaming lifecycle lands a real row in BOTH
 * accounting tables (ADR-062), using the production UsageTrackerService and
 * TelemetryRepository against the test database — the persistence the previous
 * bypass never produced.
 */
#[CoversClass(StreamingDispatcher::class)]
final class StreamingDispatcherTest extends AbstractFunctionalTestCase
{
    private const USAGE_TABLE     = 'tx_nrllm_service_usage';
    private const TELEMETRY_TABLE = 'tx_nrllm_telemetry';

    private StreamingDispatcher $dispatcher;
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->getService(ConnectionPool::class);

        $this->dispatcher = new StreamingDispatcher(
            $this->getService(BudgetServiceInterface::class),
            $this->getService(UsageTrackerServiceInterface::class),
            new TelemetryRepository($this->connectionPool),
            $this->getService(LlmConfigurationRepository::class),
            new NullLogger(),
            $this->getService(Context::class),
            $this->getService(ExtensionConfiguration::class),
        );
    }

    #[Test]
    public function aStreamedCallPersistsUsageAndTelemetryRows(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('ad-hoc:stream:openai');

        $context = new ProviderCallContext(
            ProviderOperation::Stream,
            'func-stream-corr',
            metadata: [
                BudgetMiddleware::METADATA_BE_USER_UID     => 5,
                StreamingDispatcher::METADATA_PROVIDER      => 'openai',
                StreamingDispatcher::METADATA_PROMPT_CHARS  => 8,
            ],
        );

        $chunks = iterator_to_array($this->dispatcher->stream(
            $context,
            $configuration,
            static function (): Generator {
                yield 'Hello';
                yield ' World';
            },
        ));

        self::assertSame(['Hello', ' World'], $chunks);

        // Usage row: tx_nrllm_service_usage.
        $usage = $this->connectionPool
            ->getConnectionForTable(self::USAGE_TABLE)
            ->select(['*'], self::USAGE_TABLE, ['service_type' => 'stream'])
            ->fetchAssociative();

        self::assertIsArray($usage, 'A streamed call must write a usage row.');
        self::assertSame('openai', $usage['service_provider']);
        self::assertSame(5, (int)$usage['be_user']);
        self::assertGreaterThan(0, (int)$usage['tokens_used']);
        self::assertGreaterThan(0, (int)$usage['completion_tokens']);
        self::assertSame(1, (int)$usage['request_count']);

        // Telemetry row: tx_nrllm_telemetry.
        $telemetry = $this->connectionPool
            ->getConnectionForTable(self::TELEMETRY_TABLE)
            ->select(['*'], self::TELEMETRY_TABLE, ['correlation_id' => 'func-stream-corr'])
            ->fetchAssociative();

        self::assertIsArray($telemetry, 'A streamed call must write a telemetry row.');
        self::assertSame('stream', $telemetry['operation']);
        self::assertSame(1, (int)$telemetry['success']);
        self::assertSame('ad-hoc:stream:openai', $telemetry['configuration_identifier']);
        // TTFT is populated for a streamed run (NULL only for non-streaming rows).
        self::assertNotNull($telemetry['time_to_first_token_ms']);
    }
}
