<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Hook;

use Netresearch\NrLlm\Hook\TranslationCacheFlushHook;
use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Saving or deleting a record that decides what a translation says — a
 * glossary, or the default configuration, its model, provider, skills and
 * snippets — flushes the cached translations (ADR-209), through the hook
 * registered in ext_localconf.php and the real DataHandler. The cache is an in-memory one put in place of the container's
 * service, so an entry demonstrably exists before the write.
 */
#[CoversClass(TranslationCacheFlushHook::class)]
final class TranslationCacheFlushHookTest extends AbstractFunctionalTestCase
{
    private const KEY = 'llm_translation_test';

    private CacheManager $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $typo3Caches = new Typo3CacheManager();
        $typo3Caches->setCacheConfigurations([
            'nrllm_responses' => ['frontend' => VariableFrontend::class, 'backend' => TransientMemoryBackend::class, 'options' => [], 'groups' => []],
        ]);
        $this->cache = new CacheManager($typo3Caches);
        // The hook asks makeInstance(), which answers a registered singleton
        // before the container. Removed again in tearDown.
        GeneralUtility::setSingletonInstance(CacheManagerInterface::class, $this->cache);

        $this->cache->set(self::KEY, ['translatedText' => 'cached'], 3600, [TranslationService::CACHE_TAG]);
        self::assertNotNull($this->cache->get(self::KEY), 'the control: the entry exists before the write');
    }

    protected function tearDown(): void
    {
        GeneralUtility::removeSingletonInstance(CacheManagerInterface::class, $this->cache);
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function savingAGlossaryFlushesTheCachedTranslations(): void
    {
        $this->write(['tx_nrllm_glossary' => ['NEW1' => [
            'pid' => 0, 'name' => 'Terms', 'site_identifier' => 'main',
            'source_language' => 'en', 'target_language' => 'de', 'entries' => 'cart = Warenkorb',
        ]]], []);

        self::assertNull($this->cache->get(self::KEY));
    }

    #[Test]
    public function deletingAGlossaryFlushesTheCachedTranslations(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_glossary')->insert('tx_nrllm_glossary', [
            'uid' => 5, 'pid' => 0, 'name' => 'Terms', 'site_identifier' => 'main',
            'source_language' => 'en', 'target_language' => 'de', 'entries' => 'cart = Warenkorb',
        ]);

        $this->write([], ['tx_nrllm_glossary' => [5 => ['delete' => 1]]]);

        self::assertNull($this->cache->get(self::KEY));
    }

    #[Test]
    public function savingAConfigurationFlushesTheCachedTranslations(): void
    {
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');

        $this->write(['tx_nrllm_configuration' => [1 => ['name' => 'Renamed default']]], []);

        self::assertNull($this->cache->get(self::KEY));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function tablesThatChangeATranslation(): iterable
    {
        yield 'configuration' => ['tx_nrllm_configuration'];
        yield 'model' => ['tx_nrllm_model'];
        yield 'provider' => ['tx_nrllm_provider'];
        yield 'skill' => ['tx_nrllm_skill'];
        yield 'prompt snippet' => ['tx_nrllm_promptsnippet'];
    }

    #[Test]
    #[DataProvider('tablesThatChangeATranslation')]
    public function deletingARecordTheTranslationReadsFlushesTheCache(string $table): void
    {
        $this->getConnectionPool()->getConnectionForTable($table)->insert($table, ['uid' => 7, 'pid' => 0]);

        $this->write([], [$table => [7 => ['delete' => 1]]]);

        self::assertNull($this->cache->get(self::KEY));
    }

    #[Test]
    public function aWriteToAnotherTableLeavesTheCachedTranslations(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_task')->insert('tx_nrllm_task', ['uid' => 7, 'pid' => 0]);

        $this->write([], ['tx_nrllm_task' => [7 => ['delete' => 1]]]);

        self::assertNotNull($this->cache->get(self::KEY));
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $data
     * @param array<string, array<int|string, array<string, mixed>>> $commands
     */
    private function write(array $data, array $commands): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler reported: ' . var_export($dataHandler->errorLog, true));
    }
}
