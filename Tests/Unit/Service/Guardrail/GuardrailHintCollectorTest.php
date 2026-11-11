<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailHint;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Guardrail\ContextualGuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailHintCollector;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * A caller that gets no response object captures the hints of the calls it
 * makes, and a stored refusal keeps no generated text (ADR-288).
 */
#[CoversClass(GuardrailHintCollector::class)]
#[CoversClass(GuardrailViolationException::class)]
final class GuardrailHintCollectorTest extends AbstractUnitTestCase
{
    #[Test]
    public function aCaptureReceivesTheHintsOfTheScreensInsideIt(): void
    {
        $collector  = new GuardrailHintCollector();
        $middleware = new GuardrailMiddleware([$this->hinting($this->hint('a'))], hintCollector: $collector);

        $captured = $collector->capture(fn (): string => $this->screen($middleware)->content);

        self::assertSame('Text', $captured['result']);
        self::assertEquals([$this->hint('a')], $captured['hints']);
    }

    #[Test]
    public function anOuterCaptureAlsoReceivesTheHintsOfAnInnerOne(): void
    {
        $collector = new GuardrailHintCollector();

        $outer = $collector->capture(function () use ($collector): array {
            $collector->record($this->hint('outer'));

            return $collector->capture(function () use ($collector): bool {
                $collector->record($this->hint('inner'));

                return true;
            })['hints'];
        });

        self::assertEquals([$this->hint('inner')], $outer['result']);
        self::assertEquals([$this->hint('outer'), $this->hint('inner')], $outer['hints']);
    }

    #[Test]
    public function aCallThatThrowsClosesItsCapture(): void
    {
        $collector = new GuardrailHintCollector();

        try {
            $collector->capture(static function (): never {
                throw new RuntimeException('boom', 7131478730);
            });
            self::fail('Not rethrown.');
        } catch (RuntimeException) {
        }

        $collector->record($this->hint('after'));
        self::assertSame([], $collector->capture(static fn (): int => 1)['hints']);
    }

    #[Test]
    public function withoutACaptureNothingIsKept(): void
    {
        $collector = new GuardrailHintCollector();
        $collector->record($this->hint('lost'));

        self::assertSame([], $collector->capture(static fn (): int => 1)['hints']);
    }

    #[Test]
    public function aStoredRefusalKeepsTheAuditReasonAndTheScreenTheQuote(): void
    {
        $middleware = new GuardrailMiddleware([$this->hinting(null, GuardrailResult::deny('Quote: "Hallo du"', 'Rule 1 broken'))]);

        try {
            $this->screen($middleware);
            self::fail('Not blocked.');
        } catch (GuardrailViolationException $blocked) {
            self::assertSame('Quote: "Hallo du"', $blocked->getMessage());
            self::assertSame('Rule 1 broken', $blocked->storableMessage());
        }

        self::assertSame('plain', (new GuardrailViolationException('g', 'plain'))->storableMessage());
    }

    private function screen(GuardrailMiddleware $middleware): CompletionResponse
    {
        $response = $middleware->handle(
            new ProviderCallContext(ProviderOperation::Chat, 'c'),
            static fn (): CompletionResponse => new CompletionResponse('Text', 'm', UsageStatistics::fromTokens(1, 1)),
        );
        self::assertInstanceOf(CompletionResponse::class, $response);

        return $response;
    }

    private function hint(string $message): GuardrailHint
    {
        return new GuardrailHint('test', GuardrailHint::KIND_VIOLATION, $message);
    }

    private function hinting(?GuardrailHint $hint, ?GuardrailResult $result = null): ContextualGuardrailInterface
    {
        $result ??= GuardrailResult::allowWithHints($hint instanceof GuardrailHint ? [$hint] : []);

        return new class ($result) implements ContextualGuardrailInterface {
            public function __construct(private readonly GuardrailResult $result) {}

            public function getIdentifier(): string
            {
                return 'hinting';
            }

            public function isMandatory(): bool
            {
                return true;
            }

            public function checkOutput(CompletionResponse $response): GuardrailResult
            {
                return $this->result;
            }

            public function checkOutputInContext(CompletionResponse $response, ProviderCallContext $context): GuardrailResult
            {
                return $this->result;
            }

            public function holdsStream(ProviderCallContext $context): bool
            {
                return false;
            }
        };
    }
}
