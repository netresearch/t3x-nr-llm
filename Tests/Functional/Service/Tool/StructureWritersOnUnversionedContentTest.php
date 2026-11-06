<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\CacheManager;
use Netresearch\NrLlm\Service\Feature\TranslationPromptBuilder;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolver;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateTranslationDraftTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistryInterface;
use Netresearch\NrLlm\Tests\Fixtures\Translation\RecordingTranslator;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * An installation that takes the versioning off `tt_content` has no
 * `t3ver_*` columns there: the live path of `create_translation_draft` must
 * not name one (ADR-266).
 */
#[CoversClass(CreateTranslationDraftTool::class)]
final class StructureWritersOnUnversionedContentTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'typo3conf/ext/nr_llm/Tests/Functional/Fixtures/Extensions/nrllm_unversioned_content_fixture',
    ];

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        // TYPO3 13.4 cannot install tt_content without versioning: its
        // EXT:frontend ext_tables.sql declares an index over t3ver_oid
        // (13.4.37), which the schema then lacks. 14.3 no longer does.
        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::markTestSkipped('TYPO3 13.4 indexes tt_content.t3ver_oid in ext_tables.sql, so tt_content cannot go without versioning.');
        }

        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;

        $this->importFixture('BeUsers.csv');
        $pages   = $this->connectionPool->getConnectionForTable('pages');
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        self::assertArrayNotHasKey('t3ver_oid', $content->createSchemaManager()->listTableColumns('tt_content'), 'positive control: tt_content has no t3ver columns');
        foreach ([[1, 0, 'Root', 1], [2, 1, 'A', 1], [3, 1, 'B', 2], [4, 2, 'A sub', 1]] as [$uid, $pid, $title, $sorting]) {
            $pages->insert('pages', [
                'uid'          => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/p' . $uid, 'doktype' => 1, 'sorting' => $sorting * 256,
                'is_siteroot'  => $uid === 1 ? 1 : 0,
                'perms_userid' => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL,
            ]);
        }

        $content
            ->insert('tt_content', ['uid' => 10, 'pid' => 2, 'header' => 'E10', 'CType' => 'text', 'colPos' => 0, 'sorting' => 256]);

        $siteWriter = $this->get(SiteWriter::class);
        self::assertInstanceOf(SiteWriter::class, $siteWriter);
        $siteWriter->write('testing', [
            'rootPageId' => 1,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'English', 'base' => '/', 'locale' => 'en_US.UTF-8', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'German', 'base' => '/de/', 'locale' => 'de_DE.UTF-8', 'flag' => 'de'],
            ],
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function anElementIsTranslatedInLive(): void
    {
        $result = $this->translationTool()->execute(
            ['table' => 'tt_content', 'uid' => 10, 'language' => 1],
            ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('New translation uid:', $result->content);
    }

    private function translationTool(): CreateTranslationDraftTool
    {
        $translators = ['llm' => new RecordingTranslator('llm', 'Language model')];
        $registry    = self::createStub(TranslatorRegistryInterface::class);
        $registry->method('get')->willReturnCallback(
            static fn (string $identifier): TranslatorInterface => $translators[$identifier]
                ?? throw new ServiceUnavailableException('No translator ' . $identifier, 'translation'),
        );
        $registry->method('has')->willReturnCallback(static fn (string $identifier): bool => isset($translators[$identifier]));

        $typo3Caches = new Typo3CacheManager();
        $typo3Caches->setCacheConfigurations([
            'nrllm_responses' => ['frontend' => VariableFrontend::class, 'backend' => TransientMemoryBackend::class, 'options' => [], 'groups' => []],
        ]);

        $siteFinder = $this->get(SiteFinder::class);
        self::assertInstanceOf(SiteFinder::class, $siteFinder);

        return new CreateTranslationDraftTool(
            $this->connectionPool,
            new TranslationService(
                self::createStub(LlmServiceManagerInterface::class),
                $registry,
                self::createStub(LlmConfigurationServiceInterface::class),
                new TranslationPromptBuilder(),
                null,
                new GlossaryResolver($this->connectionPool),
                null,
                new CacheManager($typo3Caches),
            ),
            $siteFinder,
            new GlossaryResolver($this->connectionPool),
            new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
        );
    }
}
