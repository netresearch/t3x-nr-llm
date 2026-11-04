<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\ClearCacheTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * `clear_cache` against the real cache manager and core's own clear-cache
 * command (ADR-257): a group empties exactly its caches, a tag exactly its
 * entries, a full flush says so on the card before it happens, and core's
 * TSconfig rule and the admin requirement hold.
 */
#[CoversClass(ClearCacheTool::class)]
final class ClearCacheToolTest extends AbstractFunctionalTestCase
{
    use AssertsGermanPreviewTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    private const RESTRICTED_ADMIN = 3;

    private const PAGE_CACHE = 'nrllm_test_pages';

    private ClearCacheTool $tool;

    private CacheManager $cacheManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid'      => self::RESTRICTED_ADMIN, 'pid' => 0, 'username' => 'cautious', 'admin' => 1,
            'TSconfig' => 'options.clearCache.all = 0',
        ]);

        $cacheManager = $this->get(CacheManager::class);
        self::assertInstanceOf(CacheManager::class, $cacheManager);
        $this->cacheManager = $cacheManager;

        // The test instance's own page caches have a null backend, on which
        // every "the entry is gone" holds before any flush. A memory cache in
        // the same group is emptied by the same group and tag flushes.
        if (!$cacheManager->hasCache(self::PAGE_CACHE)) {
            // TYPO3 13.4 takes an application context first, 14 only options.
            $constructor = (new ReflectionClass(TransientMemoryBackend::class))->getConstructor();
            $backend     = (new ReflectionClass(TransientMemoryBackend::class))->newInstanceArgs(
                ($constructor?->getParameters()[0] ?? null)?->getName() === 'context' ? ['Testing'] : [],
            );
            self::assertInstanceOf(TransientMemoryBackend::class, $backend);
            $cacheManager->registerCache(new VariableFrontend(self::PAGE_CACHE, $backend), ['pages']);
        }

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->tool = new ClearCacheTool($cacheManager, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)));
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function thePagesGroupEmptiesThePageCachesAndLeavesTheOthers(): void
    {
        $this->pages()->set('entry', 'page html', ['pageId_7']);
        $this->runtimeOutsideTheGroup()->set('kept', 'value');
        self::assertTrue($this->pages()->has('entry'), 'the control: the page cache keeps an entry');

        $result = $this->tool->execute(['group' => 'pages'], $this->admin());

        self::assertFalse($result->isError, $result->content);
        self::assertFalse($this->pages()->has('entry'));
        self::assertTrue($this->runtimeOutsideTheGroup()->has('kept'));
        self::assertNull($result->writeTarget);
    }

    #[Test]
    public function aTagEmptiesOnlyTheEntriesItTags(): void
    {
        $this->pages()->set('seven', 'page 7', ['pageId_7']);
        $this->pages()->set('eight', 'page 8', ['pageId_8']);

        $result = $this->tool->execute(['tags' => ['pageId_7']], $this->admin());

        self::assertFalse($result->isError, $result->content);
        self::assertFalse($this->pages()->has('seven'));
        self::assertTrue($this->pages()->has('eight'));
    }

    #[Test]
    public function anExtensionGroupIsFlushedThroughTheCacheManager(): void
    {
        $responses = $this->cacheManager->getCache('nrllm_responses');
        $responses->set('answer', 'cached');
        $this->pages()->set('entry', 'page html');

        $result = $this->tool->execute(['group' => 'nrllm'], $this->admin());

        self::assertFalse($result->isError, $result->content);
        self::assertFalse($responses->has('answer'));
        self::assertTrue($this->pages()->has('entry'));
    }

    #[Test]
    public function theGroupPreviewNamesTheCachesItEmptiesAndEmptiesNothing(): void
    {
        $this->pages()->set('entry', 'page html');

        $preview = $this->tool->previewCall(['group' => 'nrllm'], $this->admin());

        self::assertStartsWithHeading($preview, 'default');
        self::assertSame([
            'Clear a cache group',
            'Cache group: nrllm',
            'Caches that are emptied: nrllm_circuit, nrllm_health, nrllm_idempotency, nrllm_reachability, nrllm_responses',
            'The caches fill again on the next requests, which are slower until then.',
        ], $preview);
        self::assertTrue($this->pages()->has('entry'));
    }

    #[Test]
    public function aFullFlushSaysSoInItsHeadingAndInALineOfItsOwn(): void
    {
        $preview = $this->tool->previewCall(['group' => 'all'], $this->admin());

        self::assertStartsWithHeading($preview, 'default');
        self::assertSame('Flush ALL caches', $preview[0]);
        self::assertStringStartsWith('Full flush: all ', $preview[1] ?? '');
        self::assertStringContainsString('including the system caches', $preview[1] ?? '');
    }

    #[Test]
    public function aFullFlushEmptiesEveryCache(): void
    {
        $this->pages()->set('entry', 'page html');
        $responses = $this->cacheManager->getCache('nrllm_responses');
        $responses->set('answer', 'cached');

        $result = $this->tool->execute(['group' => 'all'], $this->admin());

        self::assertFalse($result->isError, $result->content);
        self::assertStringStartsWith('Flushed ALL caches', $result->content);
        self::assertFalse($this->pages()->has('entry'));
        self::assertFalse($responses->has('answer'));
    }

    #[Test]
    public function anAdministratorWhomTsconfigKeepsFromFlushingEverythingIsRefused(): void
    {
        $this->pages()->set('entry', 'page html');
        $context = ToolExecutionContext::fromBackendUser($this->setUpBackendUser(self::RESTRICTED_ADMIN));

        $preview = $this->tool->previewCall(['group' => 'all'], $context);
        $result  = $this->tool->execute(['group' => 'all'], $context);
        $pages   = $this->tool->execute(['group' => 'pages'], $context);

        self::assertStringContainsString('options.clearCache.all = 0', $preview[0]);
        self::assertTrue($result->isError);
        self::assertFalse($pages->isError, $pages->content);
    }

    #[Test]
    public function aNonAdministratorClearsNothing(): void
    {
        $this->pages()->set('entry', 'page html');
        $editor  = $this->setUpBackendUser(2);
        $context = ToolExecutionContext::fromBackendUser($editor);

        $result = $this->tool->execute(['group' => 'pages'], $context);

        self::assertTrue($result->isError);
        self::assertTrue($this->pages()->has('entry'));
        self::assertFalse($this->tool->mayViewerReadPreview(['group' => 'pages'], $editor));
    }

    #[Test]
    public function anUnknownGroupABadTagAndBothArgumentsAreRefused(): void
    {
        self::assertStringContainsString('is not a cache group', $this->tool->execute(['group' => 'nope'], $this->admin())->content);
        self::assertStringContainsString('a cache tag holds only', $this->tool->execute(['tags' => ['page id 7']], $this->admin())->content);
        self::assertStringContainsString('either "group" or "tags"', $this->tool->execute(['group' => 'pages', 'tags' => ['pageId_7']], $this->admin())->content);
        self::assertStringContainsString('either "group" or "tags"', $this->tool->execute([], $this->admin())->content);
    }

    #[Test]
    public function theTagPreviewIsInTheActingUsersLanguage(): void
    {
        $admin               = $this->setUpBackendUser(1);
        $admin->user['lang'] = 'de';

        $german = $this->tool->previewCall(['tags' => ['pageId_8', 'pageId_7']], ToolExecutionContext::fromBackendUser($admin));

        self::assertSame('Seiten-Caches nach Tag leeren', $german[0]);
        self::assertSame('Einträge mit den Tags: pageId_7, pageId_8', $german[1]);
        self::assertStartsWithHeading($german, 'de');
        self::assertGermanLines($german, ['pageId_7', 'pageId_8']);
    }

    private function admin(): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1));
    }

    private function pages(): FrontendInterface
    {
        return $this->cacheManager->getCache(self::PAGE_CACHE);
    }

    /**
     * A persistent cache in no group but `system`, to see that a pages flush leaves it alone.
     */
    private function runtimeOutsideTheGroup(): FrontendInterface
    {
        return $this->cacheManager->getCache('nrllm_responses');
    }
}
