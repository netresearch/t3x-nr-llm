<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider\Middleware;

use DateTimeImmutable;
use Generator;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Service\Analytics\FallbackRescueReport;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepository;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\AllowingBudgetService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A telemetry row names the configuration that ANSWERED, not only the one that
 * was asked (issue #633).
 *
 * The load-bearing case is the first test: a retryable primary failure routes
 * the call to a sibling, and the single row that run produces must attribute it
 * to that sibling — provider and model included — while still recording what
 * was requested. Before the `served_*` columns the row credited the primary,
 * which never served it.
 *
 * The other tests pin the boundaries of that: no swap means both sides name the
 * same configuration, an exhausted chain is NOT a swap (nobody served), and the
 * streaming lifecycle writes the same two triples as the pipeline.
 */
#[CoversClass(TelemetryMiddleware::class)]
#[CoversClass(FallbackMiddleware::class)]
#[CoversClass(StreamingDispatcher::class)]
#[CoversClass(TelemetryRepository::class)]
final class ServedConfigurationTelemetryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_telemetry';

    private ConnectionPool $connectionPool;

    private LlmConfigurationRepository $configurations;

    private FallbackCandidateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('ServedConfigurations.csv');

        $this->connectionPool = $this->getService(ConnectionPool::class);
        $this->configurations = $this->getService(LlmConfigurationRepository::class);
        $this->resolver       = new FallbackCandidateResolver($this->configurations);
    }

    #[Test]
    public function aRetryablePrimaryFailureAttributesTheRunToTheSiblingThatAnswered(): void
    {
        self::assertSame('served-alt', $this->runWithFailingPrimary());

        $rows = $this->rows();
        self::assertCount(1, $rows, 'One pipeline run writes exactly one telemetry row.');

        $row = $rows[0];
        self::assertSame(1, (int)$row['success']);
        self::assertSame(1, (int)$row['fallback_attempts']);

        // What was asked for.
        self::assertSame('served-primary', $row['configuration_identifier']);
        self::assertSame('openai', $row['provider']);
        self::assertSame('gpt-5', $row['model']);

        // What answered — all three differ from what was asked for.
        self::assertSame('served-alt', $row['served_configuration_identifier']);
        self::assertSame('ollama', $row['served_provider']);
        self::assertSame('llama3.3:70b', $row['served_model']);
    }

    #[Test]
    public function aRunWithoutAFallbackNamesTheRequestedConfigurationOnBothSides(): void
    {
        $this->pipeline()->run(
            ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration('served-lonely')),
            static fn(): string => 'answer',
        );

        $row = $this->rows()[0];
        self::assertSame(0, (int)$row['fallback_attempts']);
        self::assertSame('served-lonely', $row['configuration_identifier']);
        self::assertSame('served-lonely', $row['served_configuration_identifier']);
        self::assertSame($row['provider'], $row['served_provider']);
        self::assertSame($row['model'], $row['served_model']);
    }

    #[Test]
    public function anExhaustedChainIsNotASwapAndKeepsNamingTheRequestedConfiguration(): void
    {
        $caught = null;

        try {
            $this->pipeline()->run(
                ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration('served-primary')),
                static function (): string {
                    throw new ProviderConnectionException('everything down', 1770000102);
                },
            );
        } catch (Throwable $e) {
            // The exception type is FallbackMiddleware's business; this test is
            // about the row it leaves behind.
            $caught = $e;
        }

        self::assertInstanceOf(Throwable::class, $caught, 'The exhausted chain must surface an exception.');

        $row = $this->rows()[0];
        self::assertSame(0, (int)$row['success']);
        self::assertSame(1, (int)$row['fallback_attempts'], 'The sibling was dispatched...');
        self::assertSame(
            'served-primary',
            $row['served_configuration_identifier'],
            '...but it did not serve, so it must not be named as the serving configuration.',
        );
    }

    #[Test]
    public function theStreamingPathWritesTheSameTwoTriplesAsThePipeline(): void
    {
        $dispatcher = new StreamingDispatcher(
            new AllowingBudgetService(),
            $this->getService(UsageTrackerServiceInterface::class),
            new TelemetryRepository($this->connectionPool),
            $this->resolver,
            new NullLogger(),
            $this->getService(Context::class),
            $this->getService(ExtensionConfiguration::class),
        );

        $chunks = iterator_to_array($dispatcher->stream(
            new ProviderCallContext(ProviderOperation::Stream, 'served-stream-corr'),
            $this->configuration('served-primary'),
            static function (LlmConfiguration $configuration): Generator {
                if ($configuration->getIdentifier() === 'served-primary') {
                    yield from [];

                    throw new ProviderConnectionException('primary down', 1770000103);
                }

                yield $configuration->getIdentifier();
            },
        ));

        self::assertSame(['served-alt'], $chunks);

        $row = $this->rows()[0];
        self::assertSame(1, (int)$row['fallback_attempts']);
        self::assertSame('served-primary', $row['configuration_identifier']);
        self::assertSame('openai', $row['provider']);
        self::assertSame('gpt-5', $row['model']);
        self::assertSame('served-alt', $row['served_configuration_identifier']);
        self::assertSame('ollama', $row['served_provider']);
        self::assertSame('llama3.3:70b', $row['served_model']);
    }

    #[Test]
    public function theAnalyticsListShowsTheRescueAndNotTheRunThatNeededNone(): void
    {
        // One rescued run and one that served itself.
        $this->runWithFailingPrimary();
        $this->pipeline()->run(
            ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration('served-lonely')),
            static fn(): string => 'answer',
        );

        $rescues = (new FallbackRescueReport(new TelemetryRepository($this->connectionPool)))
            ->rescuesSince(new DateTimeImmutable('-1 day'));

        self::assertCount(1, $rescues);
        self::assertSame('served-primary', $rescues[0]['requestedConfiguration']);
        self::assertSame('served-alt', $rescues[0]['servedConfiguration']);
        self::assertSame('ollama', $rescues[0]['servedProvider']);
    }

    /**
     * One pipeline run whose primary fails retryably and whose sibling answers.
     *
     * @return string the identifier of the configuration whose call returned
     */
    private function runWithFailingPrimary(): string
    {
        $result = $this->pipeline()->run(
            ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->configuration('served-primary')),
            static function (ProviderCallContext $context): string {
                $configuration = $context->configuration;
                self::assertInstanceOf(LlmConfiguration::class, $configuration);

                if ($configuration->getIdentifier() === 'served-primary') {
                    throw new ProviderConnectionException('primary down', 1770000101);
                }

                return $configuration->getIdentifier();
            },
        );

        self::assertIsString($result);

        return $result;
    }

    private function pipeline(): MiddlewarePipeline
    {
        $health = self::createStub(ProviderHealthServiceInterface::class);
        $health->method('reorder')->willReturnArgument(0);

        return new MiddlewarePipeline([
            new TelemetryMiddleware(
                new TelemetryRepository($this->connectionPool),
                $this->getService(Context::class),
                $this->getService(ExtensionConfiguration::class),
                new NullLogger(),
            ),
            new FallbackMiddleware($this->resolver, new NullLogger(), $health),
        ]);
    }

    private function configuration(string $identifier): LlmConfiguration
    {
        $configuration = $this->configurations->findOneByIdentifier($identifier);
        self::assertInstanceOf(
            LlmConfiguration::class,
            $configuration,
            sprintf('Fixture row %s missing — the test would prove nothing.', $identifier),
        );

        return $configuration;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }
}
