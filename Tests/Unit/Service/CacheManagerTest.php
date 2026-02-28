<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

#[CoversClass(CacheManager::class)]
class CacheManagerTest extends AbstractUnitTestCase
{
    private CacheManager $subject;

    /** @var FrontendInterface&Stub */
    private FrontendInterface $cacheFrontendStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFrontendStub = self::createStub(FrontendInterface::class);

        $typo3CacheManagerStub = self::createStub(Typo3CacheManager::class);
        $typo3CacheManagerStub
            ->method('getCache')
            ->willReturn($this->cacheFrontendStub);

        $this->subject = new CacheManager($typo3CacheManagerStub);
    }

    /**
     * Create a CacheManager with a mock cache frontend for expectation testing.
     *
     * @return array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject}
     */
    private function createSubjectWithMockFrontend(): array
    {
        $cacheFrontendMock = $this->createMock(FrontendInterface::class);
        $typo3CacheManagerStub = self::createStub(Typo3CacheManager::class);
        $typo3CacheManagerStub->method('getCache')->willReturn($cacheFrontendMock);

        return [
            'subject' => new CacheManager($typo3CacheManagerStub),
            'cacheFrontend' => $cacheFrontendMock,
        ];
    }

    #[Test]
    public function generateCacheKeyReturnsConsistentKey(): void
    {
        $params = ['messages' => [['role' => 'user', 'content' => 'Hello']]];

        $key1 = $this->subject->generateCacheKey('openai', 'completion', $params);
        $key2 = $this->subject->generateCacheKey('openai', 'completion', $params);

        self::assertEquals($key1, $key2);
    }

    #[Test]
    public function generateCacheKeyDiffersForDifferentProviders(): void
    {
        $params = ['messages' => [['role' => 'user', 'content' => 'Hello']]];

        $key1 = $this->subject->generateCacheKey('openai', 'completion', $params);
        $key2 = $this->subject->generateCacheKey('claude', 'completion', $params);

        self::assertNotEquals($key1, $key2);
    }

    #[Test]
    public function generateCacheKeyDiffersForDifferentOperations(): void
    {
        $params = ['input' => 'test'];

        $key1 = $this->subject->generateCacheKey('openai', 'completion', $params);
        $key2 = $this->subject->generateCacheKey('openai', 'embeddings', $params);

        self::assertNotEquals($key1, $key2);
    }

    #[Test]
    public function generateCacheKeyIgnoresStreamParam(): void
    {
        $params1 = ['messages' => [['role' => 'user', 'content' => 'Hello']], 'stream' => true];
        $params2 = ['messages' => [['role' => 'user', 'content' => 'Hello']], 'stream' => false];

        $key1 = $this->subject->generateCacheKey('openai', 'completion', $params1);
        $key2 = $this->subject->generateCacheKey('openai', 'completion', $params2);

        self::assertEquals($key1, $key2);
    }

    #[Test]
    public function generateCacheKeyIgnoresUserParam(): void
    {
        $params1 = ['messages' => [['role' => 'user', 'content' => 'Hello']], 'user' => 'user1'];
        $params2 = ['messages' => [['role' => 'user', 'content' => 'Hello']], 'user' => 'user2'];

        $key1 = $this->subject->generateCacheKey('openai', 'completion', $params1);
        $key2 = $this->subject->generateCacheKey('openai', 'completion', $params2);

        self::assertEquals($key1, $key2);
    }

    #[Test]
    public function getReturnsNullWhenNotCached(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('has')
            ->with('test_key')
            ->willReturn(false);

        $result = $subject->get('test_key');

        self::assertNull($result);
    }

    #[Test]
    public function getReturnsCachedData(): void
    {
        $cachedData = ['content' => 'cached response'];

        $this->cacheFrontendStub
            ->method('has')
            ->willReturn(true);

        $this->cacheFrontendStub
            ->method('get')
            ->willReturn($cachedData);

        $result = $this->subject->get('test_key');

        self::assertEquals($cachedData, $result);
    }

    #[Test]
    public function setStoresDataWithDefaultTags(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $data = ['content' => 'test'];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('set')
            ->with(
                'test_key',
                $data,
                self::callback(
                    fn(array $tags)
                    => in_array('nrllm', $tags, true)
                    && in_array('nrllm_response', $tags, true),
                ),
                3600,
            );

        $subject->set('test_key', $data, 3600);
    }

    #[Test]
    public function setMergesCustomTags(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $data = ['content' => 'test'];
        $customTags = ['custom_tag'];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('set')
            ->with(
                'test_key',
                $data,
                self::callback(
                    fn(array $tags)
                    => in_array('nrllm', $tags, true)
                    && in_array('nrllm_response', $tags, true)
                    && in_array('custom_tag', $tags, true),
                ),
                3600,
            );

        $subject->set('test_key', $data, 3600, $customTags);
    }

    #[Test]
    public function hasReturnsCacheFrontendResult(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('has')
            ->with('test_key')
            ->willReturn(true);

        self::assertTrue($subject->has('test_key'));
    }

    #[Test]
    public function removeCallsCacheFrontend(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('remove')
            ->with('test_key');

        $subject->remove('test_key');
    }

    #[Test]
    public function flushCallsCacheFrontend(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('flush');

        $subject->flush();
    }

    #[Test]
    public function flushByTagCallsCacheFrontend(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('flushByTag')
            ->with('test_tag');

        $subject->flushByTag('test_tag');
    }

    #[Test]
    public function flushByProviderCallsFlushByTagWithProviderTag(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('flushByTag')
            ->with('nrllm_provider_openai');

        $subject->flushByProvider('openai');
    }

    #[Test]
    public function cacheCompletionStoresAndReturnsCacheKey(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = ['temperature' => 0.7];
        $response = ['content' => 'Hi there'];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::isString(),
                $response,
                self::callback(
                    fn(array $tags)
                    => in_array('nrllm_completion', $tags, true)
                    && in_array('nrllm_provider_openai', $tags, true),
                ),
                3600,
            );

        $cacheKey = $subject->cacheCompletion('openai', $messages, $options, $response);

        self::assertStringStartsWith('openai_completion_', $cacheKey);
    }

    #[Test]
    public function cacheCompletionIncludesModelTag(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = ['model' => 'gpt-4o'];
        $response = ['content' => 'Hi'];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(
                    fn(array $tags)
                    => in_array('nrllm_model_gpt_4o', $tags, true),
                ),
                self::anything(),
            );

        $subject->cacheCompletion('openai', $messages, $options, $response);
    }

    #[Test]
    public function getCachedCompletionReturnsNullWhenNotCached(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = [];

        $this->cacheFrontendStub
            ->method('has')
            ->willReturn(false);

        $result = $this->subject->getCachedCompletion('openai', $messages, $options);

        self::assertNull($result);
    }

    #[Test]
    public function getCachedCompletionReturnsCachedResponse(): void
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = [];
        $cachedResponse = ['content' => 'cached'];

        $this->cacheFrontendStub
            ->method('has')
            ->willReturn(true);

        $this->cacheFrontendStub
            ->method('get')
            ->willReturn($cachedResponse);

        $result = $this->subject->getCachedCompletion('openai', $messages, $options);

        self::assertEquals($cachedResponse, $result);
    }

    #[Test]
    public function cacheEmbeddingsUsesLongerDefaultLifetime(): void
    {
        /** @var array{subject: CacheManager, cacheFrontend: FrontendInterface&MockObject} $setup */
        $setup = $this->createSubjectWithMockFrontend();
        $subject = $setup['subject'];
        $cacheFrontendMock = $setup['cacheFrontend'];

        $input = 'test text';
        $options = [];
        $response = ['embeddings' => [[0.1, 0.2, 0.3]]];

        $cacheFrontendMock
            ->expects(self::once())
            ->method('set')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                86400, // 24 hours
            );

        $subject->cacheEmbeddings('openai', $input, $options, $response);
    }

    #[Test]
    public function getCachedEmbeddingsWorksWithArrayInput(): void
    {
        $input = ['text1', 'text2'];
        $options = [];

        $this->cacheFrontendStub
            ->method('has')
            ->willReturn(false);

        $result = $this->subject->getCachedEmbeddings('openai', $input, $options);

        self::assertNull($result);
    }
}
