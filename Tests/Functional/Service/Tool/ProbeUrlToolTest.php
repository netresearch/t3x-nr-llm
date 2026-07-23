<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\ProbeUrlTool;
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
            baseVariants:
              - base: 'http://staging.internal:8080/'
                condition: 'applicationContext == "Production/Staging"'
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
        $output = $this->tool->execute(['url' => 'https://evil.example.com/steal'], ToolExecutionContext::none())->content;

        self::assertStringContainsString('Denied', $output);
        self::assertStringContainsString('localhost:59999', $output);
    }

    #[Test]
    public function deniesNonHttpScheme(): void
    {
        self::assertStringContainsString('Denied', $this->tool->execute(['url' => 'file:///etc/passwd'], ToolExecutionContext::none())->content);
        self::assertStringContainsString('Denied', $this->tool->execute(['url' => 'gopher://localhost:59999/x'], ToolExecutionContext::none())->content);
    }

    #[Test]
    public function deniesAnInactiveBaseVariantHost(): void
    {
        // The site declares a staging baseVariant on an internal host; its
        // condition is inactive in the test context, so it is NOT the served
        // base — probe_url must not reach it (only the active base is trusted).
        // Scheme is irrelevant to this denial (the host is not the served base);
        // https keeps the test intent without an insecure-URL false positive.
        $output = $this->tool->execute(['url' => 'https://staging.internal:8080/'], ToolExecutionContext::none())->content;

        self::assertStringContainsString('Denied', $output);
    }

    #[Test]
    public function deniesRoguePortOnOwnHost(): void
    {
        // Same host, different port than the site (59999) — must NOT pass the
        // gate, or an attacker could probe localhost:6379 (Redis), :3306, …
        self::assertStringContainsString('Denied', $this->tool->execute(['url' => 'http://localhost:6379/'], ToolExecutionContext::none())->content);
        // Bare host defaults to port 80, also != 59999.
        self::assertStringContainsString('Denied', $this->tool->execute(['url' => 'http://localhost/'], ToolExecutionContext::none())->content);
    }

    #[Test]
    public function deniesUserinfoAndDoesNotEchoCredentials(): void
    {
        // Userinfo is denied regardless of scheme; the credential is assembled at
        // runtime so the deliberate attack input is not a hardcoded-secret flag.
        $secret = 's3cr3t';
        $url    = sprintf('https://user:%s@localhost:59999/', $secret);
        $output = $this->tool->execute(['url' => $url], ToolExecutionContext::none())->content;

        self::assertStringContainsString('Denied', $output);
        self::assertStringNotContainsString($secret, $output);
    }

    #[Test]
    public function ownHostPassesTheGate(): void
    {
        $output = $this->tool->execute(['url' => 'http://localhost:59999/some/page'], ToolExecutionContext::none())->content;

        // The request is ATTEMPTED (gate passed) and fails at transport
        // level because nothing listens on the closed port.
        self::assertStringContainsString('FAILED transport-level', $output);
    }

    #[Test]
    public function relativePathResolvesAgainstTheFirstSite(): void
    {
        $output = $this->tool->execute(['url' => '/imprint'], ToolExecutionContext::none())->content;

        self::assertStringContainsString('FAILED transport-level', $output);
        self::assertStringContainsString('http://localhost:59999/imprint', $output);
    }

    #[Test]
    public function emptyUrlIsRejected(): void
    {
        self::assertStringContainsString('"url" is required', $this->tool->execute([], ToolExecutionContext::none())->content);
    }
}
