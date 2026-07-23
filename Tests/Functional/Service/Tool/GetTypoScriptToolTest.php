<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetTypoScriptTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for GetTypoScriptTool (ADR-042).
 *
 * Resolves real TypoScript through the core APIs against a seeded site
 * configuration and sys_template row. Load-bearing: the dotted path drills
 * into the setup subtree, constants resolve flat, credential-ish values are
 * redacted, and a page without a site/template yields the neutral string.
 */
#[CoversClass(GetTypoScriptTool::class)]
final class GetTypoScriptToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        // A minimal site configuration so SiteFinder can map page 1.
        $siteDir = $this->instancePath . '/typo3conf/sites/testing';
        GeneralUtility::mkdir_deep($siteDir);
        file_put_contents($siteDir . '/config.yaml', <<<YAML
            rootPageId: 1
            base: 'http://localhost/'
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
        $templates->insert('sys_template', [
            'uid' => 1, 'pid' => 1, 'title' => 'Root template', 'root' => 1, 'clear' => 3,
            'config'    => "page = PAGE\ndemo.endpoint = https://api.example.org\ndemo.apiKey = setup-secret",
            'constants' => "myConst.plain = visible-value\nmyConst.apiKey = constant-secret",
        ]);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('get_typoscript');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function setupTopLevelKeysListWithoutPath(): void
    {
        $output = $this->tool->execute(['pageUid' => 1], ToolExecutionContext::none())->content;

        self::assertStringContainsString('TypoScript setup for page 1', $output);
        self::assertStringContainsString('page = PAGE', $output);
        self::assertStringContainsString('demo (+', $output);
    }

    #[Test]
    public function pathDrillsIntoSetupSubtreeAndRedactsSecrets(): void
    {
        $output = $this->tool->execute(['pageUid' => 1, 'path' => 'demo'], ToolExecutionContext::none())->content;

        self::assertStringContainsString('endpoint = https://api.example.org', $output);
        self::assertStringContainsString('apiKey = [redacted]', $output);
        self::assertStringNotContainsString('setup-secret', $output);
    }

    #[Test]
    public function constantsResolveFlatAndRedactSecrets(): void
    {
        $output = $this->tool->execute(['pageUid' => 1, 'type' => 'constants'], ToolExecutionContext::none())->content;

        self::assertStringContainsString('myConst.plain = visible-value', $output);
        self::assertStringContainsString('myConst.apiKey = [redacted]', $output);
        self::assertStringNotContainsString('constant-secret', $output);
    }

    #[Test]
    public function pageWithoutSiteReturnsNeutralString(): void
    {
        $output = $this->tool->execute(['pageUid' => 999], ToolExecutionContext::none())->content;

        self::assertSame('Page not found or no TypoScript template.', $output);
    }
}
