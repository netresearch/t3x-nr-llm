<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ProbeUrlTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for the probe_url SSRF host gate (ADR-044).
 *
 * The gate resolves the instance's own sites through the real SiteFinder:
 * off-host URLs and non-http(s) schemes are denied WITHOUT a request being
 * attempted; own-host URLs pass the gate (evidenced by a transport-level
 * failure against a closed port — no web server runs in the test
 * instance).
 */
#[CoversClass(ProbeUrlTool::class)]
final class ProbeUrlToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        // Site on a closed localhost port: gate passes, connect fails fast.
        $siteDir = $this->instancePath . '/typo3conf/sites/probe';
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

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('probe_url');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function deniesForeignHostWithoutRequesting(): void
    {
        $output = $this->tool->execute(['url' => 'https://evil.example.com/steal']);

        self::assertStringContainsString('Denied', $output);
        self::assertStringContainsString('localhost:59999', $output);
    }

    #[Test]
    public function deniesNonHttpScheme(): void
    {
        self::assertStringContainsString('Denied', $this->tool->execute(['url' => 'file:///etc/passwd']));
        self::assertStringContainsString('Denied', $this->tool->execute(['url' => 'gopher://localhost:59999/x']));
    }

    #[Test]
    public function deniesRoguePortOnOwnHost(): void
    {
        // Same host, different port than the site (59999) — must NOT pass the
        // gate, or an attacker could probe localhost:6379 (Redis), :3306, …
        self::assertStringContainsString('Denied', $this->tool->execute(['url' => 'http://localhost:6379/']));
        // Bare host defaults to port 80, also != 59999.
        self::assertStringContainsString('Denied', $this->tool->execute(['url' => 'http://localhost/']));
    }

    #[Test]
    public function deniesUserinfoAndDoesNotEchoCredentials(): void
    {
        $output = $this->tool->execute(['url' => 'http://user:s3cr3t@localhost:59999/']);

        self::assertStringContainsString('Denied', $output);
        self::assertStringNotContainsString('s3cr3t', $output);
    }

    #[Test]
    public function ownHostPassesTheGate(): void
    {
        $output = $this->tool->execute(['url' => 'http://localhost:59999/some/page']);

        // The request is ATTEMPTED (gate passed) and fails at transport
        // level because nothing listens on the closed port.
        self::assertStringContainsString('FAILED transport-level', $output);
    }

    #[Test]
    public function relativePathResolvesAgainstTheFirstSite(): void
    {
        $output = $this->tool->execute(['url' => '/imprint']);

        self::assertStringContainsString('FAILED transport-level', $output);
        self::assertStringContainsString('http://localhost:59999/imprint', $output);
    }

    #[Test]
    public function emptyUrlIsRejected(): void
    {
        self::assertStringContainsString('"url" is required', $this->tool->execute([]));
    }
}
