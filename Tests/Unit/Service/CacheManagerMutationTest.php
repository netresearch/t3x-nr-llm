<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Mutation-killing tests for CacheManager.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(CacheManager::class)]
class CacheManagerMutationTest extends AbstractUnitTestCase
{
    private function createCacheManager(FrontendInterface $cacheFrontend): CacheManager
    {
        $typo3CacheManager = $this->createMock(Typo3CacheManager::class);
        $typo3CacheManager
            ->method('getCache')
            ->willReturn($cacheFrontend);

        return new CacheManager($typo3CacheManager);
    }

    #[Test]
    public function getReturnsNullWhenCacheHasNoEntry(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->method('has')
            ->with('test_key')
            ->willReturn(false);

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $result = $cacheManager->get('test_key');

        self::assertNull($result);
    }

    #[Test]
    public function getReturnsDataWhenCacheHasEntry(): void
    {
        $expectedData = ['content' => 'cached response'];

        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->method('has')
            ->with('test_key')
            ->willReturn(true);
        $cacheFrontend
            ->method('get')
            ->with('test_key')
            ->willReturn($expectedData);

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $result = $cacheManager->get('test_key');

        self::assertEquals($expectedData, $result);
    }

    #[Test]
    public function getReturnsNullWhenCachedDataIsNotArray(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->method('has')
            ->willReturn(true);
        $cacheFrontend
            ->method('get')
            ->willReturn('not an array');

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $result = $cacheManager->get('test_key');

        self::assertNull($result);
    }

    #[Test]
    public function setUsesDefaultLifetimeOf3600(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                'test_key',
                ['data' => 'value'],
                self::anything(),
                3600,
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->set('test_key', ['data' => 'value']);
    }

    #[Test]
    public function setIncludesDefaultTags(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(fn(array $tags) => in_array('nrllm', $tags, true)
                        && in_array('nrllm_response', $tags, true)),
                self::anything(),
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->set('test_key', ['data' => 'value']);
    }

    #[Test]
    public function setMergesCustomTagsWithDefaultTags(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(fn(array $tags) => in_array('nrllm', $tags, true)
                        && in_array('nrllm_response', $tags, true)
                        && in_array('custom_tag', $tags, true)),
                self::anything(),
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->set('test_key', ['data' => 'value'], 3600, ['custom_tag']);
    }

    #[Test]
    public function setDeduplicatesTags(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(
                    // 'nrllm' should appear only once even if passed as custom tag

                    fn(array $tags) => count(array_keys($tags, 'nrllm', true)) === 1,
                ),
                self::anything(),
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->set('test_key', ['data' => 'value'], 3600, ['nrllm']);
    }

    #[Test]
    public function generateCacheKeyIncludesProviderAndOperation(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheManager = $this->createCacheManager($cacheFrontend);

        $key = $cacheManager->generateCacheKey('openai', 'completion', ['prompt' => 'test']);

        self::assertStringStartsWith('openai_completion_', $key);
    }

    #[Test]
    public function generateCacheKeyProducesDifferentKeysForDifferentParams(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheManager = $this->createCacheManager($cacheFrontend);

        $key1 = $cacheManager->generateCacheKey('openai', 'completion', ['prompt' => 'test1']);
        $key2 = $cacheManager->generateCacheKey('openai', 'completion', ['prompt' => 'test2']);

        self::assertNotEquals($key1, $key2);
    }

    #[Test]
    public function generateCacheKeyProducesSameKeyForSameParamsInDifferentOrder(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheManager = $this->createCacheManager($cacheFrontend);

        $key1 = $cacheManager->generateCacheKey('openai', 'completion', ['a' => 1, 'b' => 2]);
        $key2 = $cacheManager->generateCacheKey('openai', 'completion', ['b' => 2, 'a' => 1]);

        self::assertEquals($key1, $key2);
    }

    #[Test]
    public function cacheCompletionIncludesMessagesInCacheKey(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend->method('set');

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $messages1 = [['role' => 'user', 'content' => 'Hello']];
        $messages2 = [['role' => 'user', 'content' => 'World']];

        $key1 = $cacheManager->cacheCompletion('openai', $messages1, [], ['response' => 'data']);
        $key2 = $cacheManager->cacheCompletion('openai', $messages2, [], ['response' => 'data']);

        self::assertNotEquals($key1, $key2);
    }

    #[Test]
    public function cacheCompletionIncludesOptionsInCacheKey(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend->method('set');

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $messages = [['role' => 'user', 'content' => 'Hello']];

        $key1 = $cacheManager->cacheCompletion('openai', $messages, ['temperature' => 0.5], ['response' => 'data']);
        $key2 = $cacheManager->cacheCompletion('openai', $messages, ['temperature' => 1.0], ['response' => 'data']);

        self::assertNotEquals($key1, $key2);
    }

    #[Test]
    public function cacheCompletionAddsModelTagWithSanitizedName(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(
                    // Model 'gpt-4.0-turbo' should become 'nrllm_model_gpt_4_0_turbo'

                    fn(array $tags) => in_array('nrllm_model_gpt_4_0_turbo', $tags, true),
                ),
                self::anything(),
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $messages = [['role' => 'user', 'content' => 'Hello']];
        $cacheManager->cacheCompletion('openai', $messages, ['model' => 'gpt-4.0-turbo'], ['response' => 'data']);
    }

    #[Test]
    public function cacheCompletionSanitizesBothDotsAndDashesInModelName(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(function (array $tags) {
                    // Both dots and dashes should be replaced with underscores
                    foreach ($tags as $tag) {
                        if (str_starts_with($tag, 'nrllm_model_')) {
                            return !str_contains($tag, '.') && !str_contains($tag, '-');
                        }
                    }
                    return false;
                }),
                self::anything(),
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $messages = [['role' => 'user', 'content' => 'Hello']];
        $cacheManager->cacheCompletion('openai', $messages, ['model' => 'claude-3.5-sonnet'], ['response' => 'data']);
    }

    #[Test]
    public function getCachedCompletionReturnsCachedData(): void
    {
        $expectedData = ['content' => 'cached completion'];
        $messages = [['role' => 'user', 'content' => 'Hello']];

        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->method('has')
            ->willReturn(true);
        $cacheFrontend
            ->method('get')
            ->willReturn($expectedData);

        $cacheManager = $this->createCacheManager($cacheFrontend);

        // First cache, then retrieve
        $cacheFrontend->method('set');
        $cacheManager->cacheCompletion('openai', $messages, [], $expectedData);

        $result = $cacheManager->getCachedCompletion('openai', $messages, []);

        self::assertEquals($expectedData, $result);
    }

    #[Test]
    public function getCachedCompletionReturnsNullForDifferentMessages(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->method('has')
            ->willReturn(false);

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $result = $cacheManager->getCachedCompletion(
            'openai',
            [['role' => 'user', 'content' => 'Different message']],
            [],
        );

        self::assertNull($result);
    }

    #[Test]
    public function cacheEmbeddingsIncludesInputInCacheKey(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend->method('set');

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $key1 = $cacheManager->cacheEmbeddings('openai', 'text1', [], ['embeddings' => [[0.1]]]);
        $key2 = $cacheManager->cacheEmbeddings('openai', 'text2', [], ['embeddings' => [[0.2]]]);

        self::assertNotEquals($key1, $key2);
    }

    #[Test]
    public function cacheEmbeddingsUsesDefaultLifetimeOf86400(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                86400,
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->cacheEmbeddings('openai', 'test', [], ['embeddings' => [[0.1]]]);
    }

    #[Test]
    public function getCachedEmbeddingsReturnsNullForDifferentInput(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->method('has')
            ->willReturn(false);

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $result = $cacheManager->getCachedEmbeddings('openai', 'different text', []);

        self::assertNull($result);
    }

    #[Test]
    public function flushByProviderFlushesWithCorrectTag(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('flushByTag')
            ->with('nrllm_provider_openai');

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->flushByProvider('openai');
    }

    #[Test]
    public function hasReturnsTrueWhenCacheEntryExists(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('has')
            ->with('test_key')
            ->willReturn(true);

        $cacheManager = $this->createCacheManager($cacheFrontend);

        self::assertTrue($cacheManager->has('test_key'));
    }

    #[Test]
    public function hasReturnsFalseWhenCacheEntryDoesNotExist(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('has')
            ->with('test_key')
            ->willReturn(false);

        $cacheManager = $this->createCacheManager($cacheFrontend);

        self::assertFalse($cacheManager->has('test_key'));
    }

    #[Test]
    public function removeCallsCacheRemove(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('remove')
            ->with('test_key');

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->remove('test_key');
    }

    #[Test]
    public function flushCallsCacheFlush(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('flush');

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->flush();
    }

    #[Test]
    public function flushByTagCallsCacheFlushByTag(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('flushByTag')
            ->with('custom_tag');

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->flushByTag('custom_tag');
    }

    #[Test]
    public function cacheCompletionAddsProviderTag(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(fn(array $tags) => in_array('nrllm_provider_claude', $tags, true)),
                self::anything(),
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $messages = [['role' => 'user', 'content' => 'Hello']];
        $cacheManager->cacheCompletion('claude', $messages, [], ['response' => 'data']);
    }

    #[Test]
    public function cacheEmbeddingsAddsProviderTag(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(fn(array $tags) => in_array('nrllm_provider_openai', $tags, true)),
                self::anything(),
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->cacheEmbeddings('openai', 'test', [], ['embeddings' => [[0.1]]]);
    }

    #[Test]
    public function cacheEmbeddingsAddsEmbeddingsTag(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(fn(array $tags) => in_array('nrllm_embeddings', $tags, true)),
                self::anything(),
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $cacheManager->cacheEmbeddings('openai', 'test', [], ['embeddings' => [[0.1]]]);
    }

    #[Test]
    public function cacheCompletionAddsCompletionTag(): void
    {
        $cacheFrontend = $this->createMock(FrontendInterface::class);
        $cacheFrontend
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(fn(array $tags) => in_array('nrllm_completion', $tags, true)),
                self::anything(),
            );

        $cacheManager = $this->createCacheManager($cacheFrontend);

        $messages = [['role' => 'user', 'content' => 'Hello']];
        $cacheManager->cacheCompletion('openai', $messages, [], ['response' => 'data']);
    }
}
