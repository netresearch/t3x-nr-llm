<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Tests\Fixture\GuardrailIdentityDoubleTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GuardrailMiddleware::class)]
final class GuardrailMiddlewareTest extends TestCase
{
    #[Test]
    public function allowPassesTheResponseThrough(): void
    {
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::allow())]);

        $result = $this->screen($middleware, fn(): CompletionResponse => $this->response('hello'));

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('hello', $result->content);
    }

    #[Test]
    public function redactReplacesTheContentButKeepsScreening(): void
    {
        $middleware = new GuardrailMiddleware([
            $this->guardrail(GuardrailResult::redact('[redacted]', 'secret')),
            // A second guardrail sees the redacted content and allows it.
            new class implements GuardrailInterface {
                use GuardrailIdentityDoubleTrait;
                public function checkOutput(CompletionResponse $response): GuardrailResult
                {
                    return $response->content === '[redacted]'
                        ? GuardrailResult::allow()
                        : GuardrailResult::deny('should have been redacted first');
                }
            },
        ]);

        $result = $this->screen($middleware, fn(): CompletionResponse => $this->response('my secret is X'));

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('[redacted]', $result->content);
    }

    #[Test]
    public function redactAppliesTheRedactedThinkingToTheResponse(): void
    {
        $middleware = new GuardrailMiddleware([
            $this->guardrail(GuardrailResult::redact('[redacted]', 'secret', '[thinking-redacted]')),
        ]);
        $terminal = fn(): CompletionResponse => new CompletionResponse('raw', 'test-model', UsageStatistics::fromTokens(1, 1), 'stop', '', null, null, 'raw thinking');

        $result = $this->screen($middleware, $terminal);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('[redacted]', $result->content);
        self::assertSame('[thinking-redacted]', $result->thinking);
    }

    #[Test]
    public function redactWithNullThinkingKeepsTheOriginalThinking(): void
    {
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::redact('[redacted]', 'secret'))]);
        $terminal   = fn(): CompletionResponse => new CompletionResponse('raw', 'test-model', UsageStatistics::fromTokens(1, 1), 'stop', '', null, null, 'original thinking');

        $result = $this->screen($middleware, $terminal);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('original thinking', $result->thinking);
    }

    #[Test]
    public function denyThrowsAViolation(): void
    {
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::deny('blocked'))]);

        $this->expectException(GuardrailViolationException::class);
        $this->expectExceptionMessage('blocked');
        $this->screen($middleware, fn(): CompletionResponse => $this->response('bad'));
    }

    #[Test]
    public function requireApprovalThrowsTheApprovalException(): void
    {
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::requireApproval('needs review'))]);

        $this->expectException(GuardrailApprovalRequiredException::class);
        $this->screen($middleware, fn(): CompletionResponse => $this->response('maybe'));
    }

    #[Test]
    public function retryReRunsTheProviderOnceThenAllows(): void
    {
        $calls    = 0;
        $terminal = function () use (&$calls): CompletionResponse {
            ++$calls;

            return $this->response($calls === 1 ? 'first' : 'second');
        };
        // Retry while the content is 'first'; allow the fresh 'second'.
        $guardrail = new class implements GuardrailInterface {
            use GuardrailIdentityDoubleTrait;
            public function checkOutput(CompletionResponse $response): GuardrailResult
            {
                return $response->content === 'first'
                    ? GuardrailResult::retry('try again')
                    : GuardrailResult::allow();
            }
        };

        $result = $this->screen(new GuardrailMiddleware([$guardrail]), $terminal);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('second', $result->content);
        self::assertSame(2, $calls);
    }

    #[Test]
    public function retryIsCappedAtOnceThenDenies(): void
    {
        // A guardrail that always asks to retry: after the single retry it denies.
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::retry('never happy'))]);

        $this->expectException(GuardrailViolationException::class);
        $this->screen($middleware, fn(): CompletionResponse => $this->response('always'));
    }

    #[Test]
    public function nonScreenableResponsePassesThroughUnscreened(): void
    {
        // e.g. an embedding payload (a raw string/array): the guardrail — even a
        // denying one — is never consulted.
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::deny('should not run'))]);

        $result = $this->screen($middleware, static fn(): string => 'raw-embedding');

        self::assertSame('raw-embedding', $result);
    }

    #[Test]
    public function visionResponseDescriptionIsScreenedAndRedactedPreservingOtherFields(): void
    {
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::redact('[redacted]', 'secret'))]);
        $vision     = new VisionResponse('the key is sk-secret', 'vision-model', UsageStatistics::fromTokens(1, 1), 'openai', 0.9, [['label' => 'cat']], ['k' => 'v']);

        $result = $this->screen($middleware, static fn(): VisionResponse => $vision);

        self::assertInstanceOf(VisionResponse::class, $result);
        self::assertSame('[redacted]', $result->description);
        // Non-text fields survive the rebuild.
        self::assertSame('vision-model', $result->model);
        self::assertSame('openai', $result->provider);
        self::assertSame(0.9, $result->confidence);
        self::assertSame([['label' => 'cat']], $result->detectedObjects);
        self::assertSame(['k' => 'v'], $result->metadata);
    }

    #[Test]
    public function cleanVisionResponseIsReturnedUnchanged(): void
    {
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::allow())]);
        $vision     = new VisionResponse('a perfectly clean caption', 'vision-model', UsageStatistics::fromTokens(1, 1));

        $result = $this->screen($middleware, static fn(): VisionResponse => $vision);

        self::assertSame($vision, $result, 'A clean vision response is returned as the same instance.');
    }

    #[Test]
    public function visionResponseDenyThrowsAViolation(): void
    {
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::deny('blocked'))]);

        $this->expectException(GuardrailViolationException::class);
        $this->screen($middleware, static fn(): VisionResponse => new VisionResponse('bad', 'm', UsageStatistics::fromTokens(1, 1)));
    }

    #[Test]
    public function visionResponseRetryFailsClosedBecauseAVisionCallCannotBeRetried(): void
    {
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::retry('deficient'))]);

        $this->expectException(GuardrailViolationException::class);
        $this->screen($middleware, static fn(): VisionResponse => new VisionResponse('desc', 'm', UsageStatistics::fromTokens(1, 1)));
    }

    #[Test]
    public function aConfigurationSelectionSkipsAnUnselectedOptionalGuardrail(): void
    {
        // ADR-106: the double is an OPTIONAL guardrail (id 'test-guardrail' via
        // the trait). A configuration whose non-empty selection does not name it
        // filters it out — so its DENY never runs and the response passes through.
        $middleware = new GuardrailMiddleware([$this->guardrail(GuardrailResult::deny('blocked'))]);

        $config = new LlmConfiguration();
        $config->setAllowedGuardrails('some-other-guardrail');
        $context = ProviderCallContext::forConfiguration(ProviderOperation::Chat, $config);

        $result = $middleware->handle($context, fn(): CompletionResponse => $this->response('hello'));

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('hello', $result->content);
    }

    /**
     * @param callable(): mixed $terminal
     */
    private function screen(GuardrailMiddleware $middleware, callable $terminal): mixed
    {
        return $middleware->handle(ProviderCallContext::forConfiguration(ProviderOperation::Chat, new LlmConfiguration()), $terminal);
    }

    private function guardrail(GuardrailResult $result): GuardrailInterface
    {
        return new class ($result) implements GuardrailInterface {
            use GuardrailIdentityDoubleTrait;
            public function __construct(private readonly GuardrailResult $result) {}

            public function checkOutput(CompletionResponse $response): GuardrailResult
            {
                return $this->result;
            }
        };
    }

    private function response(string $content): CompletionResponse
    {
        return new CompletionResponse($content, 'test-model', UsageStatistics::fromTokens(1, 1));
    }
}
