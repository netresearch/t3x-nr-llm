<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ResolveUrlTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for ResolveUrlTool (ADR-046): own-site URLs resolve to
 * their page via the real SiteMatcher/PageRouter, foreign hosts and unknown
 * slugs are reported, and the tool fails closed without a backend user.
 */
#[CoversClass(ResolveUrlTool::class)]
final class ResolveUrlToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $siteDir = $this->instancePath . '/typo3conf/sites/resolver';
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
            'uid' => 2, 'pid' => 1, 'title' => 'Imprint', 'doktype' => 1,
            'sorting' => 2, 'slug' => '/imprint',
        ]);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('resolve_url');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function resolvesRelativePathToPage(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['url' => '/imprint']);

        self::assertStringContainsString('Site: resolver (base http://localhost:59999/)', $output);
        self::assertStringContainsString('Page: [2] Imprint', $output);
        self::assertStringContainsString('slug /imprint', $output);
        self::assertStringContainsString('Use get_page_content(uid) for the content.', $output);
    }

    #[Test]
    public function resolvesAbsoluteUrlToRootPage(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['url' => 'http://localhost:59999/']);

        self::assertStringContainsString('Page: [1] Home', $output);
        self::assertStringContainsString('Language: 0', $output);
    }

    #[Test]
    public function resolvesSchemelessPathWithoutLeadingSlash(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['url' => 'imprint']);

        self::assertStringContainsString('Page: [2] Imprint', $output);
    }

    #[Test]
    public function rejectsProtocolRelativeUrl(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(
            'Not a URL of this instance (no site matches).',
            $this->tool->execute(['url' => '//evil.example.com/x']),
        );
    }

    #[Test]
    public function reportsForeignHostNeutrally(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(
            'Not a URL of this instance (no site matches).',
            $this->tool->execute(['url' => 'https://evil.example.com/x']),
        );
    }

    #[Test]
    public function reportsUnknownSlugAsNoRoute(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['url' => '/nope']);

        self::assertStringContainsString('No page route matches "/nope" on site "resolver"', $output);
    }

    #[Test]
    public function deniesWithoutBackendUser(): void
    {
        self::assertSame(
            'Page not found or not permitted.',
            $this->tool->execute(['url' => '/imprint']),
        );
    }
}
