<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolProviderInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The provider seam: tools that exist because operator configuration exists.
 *
 * Separate from ToolRegistryTest, which covers the compile-time builtin set.
 * The behaviours pinned here are the ones a provider can get wrong at runtime —
 * lateness, collision and failure — none of which a builtin can.
 */
#[CoversClass(ToolRegistry::class)]
final class ToolRegistryProviderTest extends TestCase
{
    /**
     * @param list<ToolInterface> $tools
     */
    private function provider(array $tools): ToolProviderInterface
    {
        return new class ($tools) implements ToolProviderInterface {
            /**
             * @param list<ToolInterface> $tools
             */
            public function __construct(private readonly array $tools) {}

            public function tools(): iterable
            {
                return $this->tools;
            }
        };
    }

    #[Test]
    public function exposesProvidedToolsAlongsideBuiltins(): void
    {
        $registry = new ToolRegistry(
            [new FakeTool('builtin_one')],
            [$this->provider([new FakeTool('mcp_srv_remote')])],
        );

        self::assertSame(['builtin_one', 'mcp_srv_remote'], $registry->names());
        self::assertNotNull($registry->get('mcp_srv_remote'));
        self::assertCount(2, $registry->specs());
    }

    #[Test]
    public function builtinWinsAgainstACollidingProvidedName(): void
    {
        $builtin = new FakeTool('shared_name', 'from builtin');
        $logger  = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $registry = new ToolRegistry(
            [$builtin],
            [$this->provider([new FakeTool('shared_name', 'from provider')])],
            $logger,
        );

        self::assertSame($builtin, $registry->get('shared_name'));
        self::assertSame(['shared_name'], $registry->names());
    }

    /**
     * A collision among providers is decided the same way, by order: the first
     * one indexed keeps the name. Neither is privileged, so the outcome must be
     * stable rather than correct — hence pinning it.
     */
    #[Test]
    public function theFirstProviderToClaimANameKeepsIt(): void
    {
        $first = new FakeTool('mcp_dup', 'first');

        $registry = new ToolRegistry(
            [],
            [
                $this->provider([$first]),
                $this->provider([new FakeTool('mcp_dup', 'second')]),
            ],
        );

        self::assertSame($first, $registry->get('mcp_dup'));
    }

    /**
     * builtinNames() answers the compile-time question, so it must not see a
     * provider's tools — and must not consult a provider to find that out. The
     * data-class and effect coverage tests are scoped to it for exactly this
     * reason.
     */
    #[Test]
    public function builtinNamesExcludesProvidedToolsWithoutConsultingProviders(): void
    {
        $provider = new class implements ToolProviderInterface {
            public bool $consulted = false;

            public function tools(): iterable
            {
                $this->consulted = true;

                return [new FakeTool('mcp_srv_remote')];
            }
        };

        $registry = new ToolRegistry([new FakeTool('builtin_one')], [$provider]);

        self::assertSame(['builtin_one'], $registry->builtinNames());
        self::assertFalse($provider->consulted, 'builtinNames() must not hydrate providers');
    }

    /**
     * The registry is built on backend request paths that have nothing to do
     * with an agent run. Constructing it must therefore cost nothing.
     */
    #[Test]
    public function providersAreNotConsultedUntilAToolIsLookedUp(): void
    {
        $provider = new class implements ToolProviderInterface {
            public int $calls = 0;

            public function tools(): iterable
            {
                ++$this->calls;

                return [new FakeTool('mcp_srv_remote')];
            }
        };

        $registry = new ToolRegistry([], [$provider]);
        self::assertSame(0, $provider->calls);

        $registry->names();
        $registry->specs();
        $registry->get('mcp_srv_remote');

        self::assertSame(1, $provider->calls, 'providers are consulted once per registry instance');
    }

    /**
     * A provider reads persisted rows, so a broken table or a half-written
     * record can throw. That must cost the failing provider its tools and
     * nothing else: the registry is built on pages that have no stake in the
     * MCP configuration at all.
     */
    #[Test]
    public function aFailingProviderCostsOnlyItsOwnTools(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $broken = new class implements ToolProviderInterface {
            public function tools(): iterable
            {
                throw new RuntimeException('table is gone', 1799990200);
            }
        };

        $registry = new ToolRegistry(
            [new FakeTool('builtin_one')],
            [$broken, $this->provider([new FakeTool('mcp_srv_remote')])],
            $logger,
        );

        self::assertSame(['builtin_one', 'mcp_srv_remote'], $registry->names());
    }

    /**
     * The failure is absorbed mid-iteration too, which is the shape a generator
     * reading rows one at a time actually takes. Whatever it yielded before
     * throwing stays registered; there is no half-state to roll back to.
     */
    #[Test]
    public function aProviderThatThrowsMidIterationKeepsWhatItAlreadyYielded(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $broken = new class implements ToolProviderInterface {
            public function tools(): iterable
            {
                yield new FakeTool('mcp_srv_first');

                throw new RuntimeException('row 2 is malformed', 1799990201);
            }
        };

        $registry = new ToolRegistry([], [$broken], $logger);

        self::assertSame(['mcp_srv_first'], $registry->names());
    }
}
