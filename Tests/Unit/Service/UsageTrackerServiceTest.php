<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use DateTime;
use Doctrine\DBAL\Result;
use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(UsageTrackerService::class)]
class UsageTrackerServiceTest extends AbstractUnitTestCase
{
    private ConnectionPool&MockObject $connectionPoolMock;
    private Context&MockObject $contextMock;
    private QueryBuilder&MockObject $queryBuilderMock;
    private Connection&MockObject $connectionMock;
    private ExpressionBuilder&MockObject $exprMock;
    private Result&MockObject $resultMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPoolMock = $this->createMock(ConnectionPool::class);
        $this->contextMock = $this->createMock(Context::class);
        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);
        $this->connectionMock = $this->createMock(Connection::class);
        $this->exprMock = $this->createMock(ExpressionBuilder::class);
        $this->resultMock = $this->createMock(Result::class);

        // Configure query builder chain
        $this->queryBuilderMock->method('select')->willReturnSelf();
        $this->queryBuilderMock->method('addSelectLiteral')->willReturnSelf();
        $this->queryBuilderMock->method('from')->willReturnSelf();
        $this->queryBuilderMock->method('where')->willReturnSelf();
        $this->queryBuilderMock->method('groupBy')->willReturnSelf();
        $this->queryBuilderMock->method('expr')->willReturn($this->exprMock);
        $this->queryBuilderMock->method('createNamedParameter')->willReturnCallback(fn($v) => "'$v'");
        $this->queryBuilderMock->method('executeQuery')->willReturn($this->resultMock);

        // Configure expression builder
        $this->exprMock->method('eq')->willReturn('eq_expr');
        $this->exprMock->method('gte')->willReturn('gte_expr');
        $this->exprMock->method('lte')->willReturn('lte_expr');

        // Configure connection
        $this->connectionMock->method('createQueryBuilder')->willReturn($this->queryBuilderMock);
    }

    private function createSubject(): UsageTrackerService
    {
        return new UsageTrackerService(
            $this->connectionPoolMock,
            $this->contextMock,
        );
    }

    private function setupBackendUser(int $userId = 1): void
    {
        $aspectStub = new class ($userId) implements AspectInterface {
            public function __construct(private int $userId) {}

            public function get(string $name): mixed
            {
                return match ($name) {
                    'id' => $this->userId,
                    default => null,
                };
            }
        };

        $this->contextMock
            ->method('getAspect')
            ->with('backend.user')
            ->willReturn($aspectStub);
    }

    private function setupNoBackendUser(): void
    {
        $this->contextMock
            ->method('getAspect')
            ->with('backend.user')
            ->willThrowException(new AspectNotFoundException());
    }

    // ==================== trackUsage tests ====================

    #[Test]
    public function trackUsageInsertsNewRecordWhenNoneExists(): void
    {
        $this->setupBackendUser(5);

        $this->connectionPoolMock
            ->method('getConnectionForTable')
            ->willReturn($this->connectionMock);

        // No existing record
        $this->resultMock->method('fetchOne')->willReturn(false);

        $this->connectionMock
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

        $subject = $this->createSubject();
        $subject->trackUsage('translation', 'deepl', ['characters' => 1000]);
    }

    #[Test]
    public function trackUsageUpdatesExistingRecord(): void
    {
        $this->setupBackendUser(5);

        $this->connectionPoolMock
            ->method('getConnectionForTable')
            ->willReturn($this->connectionMock);

        // Existing record found
        $this->resultMock->method('fetchOne')->willReturn(123);

        $this->connectionMock
            ->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('UPDATE tx_nrllm_service_usage SET'),
                self::callback(fn(array $params) => $params['tokens'] === 500
                        && $params['uid'] === 123),
            );

        $subject = $this->createSubject();
        $subject->trackUsage('chat', 'openai', ['tokens' => 500]);
    }

    #[Test]
    public function trackUsageWithConfigurationUid(): void
    {
        $this->setupBackendUser();

        $this->connectionPoolMock
            ->method('getConnectionForTable')
            ->willReturn($this->connectionMock);

        $this->resultMock->method('fetchOne')->willReturn(false);

        $this->connectionMock
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrllm_service_usage',
                self::callback(fn(array $data) => $data['configuration_uid'] === 42),
            );

        $subject = $this->createSubject();
        $subject->trackUsage('image', 'dall-e', ['images' => 1], 42);
    }

    #[Test]
    public function trackUsageWithAllMetrics(): void
    {
        $this->setupBackendUser();

        $this->connectionPoolMock
            ->method('getConnectionForTable')
            ->willReturn($this->connectionMock);

        $this->resultMock->method('fetchOne')->willReturn(false);

        $metrics = [
            'tokens' => 100,
            'characters' => 500,
            'audioSeconds' => 30,
            'images' => 2,
            'cost' => 0.05,
        ];

        $this->connectionMock
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

        $subject = $this->createSubject();
        $subject->trackUsage('mixed', 'provider', $metrics);
    }

    #[Test]
    public function trackUsageWithNoBackendUserUsesZero(): void
    {
        $this->setupNoBackendUser();

        $this->connectionPoolMock
            ->method('getConnectionForTable')
            ->willReturn($this->connectionMock);

        $this->resultMock->method('fetchOne')->willReturn(false);

        $this->connectionMock
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrllm_service_usage',
                self::callback(fn(array $data) => $data['be_user'] === 0),
            );

        $subject = $this->createSubject();
        $subject->trackUsage('translation', 'deepl', []);
    }

    // ==================== getUsageReport tests ====================

    #[Test]
    public function getUsageReportReturnsAggregatedData(): void
    {
        $this->connectionPoolMock
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderMock);

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

        $this->resultMock->method('fetchAllAssociative')->willReturn($expectedData);

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
        $this->connectionPoolMock
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderMock);

        $this->resultMock->method('fetchAllAssociative')->willReturn([]);

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
        $this->connectionPoolMock
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderMock);

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

        $this->resultMock->method('fetchAllAssociative')->willReturn($expectedData);

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

        $this->connectionPoolMock
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderMock);

        $expectedData = [
            'request_count' => 10,
            'tokens_used' => 5000,
            'characters_used' => 0,
            'audio_seconds_used' => 0,
            'images_generated' => 0,
            'estimated_cost' => 0.10,
        ];

        $this->resultMock->method('fetchAssociative')->willReturn($expectedData);

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

        $this->connectionPoolMock
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderMock);

        $this->resultMock->method('fetchAssociative')->willReturn(false);

        $subject = $this->createSubject();
        $result = $subject->getTodayUsage('chat', 'openai');

        self::assertNull($result);
    }

    // ==================== getCurrentMonthCost tests ====================

    #[Test]
    public function getCurrentMonthCostReturnsTotalCost(): void
    {
        $this->connectionPoolMock
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderMock);

        $this->resultMock->method('fetchOne')->willReturn('25.50');

        $subject = $this->createSubject();
        $result = $subject->getCurrentMonthCost();

        self::assertEquals(25.50, $result);
    }

    #[Test]
    public function getCurrentMonthCostReturnsZeroWhenNoData(): void
    {
        $this->connectionPoolMock
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderMock);

        $this->resultMock->method('fetchOne')->willReturn(null);

        $subject = $this->createSubject();
        $result = $subject->getCurrentMonthCost();

        self::assertEquals(0.0, $result);
    }

    #[Test]
    public function getCurrentMonthCostReturnsZeroForNonNumericResult(): void
    {
        $this->connectionPoolMock
            ->method('getQueryBuilderForTable')
            ->willReturn($this->queryBuilderMock);

        $this->resultMock->method('fetchOne')->willReturn(false);

        $subject = $this->createSubject();
        $result = $subject->getCurrentMonthCost();

        self::assertEquals(0.0, $result);
    }

    // ==================== Interface implementation tests ====================

    #[Test]
    public function implementsUsageTrackerServiceInterface(): void
    {
        $subject = $this->createSubject();

        self::assertInstanceOf(\Netresearch\NrLlm\Service\UsageTrackerServiceInterface::class, $subject);
    }

    #[Test]
    public function implementsSingletonInterface(): void
    {
        $subject = $this->createSubject();

        self::assertInstanceOf(\TYPO3\CMS\Core\SingletonInterface::class, $subject);
    }
}
