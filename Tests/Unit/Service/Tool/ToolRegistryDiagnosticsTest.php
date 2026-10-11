<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Error;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolProviderInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\ToolRegistryDiagnosticLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ToolRegistry::class)]
final class ToolRegistryDiagnosticsTest extends TestCase
{
    #[Test]
    #[DataProvider('providerFailuresAndDiagnosticModes')]
    public function optionalDiagnosticsPreserveProviderRowsAndLazyHydration(
        string $kind,
        string $mode,
    ): void {
        $cause = str_ends_with($kind, 'error') ? new Error('Stored provider row failed.', 1770581117) : new RuntimeException('Stored provider row failed.', 1770581117);
        $deliveryFailure = match ($mode) {
            'runtime' => new RuntimeException('Diagnostic delivery failed.', 1770581118),
            'error' => new Error('Diagnostic delivery failed.', 1770581118),
            default => null,
        };
        $logger = $mode === 'none' ? null : new ToolRegistryDiagnosticLogger($deliveryFailure);
        $builtin = new FakeTool('builtin');
        $sameProvider = new FakeTool('same_provider');
        $partial = new FakeTool('partial_provider');
        $laterTool = new FakeTool('later_provider');
        $provider = new class (
            $kind,
            $cause,
            $sameProvider,
            $partial,
        ) implements ToolProviderInterface {
            public int $calls = 0;

            public function __construct(
                private readonly string $kind,
                private readonly Throwable $cause,
                private readonly ToolInterface $sameProvider,
                private readonly ToolInterface $partial,
            ) {}

            public function tools(): iterable
            {
                ++$this->calls;
                if ($this->kind === 'collision') {
                    return [new FakeTool('builtin'), $this->sameProvider];
                }

                if (str_starts_with($this->kind, 'method-')) {
                    throw $this->cause;
                }

                return (function (): iterable {
                    yield $this->partial;
                    throw $this->cause;
                })();
            }
        };
        $later = new class ($laterTool) implements ToolProviderInterface {
            public int $calls = 0;

            public function __construct(private readonly ToolInterface $tool) {}

            public function tools(): iterable
            {
                ++$this->calls;

                return [$this->tool];
            }
        };
        $registry = new ToolRegistry([$builtin], [$provider, $later], $logger);
        self::assertSame(['builtin'], $registry->builtinNames());
        self::assertSame(0, $provider->calls);
        self::assertSame(0, $later->calls);
        $names = null;
        $escaped = null;
        try {
            $names = $registry->names();
        } catch (Throwable $error) {
            $escaped = $error;
        }

        self::assertNull(
            $escaped,
            'Optional diagnostics must preserve provider isolation.',
        );
        $expected = match (true) {
            $kind === 'collision' => ['builtin', 'same_provider', 'later_provider'],
            str_starts_with($kind, 'partial-') => ['builtin', 'partial_provider', 'later_provider'],
            default => ['builtin', 'later_provider'],
        };
        self::assertSame($expected, $names);
        self::assertSame($builtin, $registry->get('builtin'));
        self::assertSame($laterTool, $registry->get('later_provider'));
        self::assertSame(
            $kind === 'collision' ? $sameProvider : null,
            $registry->get('same_provider'),
        );
        self::assertSame(
            str_starts_with($kind, 'partial-') ? $partial : null,
            $registry->get('partial_provider'),
        );
        self::assertSame(
            $expected,
            array_map(
                static fn(ToolSpec $spec): string => $spec->name,
                $registry->specs(),
            ),
        );
        self::assertSame(
            ['later_provider'],
            array_map(
                static fn(ToolSpec $spec): string => $spec->name,
                $registry->specs(['later_provider', 'unknown']),
            ),
        );
        self::assertSame([], $registry->specs([]));
        self::assertSame($expected, $registry->names());
        self::assertSame(['builtin'], $registry->builtinNames());
        self::assertSame(1, $provider->calls);
        self::assertSame(1, $later->calls);
        if ($logger instanceof ToolRegistryDiagnosticLogger) {
            self::assertSame(
                [
                    $kind === 'collision' ? [
                        'level' => 'warning',
                        'message' => 'A provided tool was dropped because its name is already taken',
                        'context' => ['tool' => 'builtin', 'provider' => $provider::class],
                    ] : [
                        'level' => 'error',
                        'message' => 'A tool provider failed and supplied no tools',
                        'context' => ['provider' => $provider::class, 'exception' => $cause],
                    ],
                ],
                $logger->records,
            );
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providerFailuresAndDiagnosticModes(): iterable
    {
        foreach ([
            'collision',
            'method-runtime',
            'method-error',
            'partial-runtime',
            'partial-error',
        ] as $kind) {
            foreach (['runtime', 'error', 'recording', 'none'] as $mode) {
                yield $kind . '-' . $mode => [$kind, $mode];
            }
        }
    }
}
