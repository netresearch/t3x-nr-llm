<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiddlewarePipeline::class)]
#[CoversClass(ProviderCallContext::class)]
final class MiddlewarePipelineTest extends TestCase
{
    #[Test]
    public function runWithoutMiddlewareInvokesTerminalDirectly(): void
    {
        $pipeline = new MiddlewarePipeline([]);
        $config   = $this->configuration('primary');

        $result = $pipeline->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $config,
            terminal: static fn(LlmConfiguration $c): string => 'terminal:' . $c->getIdentifier(),
        );

        self::assertSame('terminal:primary', $result);
    }

    #[Test]
    public function runWithSingleMiddlewareWrapsTerminal(): void
    {
        $middleware = $this->recordingMiddleware('A');
        $pipeline   = new MiddlewarePipeline([$middleware]);

        $result = $pipeline->run(
            context: ProviderCallContext::for(ProviderOperation::Embedding),
            configuration: $this->configuration('primary'),
            terminal: static fn(LlmConfiguration $c): string => 'terminal:' . $c->getIdentifier(),
        );

        self::assertSame('A(terminal:primary)', $result);
    }

    #[Test]
    public function runComposesMiddlewareInRegisteredOrder(): void
    {
        $pipeline = new MiddlewarePipeline([
            $this->recordingMiddleware('outer'),
            $this->recordingMiddleware('middle'),
            $this->recordingMiddleware('inner'),
        ]);

        $result = $pipeline->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration('primary'),
            terminal: static fn(LlmConfiguration $c): string => 'T(' . $c->getIdentifier() . ')',
        );

        // First-registered is outermost: outer(middle(inner(T)))
        self::assertSame('outer(middle(inner(T(primary))))', $result);
    }

    #[Test]
    public function middlewareCanShortCircuit(): void
    {
        $shortCircuit = new class implements ProviderMiddlewareInterface {
            public function handle(
                ProviderCallContext $context,
                LlmConfiguration $configuration,
                callable $next,
            ): string {
                return 'short-circuit';
            }
        };

        $terminalWasCalled = false;
        $pipeline          = new MiddlewarePipeline([$shortCircuit]);

        $result = $pipeline->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration('primary'),
            terminal: static function (LlmConfiguration $c) use (&$terminalWasCalled): string {
                $terminalWasCalled = true;

                return 'terminal';
            },
        );

        self::assertSame('short-circuit', $result);
        self::assertFalse($terminalWasCalled, 'Terminal must not be called when middleware short-circuits.');
    }

    #[Test]
    public function middlewareCanSubstituteConfigurationForDownstream(): void
    {
        $swap = new class ($this->configuration('fallback')) implements ProviderMiddlewareInterface {
            public function __construct(private readonly LlmConfiguration $replacement) {}

            public function handle(
                ProviderCallContext $context,
                LlmConfiguration $configuration,
                callable $next,
            ): mixed {
                return $next($this->replacement);
            }
        };

        $pipeline = new MiddlewarePipeline([$swap]);

        $result = $pipeline->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration('primary'),
            terminal: static fn(LlmConfiguration $c): string => $c->getIdentifier(),
        );

        self::assertSame('fallback', $result);
    }

    #[Test]
    public function contextIsPropagatedToEveryMiddleware(): void
    {
        $context = ProviderCallContext::for(
            ProviderOperation::Vision,
            ['user' => 42],
        );

        /** @var list<string> $seen */
        $seen = [];

        $pipeline = new MiddlewarePipeline([
            $this->capturingMiddleware('first', $seen),
            $this->capturingMiddleware('second', $seen),
        ]);

        $pipeline->run(
            context: $context,
            configuration: $this->configuration('primary'),
            terminal: static fn(LlmConfiguration $c): string => 'done',
        );

        self::assertSame(
            ['first:' . $context->correlationId, 'second:' . $context->correlationId],
            $seen,
        );
    }

    #[Test]
    public function runAcceptsGeneratorAsMiddlewareIterable(): void
    {
        $generator = (static function (): iterable {
            yield self::makeRecordingMiddleware('A');
            yield self::makeRecordingMiddleware('B');
        })();

        $pipeline = new MiddlewarePipeline($generator);

        $result = $pipeline->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration('primary'),
            terminal: static fn(LlmConfiguration $c): string => 'T',
        );

        self::assertSame('A(B(T))', $result);
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    private function configuration(string $identifier): LlmConfiguration
    {
        $config = new LlmConfiguration();
        $config->setIdentifier($identifier);

        return $config;
    }

    private function recordingMiddleware(string $label): ProviderMiddlewareInterface
    {
        return self::makeRecordingMiddleware($label);
    }

    private static function makeRecordingMiddleware(string $label): ProviderMiddlewareInterface
    {
        return new class ($label) implements ProviderMiddlewareInterface {
            public function __construct(private readonly string $label) {}

            public function handle(
                ProviderCallContext $context,
                LlmConfiguration $configuration,
                callable $next,
            ): string {
                $downstream = $next($configuration);
                \assert(\is_string($downstream));

                return $this->label . '(' . $downstream . ')';
            }
        };
    }

    /**
     * @param list<string> $sink reference-bound collector
     */
    private function capturingMiddleware(string $label, array &$sink): ProviderMiddlewareInterface
    {
        $record = static function (string $line) use (&$sink): void {
            $sink[] = $line;
        };

        return new class ($label, $record) implements ProviderMiddlewareInterface {
            /**
             * @param callable(string): void $record
             */
            public function __construct(
                private readonly string $label,
                /** @var callable(string): void */
                private $record,
            ) {}

            public function handle(
                ProviderCallContext $context,
                LlmConfiguration $configuration,
                callable $next,
            ): mixed {
                ($this->record)($this->label . ':' . $context->correlationId);

                return $next($configuration);
            }
        };
    }
}
