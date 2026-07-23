<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\CheckTypoScriptTool;
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
 * CheckTypoScriptTool on a site WITHOUT any sys_template row whose
 * TypoScript comes from the site itself (`setup.typoscript` beside the
 * config — the v13+ site-set mechanism). The scan must include that source
 * instead of reporting "no TypoScript template" (ADR-046).
 */
#[CoversClass(CheckTypoScriptTool::class)]
final class CheckTypoScriptToolSiteSetTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $siteDir = $this->instancePath . '/typo3conf/sites/sitets';
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
        // Site-local setup with an unbalanced brace — and NO sys_template row.
        file_put_contents($siteDir . '/setup.typoscript', "page = PAGE\npage {\n  10 = TEXT\n");

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $pages = $connectionPool->getConnectionForTable('pages');
        self::assertInstanceOf(Connection::class, $pages);
        $pages->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1,
            'sorting' => 1, 'is_siteroot' => 1, 'slug' => '/',
        ]);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('check_typoscript');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function scansSiteProvidedTypoScriptWithoutSysTemplateRow(): void
    {
        $output = $this->tool->execute(['pageUid' => 1], ToolExecutionContext::none())->content;

        self::assertStringContainsString('TypoScript syntax errors on page 1', $output);
        self::assertStringContainsString('missing closing "}"', $output);
    }
}
