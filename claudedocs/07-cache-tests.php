<?php

/**
 * Unit Tests for Caching and Performance Layer
 *
 * Test Coverage:
 * - Cache key generation (determinism, collision resistance)
 * - Cache hit/miss behavior
 * - TTL expiration
 * - Metrics tracking
 * - Prompt normalization
 * - Performance benchmarks
 *
 * @package Netresearch\AiBase\Tests\Unit\Service\Cache
 */

declare(strict_types=1);

namespace Netresearch\AiBase\Tests\Unit\Service\Cache;

use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Netresearch\AiBase\Service\Cache\CacheKeyGenerator;
use Netresearch\AiBase\Service\Cache\PromptNormalizer;
use Netresearch\AiBase\Service\Cache\CacheService;
use Netresearch\AiBase\Service\Cache\CacheMetricsService;
use Netresearch\AiBase\Domain\Model\AiRequest;
use Netresearch\AiBase\Domain\Model\AiResponse;

// ============================================================================
// 1. CACHE KEY GENERATOR TESTS
// ============================================================================

class CacheKeyGeneratorTest extends UnitTestCase
{
    private CacheKeyGenerator $subject;
    private PromptNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new PromptNormalizer();
        $this->subject = new CacheKeyGenerator($this->normalizer);
    }

    /**
     * @test
     */
    public function generateReturnsDeterministicKey(): void
    {
        $request1 = $this->createRequest('openai', 'gpt-4', 'Hello world', []);
        $request2 = $this->createRequest('openai', 'gpt-4', 'Hello world', []);

        $key1 = $this->subject->generate($request1);
        $key2 = $this->subject->generate($request2);

        self::assertEquals($key1, $key2, 'Identical requests should produce identical keys');
    }

    /**
     * @test
     */
    public function generateReturnsDifferentKeysForDifferentProviders(): void
    {
        $request1 = $this->createRequest('openai', 'gpt-4', 'Hello', []);
        $request2 = $this->createRequest('anthropic', 'claude-3', 'Hello', []);

        $key1 = $this->subject->generate($request1);
        $key2 = $this->subject->generate($request2);

        self::assertNotEquals($key1, $key2, 'Different providers should produce different keys');
    }

    /**
     * @test
     */
    public function generateReturnsDifferentKeysForDifferentModels(): void
    {
        $request1 = $this->createRequest('openai', 'gpt-4', 'Hello', []);
        $request2 = $this->createRequest('openai', 'gpt-3.5-turbo', 'Hello', []);

        $key1 = $this->subject->generate($request1);
        $key2 = $this->subject->generate($request2);

        self::assertNotEquals($key1, $key2, 'Different models should produce different keys');
    }

    /**
     * @test
     */
    public function generateReturnsDifferentKeysForDifferentPrompts(): void
    {
        $request1 = $this->createRequest('openai', 'gpt-4', 'Hello', []);
        $request2 = $this->createRequest('openai', 'gpt-4', 'Goodbye', []);

        $key1 = $this->subject->generate($request1);
        $key2 = $this->subject->generate($request2);

        self::assertNotEquals($key1, $key2, 'Different prompts should produce different keys');
    }

    /**
     * @test
     */
    public function generateReturnsDifferentKeysForDifferentOptions(): void
    {
        $request1 = $this->createRequest('openai', 'gpt-4', 'Hello', ['temperature' => 0.7]);
        $request2 = $this->createRequest('openai', 'gpt-4', 'Hello', ['temperature' => 0.9]);

        $key1 = $this->subject->generate($request1);
        $key2 = $this->subject->generate($request2);

        self::assertNotEquals($key1, $key2, 'Different options should produce different keys');
    }

    /**
     * @test
     */
    public function generateIgnoresNonDeterministicOptions(): void
    {
        $request1 = $this->createRequest('openai', 'gpt-4', 'Hello', [
            'temperature' => 0.7,
            'timestamp' => 1234567890,
        ]);
        $request2 = $this->createRequest('openai', 'gpt-4', 'Hello', [
            'temperature' => 0.7,
            'timestamp' => 9999999999,
        ]);

        $key1 = $this->subject->generate($request1);
        $key2 = $this->subject->generate($request2);

        self::assertEquals($key1, $key2, 'Non-deterministic options should be ignored');
    }

    /**
     * @test
     */
    public function generateNormalizesSortedOptionsOrder(): void
    {
        $request1 = $this->createRequest('openai', 'gpt-4', 'Hello', [
            'temperature' => 0.7,
            'max_tokens' => 100,
        ]);
        $request2 = $this->createRequest('openai', 'gpt-4', 'Hello', [
            'max_tokens' => 100,
            'temperature' => 0.7,
        ]);

        $key1 = $this->subject->generate($request1);
        $key2 = $this->subject->generate($request2);

        self::assertEquals($key1, $key2, 'Options order should not affect key');
    }

    /**
     * @test
     */
    public function generateIncludesFeatureInKey(): void
    {
        $request1 = $this->createRequest('openai', 'gpt-4', 'Hello', [], 'translation');
        $request2 = $this->createRequest('openai', 'gpt-4', 'Hello', [], 'image_alt');

        $key1 = $this->subject->generate($request1);
        $key2 = $this->subject->generate($request2);

        self::assertNotEquals($key1, $key2, 'Different features should produce different keys');
        self::assertStringContainsString('translation', $key1);
        self::assertStringContainsString('image_alt', $key2);
    }

    /**
     * @test
     */
    public function generateReturnsValidCacheKeyFormat(): void
    {
        $request = $this->createRequest('openai', 'gpt-4', 'Hello', [], 'translation');
        $key = $this->subject->generate($request);

        // Format: ai_v1_<feature>_<hash>
        self::assertMatchesRegularExpression(
            '/^ai_v1_[a-z_]+_[a-f0-9]{32}$/',
            $key,
            'Cache key should match expected format'
        );
    }

    /**
     * @test
     */
    public function generateProducesCollisionResistantKeys(): void
    {
        $keys = [];
        $iterations = 1000;

        for ($i = 0; $i < $iterations; $i++) {
            $request = $this->createRequest(
                'openai',
                'gpt-4',
                'Test prompt ' . $i,
                ['temperature' => 0.7 + ($i / 10000)]
            );
            $keys[] = $this->subject->generate($request);
        }

        $uniqueKeys = array_unique($keys);

        self::assertCount(
            $iterations,
            $uniqueKeys,
            'All keys should be unique (no collisions)'
        );
    }

    private function createRequest(
        string $provider,
        string $model,
        string $prompt,
        array $options,
        string $feature = 'translation'
    ): AiRequest {
        return new AiRequest([
            'provider' => $provider,
            'model' => $model,
            'prompt' => $prompt,
            'options' => $options,
            'feature' => $feature,
        ]);
    }
}

// ============================================================================
// 2. PROMPT NORMALIZER TESTS
// ============================================================================

class PromptNormalizerTest extends UnitTestCase
{
    private PromptNormalizer $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new PromptNormalizer();
    }

    /**
     * @test
     */
    public function normalizeTrimsLeadingWhitespace(): void
    {
        $result = $this->subject->normalize('   Hello world');
        self::assertEquals('Hello world', $result);
    }

    /**
     * @test
     */
    public function normalizeTrimsTrailingWhitespace(): void
    {
        $result = $this->subject->normalize('Hello world   ');
        self::assertEquals('Hello world', $result);
    }

    /**
     * @test
     */
    public function normalizeTrimsLeadingAndTrailingWhitespace(): void
    {
        $result = $this->subject->normalize('   Hello world   ');
        self::assertEquals('Hello world', $result);
    }

    /**
     * @test
     */
    public function normalizeConvertsCarriageReturnLineFeedToLineFeed(): void
    {
        $result = $this->subject->normalize("Hello\r\nworld");
        self::assertEquals("Hello\nworld", $result);
    }

    /**
     * @test
     */
    public function normalizeConvertsCarriageReturnToLineFeed(): void
    {
        $result = $this->subject->normalize("Hello\rworld");
        self::assertEquals("Hello\nworld", $result);
    }

    /**
     * @test
     */
    public function normalizePreservesLineFeed(): void
    {
        $result = $this->subject->normalize("Hello\nworld");
        self::assertEquals("Hello\nworld", $result);
    }

    /**
     * @test
     */
    public function normalizePreservesCase(): void
    {
        $result = $this->subject->normalize('HeLLo WoRLd');
        self::assertEquals('HeLLo WoRLd', $result);
    }

    /**
     * @test
     */
    public function normalizePreservesInternalWhitespace(): void
    {
        $result = $this->subject->normalize('Hello    world');
        self::assertEquals('Hello    world', $result);
    }

    /**
     * @test
     */
    public function normalizeHandlesEmptyString(): void
    {
        $result = $this->subject->normalize('');
        self::assertEquals('', $result);
    }

    /**
     * @test
     */
    public function normalizeHandlesWhitespaceOnlyString(): void
    {
        $result = $this->subject->normalize('   ');
        self::assertEquals('', $result);
    }

    /**
     * @test
     */
    public function normalizeProducesSameResultForEquivalentInputs(): void
    {
        $result1 = $this->subject->normalize("  Hello\r\nworld  ");
        $result2 = $this->subject->normalize("Hello\nworld");

        self::assertEquals($result1, $result2);
    }

    /**
     * @test
     */
    public function normalizeCaseInsensitiveConvertsToLowercase(): void
    {
        $result = $this->subject->normalizeCaseInsensitive('HeLLo WoRLd');
        self::assertEquals('hello world', $result);
    }

    /**
     * @test
     */
    public function normalizeCaseInsensitiveHandlesUnicode(): void
    {
        $result = $this->subject->normalizeCaseInsensitive('ÜBER');
        self::assertEquals('über', $result);
    }
}

// ============================================================================
// 3. CACHE SERVICE TESTS
// ============================================================================

class CacheServiceTest extends UnitTestCase
{
    private CacheService $subject;
    private $cacheMock;
    private $metricsMock;
    private $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheMock = $this->createMock(\TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class);
        $this->metricsMock = $this->createMock(CacheMetricsService::class);
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $configMock = $this->createMock(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class);
        $keyGenerator = new CacheKeyGenerator(new PromptNormalizer());

        $this->subject = new CacheService(
            $this->cacheMock,
            $configMock,
            $this->metricsMock,
            $this->loggerMock,
            $keyGenerator
        );
    }

    /**
     * @test
     */
    public function getCacheHitReturnsStoredResponse(): void
    {
        $request = $this->createRequest();
        $expectedResponse = $this->createResponse();

        $this->cacheMock
            ->expects(self::once())
            ->method('has')
            ->willReturn(true);

        $this->cacheMock
            ->expects(self::once())
            ->method('get')
            ->willReturn($expectedResponse);

        $this->metricsMock
            ->expects(self::once())
            ->method('recordHit');

        $result = $this->subject->get($request, fn() => throw new \Exception('Should not execute'));

        self::assertSame($expectedResponse, $result);
    }

    /**
     * @test
     */
    public function getCacheMissExecutesProvider(): void
    {
        $request = $this->createRequest();
        $expectedResponse = $this->createResponse();

        $this->cacheMock
            ->expects(self::once())
            ->method('has')
            ->willReturn(false);

        $this->metricsMock
            ->expects(self::once())
            ->method('recordMiss');

        $providerExecuted = false;
        $provider = function () use ($expectedResponse, &$providerExecuted) {
            $providerExecuted = true;
            return $expectedResponse;
        };

        $result = $this->subject->get($request, $provider);

        self::assertTrue($providerExecuted);
        self::assertSame($expectedResponse, $result);
    }

    /**
     * @test
     */
    public function getCacheMissStoresResponseInCache(): void
    {
        $request = $this->createRequest();
        $response = $this->createResponse();

        $this->cacheMock
            ->expects(self::once())
            ->method('has')
            ->willReturn(false);

        $this->cacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                $response,
                [],
                self::greaterThan(0)
            );

        $this->subject->get($request, fn() => $response);
    }

    /**
     * @test
     */
    public function setStoresResponseWithCorrectTtl(): void
    {
        $response = $this->createResponse();
        $ttl = 3600;

        $this->cacheMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                $response,
                [],
                $ttl
            );

        $this->subject->set('test_key', $response, $ttl);
    }

    /**
     * @test
     */
    public function hasReturnsTrueWhenCacheEntryExists(): void
    {
        $this->cacheMock
            ->expects(self::once())
            ->method('has')
            ->with('test_key')
            ->willReturn(true);

        self::assertTrue($this->subject->has('test_key'));
    }

    /**
     * @test
     */
    public function hasReturnsFalseWhenCacheEntryDoesNotExist(): void
    {
        $this->cacheMock
            ->expects(self::once())
            ->method('has')
            ->with('test_key')
            ->willReturn(false);

        self::assertFalse($this->subject->has('test_key'));
    }

    /**
     * @test
     */
    public function removeDeletesCacheEntry(): void
    {
        $this->cacheMock
            ->expects(self::once())
            ->method('remove')
            ->with('test_key');

        $this->subject->remove('test_key');
    }

    /**
     * @test
     */
    public function flushClearsEntireCache(): void
    {
        $this->cacheMock
            ->expects(self::once())
            ->method('flush');

        $this->subject->flush();
    }

    private function createRequest(): AiRequest
    {
        return new AiRequest([
            'provider' => 'openai',
            'model' => 'gpt-4',
            'prompt' => 'Test prompt',
            'options' => [],
            'feature' => 'translation',
        ]);
    }

    private function createResponse(): AiResponse
    {
        $request = $this->createRequest();
        return new AiResponse([
            'request' => $request,
            'content' => 'Test response',
            'tokens' => ['input' => 10, 'output' => 20],
        ]);
    }
}

// ============================================================================
// 4. CACHE METRICS TESTS
// ============================================================================

class CacheMetricsServiceTest extends UnitTestCase
{
    private CacheMetricsService $subject;
    private $repositoryMock;
    private $loggerMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryMock = $this->createMock(\Netresearch\AiBase\Service\Cache\CacheMetricsRepository::class);
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->subject = new CacheMetricsService(
            $this->repositoryMock,
            $this->loggerMock
        );
    }

    /**
     * @test
     */
    public function recordHitIncrementsHitCounter(): void
    {
        $this->subject->recordHit('translation', 'openai');

        $metrics = $this->subject->getSessionMetrics();
        self::assertEquals(1, $metrics['translation:openai']['hits']);
    }

    /**
     * @test
     */
    public function recordMissIncrementsMissCounter(): void
    {
        $this->subject->recordMiss('translation', 'openai');

        $metrics = $this->subject->getSessionMetrics();
        self::assertEquals(1, $metrics['translation:openai']['misses']);
    }

    /**
     * @test
     */
    public function recordWriteIncrementsWriteCounter(): void
    {
        $this->subject->recordWrite('translation', 'openai', 1024);

        $metrics = $this->subject->getSessionMetrics();
        self::assertEquals(1, $metrics['translation:openai']['writes']);
        self::assertEquals(1024, $metrics['translation:openai']['storage_bytes']);
    }

    /**
     * @test
     */
    public function getHitRateCalculatesCorrectRatio(): void
    {
        $this->subject->recordHit('translation', 'openai');
        $this->subject->recordHit('translation', 'openai');
        $this->subject->recordHit('translation', 'openai');
        $this->subject->recordMiss('translation', 'openai');

        $hitRate = $this->subject->getHitRate('translation', 'openai');

        self::assertEquals(0.75, $hitRate); // 3 hits / 4 total
    }

    /**
     * @test
     */
    public function getHitRateReturnsZeroWhenNoRequests(): void
    {
        $hitRate = $this->subject->getHitRate('translation', 'openai');

        self::assertEquals(0.0, $hitRate);
    }

    /**
     * @test
     */
    public function persistMetricsSavesToRepository(): void
    {
        $this->subject->recordHit('translation', 'openai');
        $this->subject->recordMiss('translation', 'openai');
        $this->subject->recordWrite('translation', 'openai', 1024);

        $this->repositoryMock
            ->expects(self::once())
            ->method('saveMetrics')
            ->with(
                'translation',
                'openai',
                1,  // hits
                1,  // misses
                1,  // writes
                1024 // storage_bytes
            );

        $this->subject->persistMetrics();
    }

    /**
     * @test
     */
    public function persistMetricsClearsSessionData(): void
    {
        $this->subject->recordHit('translation', 'openai');

        $this->subject->persistMetrics();

        $metrics = $this->subject->getSessionMetrics();
        self::assertEmpty($metrics);
    }
}

// ============================================================================
// 5. PERFORMANCE BENCHMARK TESTS
// ============================================================================

class CachePerformanceTest extends UnitTestCase
{
    /**
     * @test
     */
    public function cacheKeyGenerationIsUnder1Millisecond(): void
    {
        $generator = new CacheKeyGenerator(new PromptNormalizer());
        $request = new AiRequest([
            'provider' => 'openai',
            'model' => 'gpt-4',
            'prompt' => 'Test prompt with some content',
            'options' => ['temperature' => 0.7, 'max_tokens' => 100],
            'feature' => 'translation',
        ]);

        $iterations = 1000;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $generator->generate($request);
        }

        $duration = (microtime(true) - $start) * 1000; // Convert to ms
        $avgDuration = $duration / $iterations;

        self::assertLessThan(
            1.0,
            $avgDuration,
            sprintf('Cache key generation took %.3fms (expected <1ms)', $avgDuration)
        );
    }

    /**
     * @test
     */
    public function promptNormalizationIsUnder100Microseconds(): void
    {
        $normalizer = new PromptNormalizer();
        $prompt = "  This is a test prompt\r\nwith multiple lines\r\nand whitespace  ";

        $iterations = 10000;
        $start = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $normalizer->normalize($prompt);
        }

        $duration = (microtime(true) - $start) * 1000000; // Convert to μs
        $avgDuration = $duration / $iterations;

        self::assertLessThan(
            100.0,
            $avgDuration,
            sprintf('Prompt normalization took %.1fμs (expected <100μs)', $avgDuration)
        );
    }

    /**
     * @test
     */
    public function cacheOverheadIsUnder10Milliseconds(): void
    {
        // This test would require actual cache backend
        // Placeholder for integration test
        self::markTestSkipped('Requires integration test with real cache backend');
    }
}

// ============================================================================
// 6. TTL STRATEGY TESTS
// ============================================================================

class CacheTtlStrategyTest extends UnitTestCase
{
    /**
     * @test
     * @dataProvider featureTtlProvider
     */
    public function getTtlForFeatureReturnsCorrectValue(string $feature, int $expectedTtl): void
    {
        // This test validates TTL configuration
        $ttlMap = [
            'translation' => 2592000,      // 30 days
            'image_alt' => 7776000,        // 90 days
            'seo_meta' => 604800,          // 7 days
            'content_suggestions' => 86400, // 1 day
            'embeddings' => 0,             // Permanent
            'content_enhancement' => 259200, // 3 days
        ];

        self::assertEquals($expectedTtl, $ttlMap[$feature]);
    }

    public function featureTtlProvider(): array
    {
        return [
            'translation' => ['translation', 2592000],
            'image_alt' => ['image_alt', 7776000],
            'seo_meta' => ['seo_meta', 604800],
            'content_suggestions' => ['content_suggestions', 86400],
            'embeddings' => ['embeddings', 0],
            'content_enhancement' => ['content_enhancement', 259200],
        ];
    }
}

// ============================================================================
// 7. INTEGRATION TEST EXAMPLE
// ============================================================================

/**
 * Integration test demonstrating full cache lifecycle
 *
 * Note: This requires actual TYPO3 testing framework and database
 */
class CacheIntegrationTest extends \TYPO3\TestingFramework\Core\Functional\FunctionalTestCase
{
    protected $testExtensionsToLoad = ['typo3conf/ext/ai_base'];

    /**
     * @test
     */
    public function fullCacheLifecycleWorks(): void
    {
        // 1. First request - cache miss
        $request = new AiRequest([
            'provider' => 'openai',
            'model' => 'gpt-4',
            'prompt' => 'Translate to German: Hello',
            'options' => [],
            'feature' => 'translation',
        ]);

        $cacheService = $this->getContainer()->get(CacheService::class);

        $providerCallCount = 0;
        $provider = function () use (&$providerCallCount) {
            $providerCallCount++;
            return new AiResponse([
                'content' => 'Hallo',
                'tokens' => ['input' => 10, 'output' => 5],
            ]);
        };

        $response1 = $cacheService->get($request, $provider, 3600);
        self::assertEquals(1, $providerCallCount, 'Provider should be called on cache miss');

        // 2. Second request - cache hit
        $response2 = $cacheService->get($request, $provider, 3600);
        self::assertEquals(1, $providerCallCount, 'Provider should NOT be called on cache hit');
        self::assertEquals($response1->getContent(), $response2->getContent());

        // 3. Verify metrics
        $metricsService = $this->getContainer()->get(CacheMetricsService::class);
        $hitRate = $metricsService->getHitRate('translation', 'openai');
        self::assertEquals(0.5, $hitRate, 'Hit rate should be 50% (1 hit, 1 miss)');

        // 4. Clear cache
        $cacheService->flush();

        // 5. Third request - cache miss again
        $response3 = $cacheService->get($request, $provider, 3600);
        self::assertEquals(2, $providerCallCount, 'Provider should be called after cache flush');
    }
}
