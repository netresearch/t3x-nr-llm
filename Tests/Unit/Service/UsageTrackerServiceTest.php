<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use DateTime;
use Doctrine\DBAL\Result;
use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\SingletonInterface;

#[CoversClass(UsageTrackerService::class)]
class UsageTrackerServiceTest extends AbstractUnitTestCase
{
    private ConnectionPool&Stub $connectionPoolStub;
    private Context&Stub $contextStub;
    private QueryBuilder&Stub $queryBuilderStub;
    private Connection&Stub $connectionStub;
    private ExpressionBuilder&Stub $exprStub;
    private Result&Stub $resultStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPoolStub = self::createStub(ConnectionPool::class);
        $this->contextStub = self::createStub(Context::class);
        $this->queryBuilderStub = self::createStub(QueryBuilder::class);
        $this->connectionStub = self::createStub(Connection::class);
        $this->exprStub = self::createStub(ExpressionBuilder::class);
        $this->resultStub = self::createStub(Result::class);

        // Configure query builder chain
        $this->queryBuilderStub->method('select')->willReturnSelf();
        $this->queryBuilderStub->method('addSelectLiteral')->willReturnSelf();
        $this->queryBuilderStub->method('from')->willReturnSelf();
        $this->queryBuilderStub->method('where')->willReturnSelf();
        $this->queryBuilderStub->method('groupBy')->willReturnSelf();
        $this->queryBuilderStub->method('expr')->willReturn($this->exprStub);
        $this->queryBuilderStub->method('createNamedParameter')->willReturnCallback(fn(string|int|float $v): string => "'$v'");
        $this->queryBuilderStub->method('executeQuery')->willReturn($this->resultStub);

        // Configure expression builder
        $this->exprStub->method('eq')->willReturn('eq_expr');
        $this->exprStub->method('gte')->willReturn('gte_expr');
        $this->exprStub->method('lte')->willReturn('lte_expr');

        // Configure connection
        $this->connectionStub->method('createQueryBuilder')->willReturn($this->queryBuilderStub);
    }

    private function createSubject(): UsageTrackerService
    {
        return new UsageTrackerService(
            $this->connectionPoolStub,
            $this->contextStub,
        );
    }

    private function setupBackendUser(int $userId = 1): void
    {
        $aspectStub = new class ($userId) implements AspectInterface {
            public function __construct(private readonly int $userId) {}

            public function get(string $name): mixed
            {
                return match ($name) {
                    'id' => $this->userId,
                    default => null,
                };
            }
        };

        $this->contextStub
            ->method('getAspect')
            ->with('backend.user')
            ->willReturn($aspectStub);
    }

    private function setupNoBackendUser(): void
    {
        $this->contextStub
            ->method('getAspect')
            ->with('backend.user')
            ->willThrowException(new AspectNotFoundException());
    }

    // ==================== trackUsage tests ====================

    #[Test]
    public function trackUsageInsertsNewRecordWhenNoneExists(): void
    {
        $this->setupBackendUser(5);

        $resultStub = self::createStub(Result::class);
        $resultStub->method('fetchOne')->willReturn(false);

        $queryBuilderStub = self::createStub(QueryBuilder::class);
        $queryBuilderStub->method('select')->willReturnSelf();
        $queryBuilderStub->method('from')->willReturnSelf();
        $queryBuilderStub->method('where')->willReturnSelf();
        $queryBuilderStub->method('expr')->willReturn($this->exprStub);
        $queryBuilderStub->method('createNamedParameter')->willReturnCallback(fn(string|int|float $v): string => "'$v'");
        $queryBuilderStub->method('executeQuery')->willReturn($resultStub);

        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('createQueryBuilder')->willReturn($queryBuilderStub);
        $connectionMock
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrllm_service_usage',
                self::callback(fn(array $data) => $data['service_type'] === 'translation'
                        && $data['service_provider'] === 'deepl'
                        && $data['request_count'] === 1
                        && $data['characters_used'] === 1000
                        && $data['be_user'] === 5),
            );

        $connectionPoolStub = self::createStub(ConnectionPool::class);
        $connectionPoolStub
            ->method('getConnectionForTable')
            ->willReturn($connectionMock);

        $subject = new UsageTrackerService($connectionPoolStub, $this->contextStub);
        $subject->trackUsage('translation', 'deepl', ['characters' => 1000]);
    }

    #[Test]
    public function trackUsageUpdatesExistingRecord(): void
    {
        $this->setupBackendUser(5);

        $resultStub = self::createStub(Result::class);
        $resultStub->method('fetchOne')->willReturn(123);

        $queryBuilderStub = self::createStub(QueryBuilder::class);
        $queryBuilderStub->method('select')->willReturnSelf();
        $queryBuilderStub->method('from')->willReturnSelf();
        $queryBuilderStub->method('where')->willReturnSelf();
        $queryBuilderStub->method('expr')->willReturn($this->exprStub);
        $queryBuilderStub->method('createNamedParameter')->willReturnCallback(fn(string|int|float $v): string => "'$v'");
        $queryBuilderStub->method('executeQuery')->willReturn($resultStub);

        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('createQueryBuilder')->willReturn($queryBuilderStub);
        $connectionMock
            ->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('UPDATE tx_nrllm_service_usage SET'),
                self::callback(fn(array $params) => $params['tokens'] === 500
                        && $params['uid'] === 123),
            );

        $connectionPoolStub = self::createStub(ConnectionPool::class);
        $connectionPoolStub
            ->method('getConnectionForTable')
            ->willReturn($connectionMock);

        $subject = new UsageTrackerService($connectionPoolStub, $this->contextStub);
        $subject->trackUsage('chat', 'openai', ['tokens' => 500]);
    }

    #[Test]
    public function trackUsageWithConfigurationUid(): void
    {
        $this->setupBackendUser();

        $resultStub = self::createStub(Result::class);
        $resultStub->method('fetchOne')->willReturn(false);

        $queryBuilderStub = self::createStub(QueryBuilder::class);
        $queryBuilderStub->method('select')->willReturnSelf();
        $queryBuilderStub->method('from')->willReturnSelf();
        $queryBuilderStub->method('where')->willReturnSelf();
        $queryBuilderStub->method('expr')->willReturn($this->exprStub);
        $queryBuilderStub->method('createNamedParameter')->willReturnCallback(fn(string|int|float $v): string => "'$v'");
        $queryBuilderStub->method('executeQuery')->willReturn($resultStub);

        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('createQueryBuilder')->willReturn($queryBuilderStub);
        $connectionMock
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrllm_service_usage',
                self::callback(fn(array $data) => $data['configuration_uid'] === 42),
            );

        $connectionPoolStub = self::createStub(ConnectionPool::class);
        $connectionPoolStub
            ->method('getConnectionForTable')
            ->willReturn($connectionMock);

        $subject = new UsageTrackerService($connectionPoolStub, $this->contextStub);
        $subject->trackUsage('image', 'dall-e', ['images' => 1], 42);
    }

    #[Test]
    public function trackUsageWithAllMetrics(): void
    {
        $this->setupBackendUser();

        $resultStub = self::createStub(Result::class);
        $resultStub->method('fetchOne')->willReturn(false);

        $queryBuilderStub = self::createStub(QueryBuilder::class);
        $queryBuilderStub->method('select')->willReturnSelf();
        $queryBuilderStub->method('from')->willReturnSelf();
        $queryBuilderStub->method('where')->willReturnSelf();
        $queryBuilderStub->method('expr')->willReturn($this->exprStub);
        $queryBuilderStub->method('createNamedParameter')->willReturnCallback(fn(string|int|float $v): string => "'$v'");
        $queryBuilderStub->method('executeQuery')->willReturn($resultStub);

        $metrics = [
            'tokens' => 100,
            'characters' => 500,
            'audioSeconds' => 30,
            'images' => 2,
            'cost' => 0.05,
        ];

        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('createQueryBuilder')->willReturn($queryBuilderStub);
        $connectionMock
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrllm_service_usage',
                self::callback(fn(array $data) => $data['tokens_used'] === $metrics['tokens']
                        && $data['characters_used'] === $metrics['characters']
                        && $data['audio_seconds_used'] === $metrics['audioSeconds']
                        && $data['images_generated'] === $metrics['images']
                        && $data['estimated_cost'] === $metrics['cost']),
            );

        $connectionPoolStub = self::createStub(ConnectionPool::class);
        $connectionPoolStub
            ->method('getConnectionForTable')
            ->willReturn($connectionMock);

        $subject = new UsageTrackerService($connectionPoolStub, $this->contextStub);
        $subject->trackUsage('mixed', 'provider', $metrics);
    }

    #[Test]
    public function trackUsageWithNoBackendUserUsesZero(): void
    {
        $this->setupNoBackendUser();

        $resultStub = self::createStub(Result::class);
        $resultStub->method('fetchOne')->willReturn(false);

        $queryBuilderStub = self::createStub(QueryBuilder::class);
        $queryBuilderStub->method('select')->willReturnSelf();
        $queryBuilderStub->method('from')->willReturnSelf();
        $queryBuilderStub->method('where')->willReturnSelf();
        $queryBuilderStub->method('expr')->willReturn($this->exprStub);
        $queryBuilderStub->method('createNamedParameter')->willReturnCallback(fn(string|int|float $v): string => "'$v'");
        $queryBuilderStub->method('executeQuery')->willReturn($resultStub);

        $connectionMock = $this->createMock(Connection::class);
        $connectionMock->method('createQueryBuilder')->willReturn($queryBuilderStub);
        $connectionMock
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrllm_service_usage',
                self::callback(fn(array $data) => $data['be_user'] === 0),
            );

        $connectionPoolStub = self::createStub(ConnectionPool::class);
        $connectionPoolStub
            ->method('getConnectionForTable')
            ->willReturn($connectionMock);

        $subject = new UsageTrackerService($connectionPoolStub, $this->contextStub);
        $subject->trackUsage('translation', 'deepl', []);
    }

    // ==================== getUsageReport tests ====================

    #[Test]
    public function getUsageReportReturnsAggregatedData(): void
    {
        $this->connectionPoolStub
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderStub);

        $expectedData = [
            [
                'service_provider' => 'deepl',
                'total_requests' => 100,
                'total_tokens' => 0,
                'total_characters' => 50000,
                'total_audio_seconds' => 0,
                'total_images' => 0,
                'total_cost' => 2.50,
            ],
            [
                'service_provider' => 'google',
                'total_requests' => 50,
                'total_tokens' => 0,
                'total_characters' => 25000,
                'total_audio_seconds' => 0,
                'total_images' => 0,
                'total_cost' => 1.00,
            ],
        ];

        $this->resultStub->method('fetchAllAssociative')->willReturn($expectedData);

        $subject = $this->createSubject();
        $result = $subject->getUsageReport(
            'translation',
            new DateTime('2025-01-01'),
            new DateTime('2025-01-31'),
        );

        self::assertCount(2, $result);
        self::assertEquals('deepl', $result[0]['service_provider']);
        self::assertEquals(100, $result[0]['total_requests']);
    }

    #[Test]
    public function getUsageReportReturnsEmptyArrayWhenNoData(): void
    {
        $this->connectionPoolStub
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderStub);

        $this->resultStub->method('fetchAllAssociative')->willReturn([]);

        $subject = $this->createSubject();
        $result = $subject->getUsageReport(
            'translation',
            new DateTime('2025-01-01'),
            new DateTime('2025-01-31'),
        );

        self::assertEmpty($result);
    }

    // ==================== getUserUsage tests ====================

    #[Test]
    public function getUserUsageReturnsUserAggregatedData(): void
    {
        $this->connectionPoolStub
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderStub);

        $expectedData = [
            [
                'service_type' => 'translation',
                'service_provider' => 'deepl',
                'total_requests' => 25,
                'total_cost' => 1.25,
            ],
            [
                'service_type' => 'image',
                'service_provider' => 'dall-e',
                'total_requests' => 10,
                'total_cost' => 0.50,
            ],
        ];

        $this->resultStub->method('fetchAllAssociative')->willReturn($expectedData);

        $subject = $this->createSubject();
        $result = $subject->getUserUsage(
            5,
            new DateTime('2025-01-01'),
            new DateTime('2025-01-31'),
        );

        self::assertCount(2, $result);
        self::assertEquals('translation', $result[0]['service_type']);
        self::assertEquals(25, $result[0]['total_requests']);
    }

    // ==================== getTodayUsage tests ====================

    #[Test]
    public function getTodayUsageReturnsDataWhenExists(): void
    {
        $this->setupBackendUser(5);

        $this->connectionPoolStub
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderStub);

        $expectedData = [
            'request_count' => 10,
            'tokens_used' => 5000,
            'characters_used' => 0,
            'audio_seconds_used' => 0,
            'images_generated' => 0,
            'estimated_cost' => 0.10,
        ];

        $this->resultStub->method('fetchAssociative')->willReturn($expectedData);

        $subject = $this->createSubject();
        $result = $subject->getTodayUsage('chat', 'openai');

        self::assertNotNull($result);
        self::assertEquals(10, $result['request_count']);
        self::assertEquals(5000, $result['tokens_used']);
        self::assertEquals(0.10, $result['estimated_cost']);
    }

    #[Test]
    public function getTodayUsageReturnsNullWhenNoData(): void
    {
        $this->setupBackendUser();

        $this->connectionPoolStub
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderStub);

        $this->resultStub->method('fetchAssociative')->willReturn(false);

        $subject = $this->createSubject();
        $result = $subject->getTodayUsage('chat', 'openai');

        self::assertNull($result);
    }

    // ==================== getCurrentMonthCost tests ====================

    #[Test]
    public function getCurrentMonthCostReturnsTotalCost(): void
    {
        $this->connectionPoolStub
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderStub);

        $this->resultStub->method('fetchOne')->willReturn('25.50');

        $subject = $this->createSubject();
        $result = $subject->getCurrentMonthCost();

        self::assertEquals(25.50, $result);
    }

    #[Test]
    public function getCurrentMonthCostReturnsZeroWhenNoData(): void
    {
        $this->connectionPoolStub
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderStub);

        $this->resultStub->method('fetchOne')->willReturn(null);

        $subject = $this->createSubject();
        $result = $subject->getCurrentMonthCost();

        self::assertEquals(0.0, $result);
    }

    #[Test]
    public function getCurrentMonthCostReturnsZeroForNonNumericResult(): void
    {
        $this->connectionPoolStub
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderStub);

        $this->resultStub->method('fetchOne')->willReturn(false);

        $subject = $this->createSubject();
        $result = $subject->getCurrentMonthCost();

        self::assertEquals(0.0, $result);
    }

    // ==================== Interface implementation tests ====================

    #[Test]
    public function implementsUsageTrackerServiceInterface(): void
    {
        $subject = $this->createSubject();

        self::assertInstanceOf(UsageTrackerServiceInterface::class, $subject);
    }

    #[Test]
    public function implementsSingletonInterface(): void
    {
        $subject = $this->createSubject();

        self::assertInstanceOf(SingletonInterface::class, $subject);
    }
}
