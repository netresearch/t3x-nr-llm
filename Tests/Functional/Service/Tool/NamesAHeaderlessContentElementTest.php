<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CopyRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\MoveContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\PublishRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReplaceFileReferenceTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileReferenceAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateContentElementTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * An approval card names a content element without a header by its type and
 * UID. "Content element: (empty)" read as "the element is empty" (#1325).
 *
 * Fixture: `HeaderlessContentElement.csv` — element 10, Text & Media, hidden,
 * no header, one image (reference 40), on page 1.
 */
#[CoversClass(ApprovalPreviewTranslator::class)]
#[CoversClass(ReplaceFileReferenceTool::class)]
#[CoversClass(SetFileReferenceAlternativeTextTool::class)]
#[CoversClass(AttachFileToContentElementTool::class)]
#[CoversClass(UpdateContentElementTool::class)]
#[CoversClass(MoveContentElementTool::class)]
#[CoversClass(CopyRecordTool::class)]
#[CoversClass(PublishRecordTool::class)]
final class NamesAHeaderlessContentElementTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = ['netresearch/nr-vault', 'netresearch/nr-llm'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->importFixture('HeaderlessContentElement.csv');

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin');
        file_put_contents($this->instancePath . '/fileadmin/one.jpg', 'first');
        file_put_contents($this->instancePath . '/fileadmin/two.jpg', 'second');

        $siteDir = $this->instancePath . '/typo3conf/sites/headerless';
        GeneralUtility::mkdir_deep($siteDir);
        file_put_contents($siteDir . '/config.yaml', <<<YAML
            rootPageId: 1
            base: 'http://localhost:59998/'
            languages:
              - languageId: 0
                title: English
                locale: en_US.UTF-8
                base: /
              - languageId: 1
                title: Deutsch
                locale: de_DE.UTF-8
                base: /de/
            YAML);
        $this->getService(SiteConfiguration::class)->resolveAllExistingSites(false);
        $this->getService(CacheManager::class)->getCache('runtime')->remove('sites-root-id-to-identifier');

        $GLOBALS['LANG']          = $this->getService(LanguageServiceFactory::class)->create('default');
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /**
     * Every writer whose card names the content element it acts on;
     * `create_translation_draft` needs a translator and is asked in its own
     * test class.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function cards(): array
    {
        return [
            'replace_file_reference'              => ['replace_file_reference', ['reference' => 40, 'action' => 'remove']],
            'set_file_reference_alternative_text' => ['set_file_reference_alternative_text', ['reference' => 40, 'alternative' => 'A text']],
            'attach_file_to_content_element'      => ['attach_file_to_content_element', ['content_element' => 10, 'file' => 2, 'field' => 'assets']],
            'update_content_element'              => ['update_content_element', ['uid' => 10, 'fields' => ['bodytext' => 'Another text']]],
            'move_content_element'                => ['move_content_element', ['uid' => 10, 'target_page' => 2]],
            'copy_record'                         => ['copy_record', ['table' => 'tt_content', 'uid' => 10, 'target_page' => 2]],
            'publish_record'                      => ['publish_record', ['table' => 'tt_content', 'uid' => 10]],
        ];
    }

    /**
     * @param array<string, mixed> $call
     */
    #[Test]
    #[DataProvider('cards')]
    public function theCardNamesAContentElementWithoutAHeaderByTypeAndUid(string $name, array $call): void
    {
        $tool = $this->tool($name);
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);
        $context = ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1));

        $card = implode("\n", $tool->previewCall($call, $context));

        self::assertStringContainsString('Content element: no header, type “Text & Media”, UID 10', $card);
        self::assertStringNotContainsString('Content element: (empty)', $card);
    }

    /**
     * The German card, as the editor of the live check read it. The type's
     * name is core's label, English here without a German language pack.
     */
    #[Test]
    public function theGermanCardNamesItToo(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['lang' => 'de'], ['uid' => 1]);
        $tool = $this->tool('replace_file_reference');
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);

        $card = implode("\n", $tool->previewCall(['reference' => 40, 'action' => 'remove'], ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1))));

        self::assertStringContainsString('Inhaltselement: ohne Überschrift, Typ „Text & Media“, UID 10', $card);
        self::assertStringNotContainsString('Inhaltselement: (leer)', $card);
    }

    private function tool(string $name): ToolInterface
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get($name);
        self::assertInstanceOf(ToolInterface::class, $tool);
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        (new ToolStateRepository($pool))->setEnabled($name, true);

        return $tool;
    }
}
