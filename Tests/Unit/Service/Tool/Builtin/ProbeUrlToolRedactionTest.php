<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Service\Tool\Builtin\ProbeUrlTool;
use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The report probe_url hands to the model passes the shared secret redactor:
 * the probed URL, a Location header and the page body can each carry a preview
 * token or a bare credential.
 */
#[CoversClass(ProbeUrlTool::class)]
final class ProbeUrlToolRedactionTest extends TestCase
{
    private const PREVIEW = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const PAT = 'ghp_abcdefghijklmnopqrstuvwxyz0123456789';

    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . '/nrllm-probe-' . bin2hex(random_bytes(4));
        mkdir($this->logDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        rmdir($this->logDir);
        parent::tearDown();
    }

    #[Test]
    public function theReportMasksSecretShapesInTheUrlTheLocationHeaderAndTheBody(): void
    {
        $requests = self::createStub(RequestFactory::class);
        $requests->method('request')->willReturn(new Response(
            302,
            ['Location' => 'https://www.example.com/next?ADMCMD_prev=' . self::PREVIEW],
            '<p>Session token ' . self::PAT . ' was issued.</p>',
        ));

        $output = $this->tool($requests)->execute(
            ['url' => 'https://www.example.com/page?ADMCMD_prev=' . self::PREVIEW],
            ToolExecutionContext::none(),
        )->content;

        self::assertStringContainsString('GET https://www.example.com/page', $output);
        self::assertStringContainsString('location: https://www.example.com/next', $output);
        self::assertStringContainsString('Session token', $output);
        self::assertStringNotContainsString(self::PREVIEW, $output);
        self::assertStringNotContainsString(self::PAT, $output);
    }

    #[Test]
    public function aTransportFailureMasksSecretShapesInTheUrlAndTheMessage(): void
    {
        $requests = self::createStub(RequestFactory::class);
        $requests->method('request')->willThrowException(new RuntimeException('connect failed, authorization ' . self::PAT));

        $output = $this->tool($requests)->execute(
            ['url' => 'https://www.example.com/page?ADMCMD_prev=' . self::PREVIEW],
            ToolExecutionContext::none(),
        )->content;

        self::assertStringContainsString('FAILED transport-level', $output);
        self::assertStringNotContainsString(self::PREVIEW, $output);
        self::assertStringNotContainsString(self::PAT, $output);
    }

    #[Test]
    public function aDeniedUrlIsEchoedWithoutItsSecretShapes(): void
    {
        $output = $this->tool(self::createStub(RequestFactory::class))->execute(
            ['url' => 'https://evil.example.org/?ADMCMD_prev=' . self::PREVIEW . '&note=' . self::PAT],
            ToolExecutionContext::none(),
        )->content;

        self::assertStringContainsString('Denied', $output);
        self::assertStringContainsString('https://evil.example.org/', $output);
        self::assertStringNotContainsString(self::PREVIEW, $output);
        self::assertStringNotContainsString(self::PAT, $output);
    }

    private function tool(RequestFactory $requests): ProbeUrlTool
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([
            'main' => new Site('main', 1, ['base' => 'https://www.example.com/']),
        ]);

        return new ProbeUrlTool(new EgressPolicyService($siteFinder), $requests, new LogExceptionReader($this->logDir));
    }
}
