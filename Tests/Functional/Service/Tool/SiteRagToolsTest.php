<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\SiteFetchSourceTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SiteRagQueryTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for the rag tool group (ADR-049): both tools are
 * discovered through the registry, retrieve evidence end-to-end through
 * the cascade (database fallback in this instance — no search extension
 * loaded), round-trip source ids, and fail closed without a backend
 * user.
 */
#[CoversClass(SiteRagQueryTool::class)]
#[CoversClass(SiteFetchSourceTool::class)]
final class SiteRagToolsTest extends AbstractFunctionalTestCase
{
    private ToolInterface $queryTool;

    private ToolInterface $fetchTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $siteDir = $this->instancePath . '/typo3conf/sites/main';
        GeneralUtility::mkdir_deep($siteDir);
        file_put_contents($siteDir . '/config.yaml', <<<YAML
            rootPageId: 1
            base: 'http://localhost:59999/'
            languages:
              - languageId: 0
                title: English
                locale: en_US.UTF-8
                base: /
            YAML);

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $pages = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1,
            'sorting' => 1, 'is_siteroot' => 1, 'slug' => '/',
        ]);
        $pages->insert('pages', [
            'uid' => 2, 'pid' => 1, 'title' => 'Aikido Migration Services', 'doktype' => 1,
            'sorting' => 2, 'slug' => '/migration',
        ]);
        $content = $connectionPool->getConnectionForTable('tt_content');
        self::assertInstanceOf(Connection::class, $content);
        $content->insert('tt_content', [
            'uid' => 10, 'pid' => 2, 'colPos' => 0, 'sorting' => 1, 'CType' => 'text',
            'header' => 'Our aikido offering', 'bodytext' => 'Aikido migrations done right.',
        ]);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $queryTool = $registry->get('site_rag_query');
        self::assertInstanceOf(ToolInterface::class, $queryTool);
        $this->queryTool = $queryTool;
        $fetchTool = $registry->get('site_fetch_source');
        self::assertInstanceOf(ToolInterface::class, $fetchTool);
        $this->fetchTool = $fetchTool;
    }

    private function contextForUser(BackendUserAuthentication $user): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($user);
    }

    #[Test]
    public function toolsAreRegisteredInTheRagGroupAndEnabledByDefault(): void
    {
        self::assertSame('rag', $this->queryTool->getGroup());
        self::assertSame('rag', $this->fetchTool->getGroup());
        self::assertTrue($this->queryTool->isEnabledByDefault());
        self::assertTrue($this->fetchTool->isEnabledByDefault());
        self::assertFalse($this->queryTool->requiresAdmin());
        self::assertFalse($this->fetchTool->requiresAdmin());
    }

    #[Test]
    public function ragQueryReturnsCitedEvidenceThroughTheDatabaseFallback(): void
    {
        $context = $this->contextForUser($this->setUpBackendUser(1));

        $output = $this->queryTool->execute(['question' => 'aikido'], $context)->content;

        self::assertStringContainsString('backend: database', $output);
        self::assertStringContainsString('database:2:0', $output);
        self::assertStringContainsString('Aikido Migration Services', $output);
        self::assertStringContainsString('http://localhost:59999/migration', $output);
        self::assertStringContainsString('site_fetch_source', $output);
    }

    #[Test]
    public function fetchSourceRoundTripsAQueryResultSourceId(): void
    {
        $context = $this->contextForUser($this->setUpBackendUser(1));

        $output = $this->fetchTool->execute(['source_id' => 'database:2:0'], $context)->content;

        self::assertStringContainsString('# Aikido Migration Services', $output);
        self::assertStringContainsString('Aikido migrations done right.', $output);
    }

    #[Test]
    public function modelChosenArgumentsAreValidatedNotTrusted(): void
    {
        $context = $this->contextForUser($this->setUpBackendUser(1));

        self::assertSame('Question too short (minimum 2 characters).', $this->queryTool->execute(['question' => 'x'], $context)->content);
        self::assertSame('Invalid source_id.', $this->fetchTool->execute(['source_id' => "db:1' OR '1"], $context)->content);
        self::assertSame('Source not found or not permitted.', $this->fetchTool->execute(['source_id' => 'database:999:0'], $context)->content);
        self::assertSame(
            'No evidence found for "zzz-not-present" (backend: database).',
            $this->queryTool->execute(['question' => 'zzz-not-present'], $context)->content,
        );
    }

    #[Test]
    public function outOfRangeArgumentsAreClampedNeverThrown(): void
    {
        $context = $this->contextForUser($this->setUpBackendUser(1));

        // Each of these once mapped to a RetrievalQuery::create() guard —
        // the tool must clamp them, not surface an exception (temperature=5
        // bug class).
        $overCap = $this->queryTool->execute(['question' => 'aikido', 'max_sources' => 999], $context)->content;
        self::assertStringContainsString('database:2:0', $overCap);

        $zero = $this->queryTool->execute(['question' => 'aikido', 'max_sources' => 0], $context)->content;
        self::assertStringContainsString('database:2:0', $zero);

        $negativeLanguage = $this->queryTool->execute(['question' => 'aikido', 'language' => -3], $context)->content;
        self::assertStringContainsString('database:2:0', $negativeLanguage);

        // 350 chars in, truncated to 200 and answered cleanly — the echoed
        // question ends mid-word instead of raising a VO exception.
        $longQuestion = $this->queryTool->execute(['question' => str_repeat('aikido ', 50)], $context)->content;
        self::assertStringStartsWith('Evidence for "aikido ', $longQuestion);
        self::assertStringContainsString('aiki" (backend:', $longQuestion);
        self::assertStringContainsString('database:2:0', $longQuestion);
    }

    #[Test]
    public function fetchedSourceTextIsCappedAt8000Characters(): void
    {
        $context = $this->contextForUser($this->setUpBackendUser(1));

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $content = $connectionPool->getConnectionForTable('tt_content');
        self::assertInstanceOf(Connection::class, $content);
        $content->insert('tt_content', [
            'uid' => 11, 'pid' => 2, 'colPos' => 0, 'sorting' => 2, 'CType' => 'text',
            'header' => 'Long chapter', 'bodytext' => str_repeat('aikido wisdom ', 1000),
        ]);

        $output = $this->fetchTool->execute(['source_id' => 'database:2:0'], $context)->content;

        self::assertSame(8001, mb_strlen($output));
        self::assertStringEndsWith('…', $output);
    }

    #[Test]
    public function bothToolsFailClosedWithoutBackendUser(): void
    {
        self::assertSame('Not permitted.', $this->queryTool->execute(['question' => 'aikido'], ToolExecutionContext::none())->content);
        self::assertSame('Not permitted.', $this->fetchTool->execute(['source_id' => 'database:2:0'], ToolExecutionContext::none())->content);
    }
}
