<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\CheckTypoScriptTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for CheckTypoScriptTool (ADR-046): the core syntax
 * scanner runs over the page's real sys_template chain — unbalanced braces
 * are reported with a line number and never with the line's content; a
 * clean template gets a clean bill.
 */
#[CoversClass(CheckTypoScriptTool::class)]
final class CheckTypoScriptToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    private Connection $templates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $siteDir = $this->instancePath . '/typo3conf/sites/tscheck';
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

        $templates = $connectionPool->getConnectionForTable('sys_template');
        self::assertInstanceOf(Connection::class, $templates);
        $this->templates = $templates;

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('check_typoscript');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function reportsUnbalancedBraceWithLineNumberButWithoutContent(): void
    {
        $this->templates->insert('sys_template', [
            'uid' => 10, 'pid' => 1, 'title' => 'Broken', 'root' => 1, 'clear' => 3,
            'config' => "page = PAGE\npage {\n  10 = TEXT\n  10.value = secret-value-99\n",
        ]);

        $output = $this->tool->execute(['pageUid' => 1]);

        self::assertStringContainsString('TypoScript syntax errors on page 1', $output);
        self::assertStringContainsString('missing closing "}"', $output);
        self::assertStringContainsString('[setup]', $output);
        self::assertMatchesRegularExpression('/line \d+:/', $output);
        // The offending line's content must never egress.
        self::assertStringNotContainsString('secret-value-99', $output);
    }

    #[Test]
    public function cleanTemplateReportsNoErrors(): void
    {
        $this->templates->insert('sys_template', [
            'uid' => 11, 'pid' => 1, 'title' => 'Clean', 'root' => 1, 'clear' => 3,
            'config' => "page = PAGE\npage.10 = TEXT\npage.10.value = Hello\n",
        ]);

        $output = $this->tool->execute(['pageUid' => 1]);

        self::assertStringContainsString('No TypoScript syntax errors on page 1', $output);
    }

    #[Test]
    public function missingTemplateYieldsNeutralAnswer(): void
    {
        self::assertSame(
            'Page not found or no TypoScript template.',
            $this->tool->execute(['pageUid' => 1]),
        );
        self::assertSame(
            'Page not found or no TypoScript template.',
            $this->tool->execute(['pageUid' => 12345]),
        );
    }
}
