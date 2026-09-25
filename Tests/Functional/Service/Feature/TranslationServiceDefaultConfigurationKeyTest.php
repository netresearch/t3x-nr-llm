<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Feature;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\TranslationOptions;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Tests\Fixtures\Translation\RecordingTranslator;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The LLM translator's cache key follows the default configuration as the
 * DATABASE holds it (ADR-209): its last change comes from `tstamp`, which the
 * DataMapper only hydrates because the TCA declares the column. A unit test
 * setting the property by hand cannot tell; this one saves the record through
 * the DataHandler and reads it back through the repository.
 */
#[CoversClass(TranslationService::class)]
final class TranslationServiceDefaultConfigurationKeyTest extends AbstractFunctionalTestCase
{
    /** The fixture's tstamp of the default configuration. */
    private const FIXTURE_TSTAMP = 1703347200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
        $this->importFixture('LlmConfigurations.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theStoredLastChangeOfTheDefaultConfigurationIsHydrated(): void
    {
        self::assertSame(self::FIXTURE_TSTAMP, $this->defaultConfiguration()->getTstamp());

        $this->saveTheDefaultConfiguration();

        self::assertGreaterThan(self::FIXTURE_TSTAMP, $this->defaultConfiguration()->getTstamp());
    }

    /**
     * Saving the default configuration is a new key, even with the flush of
     * the container's cache out of the picture: this cache is a private one
     * the flush hook does not reach.
     */
    #[Test]
    public function savingTheDefaultConfigurationIsANewKey(): void
    {
        $translator = new RecordingTranslator('llm', 'Language model');
        $registry   = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturn($translator);

        $typo3Caches = new Typo3CacheManager();
        $typo3Caches->setCacheConfigurations([
            'nrllm_responses' => ['frontend' => VariableFrontend::class, 'backend' => TransientMemoryBackend::class, 'options' => [], 'groups' => []],
        ]);

        $repository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $repository);

        $service = new TranslationService(
            self::createStub(LlmServiceManagerInterface::class),
            $registry,
            self::createStub(LlmConfigurationServiceInterface::class),
            new TranslationPromptBuilder(),
            null,
            null,
            null,
            new CacheManager($typo3Caches),
            new ConfigurationResolver($repository),
        );
        $options = (new TranslationOptions())->withTranslator('llm')->withCacheTtl(60);

        $service->translateWithTranslator('Hello world', 'de', 'en', $options);
        $service->translateWithTranslator('Hello world', 'de', 'en', $options);
        self::assertCount(1, $translator->calls, 'the control: an unchanged default is one entry');

        $this->saveTheDefaultConfiguration();
        $service->translateWithTranslator('Hello world', 'de', 'en', $options);

        self::assertCount(2, $translator->calls);
    }

    private function saveTheDefaultConfiguration(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tx_nrllm_configuration' => [1 => ['name' => 'Renamed default']]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler reported: ' . var_export($dataHandler->errorLog, true));

        // A fresh request reads the record anew; the identity map of this
        // process would otherwise hand back the object read before the save.
        $persistence = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistence);
        $persistence->clearState();
    }

    private function defaultConfiguration(): LlmConfiguration
    {
        $repository = $this->get(LlmConfigurationRepository::class);
        self::assertInstanceOf(LlmConfigurationRepository::class, $repository);
        $default = $repository->findDefault();
        self::assertInstanceOf(LlmConfiguration::class, $default);

        return $default;
    }
}
