<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\FetchExternalUrlTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\Web\ExternalFetchClientFactory;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * fetch_external_url as the container builds it (ADR-202).
 *
 * Proves the wiring — registry, egress policy, guard, nr-vault client — with
 * targets the guard refuses before any network access, so the test needs no
 * DNS and no server. The fetch path itself is covered by the unit test
 * against a scripted transport.
 */
#[CoversClass(FetchExternalUrlTool::class)]
final class FetchExternalUrlToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('fetch_external_url');
        self::assertInstanceOf(FetchExternalUrlTool::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function internalTargetsAreRefusedThroughTheWiredGuard(): void
    {
        $refused = [
            'http://127.0.0.1/'                         => 'private, loopback',
            'http://169.254.169.254/latest/meta-data/'  => 'private, loopback',
            'http://[::1]/'                             => 'private, loopback',
            'http://[fd00:ec2::254]/'                   => 'private, loopback',
            'http://[::ffff:10.0.0.1]/'                 => 'private, loopback',
            'http://2130706433/'                        => 'numeric host',
            'http://0177.0.0.1/'                        => 'numeric host',
            'file:///etc/passwd'                        => 'only http and https',
            'https://user:pw@93.184.215.14/'            => 'credentials',
            'https://93.184.215.14:8443/'               => 'port 8443',
        ];

        foreach ($refused as $url => $reason) {
            $result = $this->tool->execute(['url' => $url], ToolExecutionContext::none());

            self::assertTrue($result->isError, $url);
            self::assertStringStartsWith('Refused: ', $result->content, $url);
            self::assertStringContainsString($reason, $result->content, $url);
        }
    }

    #[Test]
    public function theContainerWiresNrVaultsHardenedClient(): void
    {
        // The unit test runs against a scripted transport; this is the one place
        // that sees which client production gets.
        $property = (new ReflectionClass(FetchExternalUrlTool::class))->getProperty('clientFactory');

        self::assertInstanceOf(ExternalFetchClientFactory::class, $property->getValue($this->tool));
    }

    #[Test]
    public function itIsAReadToolOfTheWebGroupThatShipsDisabled(): void
    {
        self::assertSame('web', $this->tool->getGroup());
        self::assertFalse($this->tool->isEnabledByDefault());
        self::assertFalse($this->tool->requiresAdmin());
    }
}
