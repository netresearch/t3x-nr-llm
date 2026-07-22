<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Streaming;

use Generator;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\SecretRedactionGuardrail;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Tests\Fixture\GuardrailIdentityDoubleTrait;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(StreamingDispatcher::class)]
final class StreamingDispatcherTest extends AbstractUnitTestCase
{
    private RecordingUsageTracker $usage;

    private InMemoryTelemetryRepository $telemetry;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usage     = new RecordingUsageTracker();
        $this->telemetry = new InMemoryTelemetryRepository();
        $this->logger    = new RecordingLogger();
    }

    #[Test]
    public function drainsEveryChunkFromTheInnerStream(): void
    {
        $dispatcher = $this->dispatcher();

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['Hello', ' ', 'World']),
        ));

        self::assertSame(['Hello', ' ', 'World'], $chunks);
    }

    #[Test]
    public function auditsTheAssembledStreamedResponseAgainstGuardrailsAfterDelivery(): void
    {
        $guardrail = new class implements GuardrailInterface {
            use GuardrailIdentityDoubleTrait;
            public function checkOutput(CompletionResponse $response): GuardrailResult
            {
                return str_contains($response->content, 'sk-secret')
                    ? GuardrailResult::deny('a secret was streamed')
                    : GuardrailResult::allow();
            }
        };
        $dispatcher = $this->dispatcher(guardrails: [$guardrail]);

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['here is ', 'sk-secret-value', ' — oops']),
        ));

        // A DENY guardrail is not StreamRedactable, so it never pulls the stream
        // onto the buffered path: chunks pass through 1:1, unchanged.
        self::assertSame(['here is ', 'sk-secret-value', ' — oops'], $chunks);

        // The verdict is recorded for audit so streamed output is not a blind spot.
        $record = $this->logger->firstMatching('warning', 'matched a guardrail');
        self::assertNotNull($record);
        self::assertSame($guardrail::class, $record['context']['guardrail'] ?? null);
        self::assertSame('deny', $record['context']['verdict'] ?? null);
        self::assertSame('a secret was streamed', $record['context']['reason'] ?? null);
    }

    #[Test]
    public function doesNotRecordAGuardrailAuditForACleanStream(): void
    {
        $guardrail = new class implements GuardrailInterface {
            use GuardrailIdentityDoubleTrait;
            public function checkOutput(CompletionResponse $response): GuardrailResult
            {
                return GuardrailResult::allow();
            }
        };
        $dispatcher = $this->dispatcher(guardrails: [$guardrail]);

        iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['a perfectly ', 'normal answer']),
        ));

        self::assertNull($this->logger->firstMatching('warning', 'matched a guardrail'));
    }

    #[Test]
    public function redactsASecretSpanningAChunkBoundaryBeforeEmittingIt(): void
    {
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);

        // The key 'sk-abcdef0123456789ABCDEF' is split across the two chunks, then
        // followed by > HOLDBACK bytes of filler so the (now redacted) secret
        // exits the holdback window and is actually emitted, not just flushed.
        $filler = str_repeat('x', 200);
        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['start sk-abcdef012', '3456789ABCDEF end ' . $filler]),
        ));

        $emitted = implode('', $chunks);
        self::assertStringContainsString('sk-***', $emitted);
        self::assertStringNotContainsString('sk-abcdef0123456789ABCDEF', $emitted);
        // Non-secret text is preserved verbatim.
        self::assertStringContainsString('start ', $emitted);
        self::assertStringContainsString(' end ', $emitted);
    }

    #[Test]
    public function redactsASecretAtTheVeryEndOfTheStreamOnFlush(): void
    {
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);

        // The secret straddles the last boundary and completes only at end — the
        // holdback keeps it unsent until the flush redacts it.
        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['final answer sk-', 'abcdef0123456789ABCDEF']),
        ));

        $emitted = implode('', $chunks);
        self::assertStringContainsString('sk-***', $emitted);
        self::assertStringNotContainsString('sk-abcdef0123456789ABCDEF', $emitted);
        self::assertStringContainsString('final answer ', $emitted);
    }

    #[Test]
    public function leavesACleanStreamContentUnchangedThroughTheHoldback(): void
    {
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);

        $tail   = str_repeat('y', 200);
        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['a perfectly ', 'normal answer ', $tail]),
        ));

        self::assertSame('a perfectly normal answer ' . $tail, implode('', $chunks));
    }

    #[Test]
    public function flushesAShortStreamThroughTheHoldback(): void
    {
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);

        // Whole stream is shorter than the holdback: nothing emits mid-stream, the
        // flush delivers it all at end — concatenation is intact.
        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['hi ', 'there']),
        ));

        self::assertSame('hi there', implode('', $chunks));
    }

    #[Test]
    public function masksASecretWhoseTailArrivesAfterItsHeadWasFirstMatched(): void
    {
        // The 16-char body prefix arrives in chunk 1 (enough for the pattern to
        // match); the rest of the SAME key arrives in chunk 2. Re-redacting the
        // marker would orphan the tail ('sk-***' + continuation no longer
        // matches); redacting the raw buffer fresh re-masks the whole key.
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['key sk-0123456789abcdef', 'ghijklmnopqrstuvwxyz done']),
        ));

        $emitted = implode('', $chunks);
        self::assertStringContainsString('sk-***', $emitted);
        self::assertStringNotContainsString('sk-0123456789abcdef', $emitted);
        self::assertStringNotContainsString('ghijklmnopqrstuvwxyz', $emitted);
        self::assertStringContainsString('key ', $emitted);
        self::assertStringContainsString(' done', $emitted);
    }

    #[Test]
    public function masksASecretStreamedInSmallDeltas(): void
    {
        // A 51-char key streamed 4 bytes at a time — the pattern matches at 16
        // body chars, long before the key completes.
        $key    = 'sk-' . str_repeat('A', 48);
        $deltas = str_split('here ' . $key . ' there ' . str_repeat('z', 200), 4);

        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);
        $chunks     = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream($deltas),
        ));

        $emitted = implode('', $chunks);
        self::assertStringContainsString('sk-***', $emitted);
        self::assertStringNotContainsString($key, $emitted);
        self::assertStringNotContainsString('AAAA', $emitted, 'no run of key characters may leak');
    }

    #[Test]
    public function masksABearerTokenSplitAcrossChunks(): void
    {
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['auth Bearer abc123', 'def456ghi789 rest ' . str_repeat('q', 200)]),
        ));

        $emitted = implode('', $chunks);
        self::assertStringContainsString('Bearer ***', $emitted);
        self::assertStringNotContainsString('abc123def456ghi789', $emitted);
        self::assertStringNotContainsString('def456ghi789', $emitted);
    }

    #[Test]
    public function emitsContentMidStreamNotOnlyAtTheFlush(): void
    {
        // A long clean stream must deliver incrementally (more than one yielded
        // chunk), proving the mid-stream emit path runs, not just the end flush.
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);
        $long       = str_repeat('word ', 200);

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(str_split($long, 50)),
        ));

        self::assertGreaterThan(1, count($chunks), 'content must be emitted incrementally, not only at flush');
        self::assertSame($long, implode('', $chunks));
    }

    #[Test]
    public function usageCountsRawProviderBytesEvenWhenRedactionShortensTheStream(): void
    {
        $model      = $this->model(pricing: false);
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);

        // Raw completion is 92 bytes ('sk-' + 48 + ' ' + 40); the redacted output
        // ('sk-*** ' + 40) is far shorter. Usage must reflect the RAW length.
        iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 0]),
            $this->configuration('primary', providerType: 'openai', modelId: 'gpt-4o', model: $model, uid: 9),
            $this->staticStream(['sk-' . str_repeat('A', 48) . ' ', str_repeat('z', 40)]),
        ));

        self::assertCount(1, $this->usage->calls);
        // ceil(92 / 4) = 23 raw-byte tokens, not the ~12 of the redacted output.
        self::assertSame(23, $this->usage->calls[0]['metrics']['completionTokens']);
    }

    #[Test]
    public function masksAUrlCredentialSplitAcrossChunks(): void
    {
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['see https://api.example.com/x?api_key=SUPER', 'SECRETvalue123 done ' . str_repeat('q', 200)]),
        ));

        $emitted = implode('', $chunks);
        self::assertStringNotContainsString('SUPERSECRETvalue123', $emitted);
        self::assertStringContainsString('see https://', $emitted);
    }

    #[Test]
    public function yieldsValidUtf8ChunksForMultibyteContentThroughTheHoldback(): void
    {
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);

        // A run of 3-byte chars longer than the holdback, fed in 7-byte deltas
        // (misaligned to the 3-byte boundary), so the emit boundary lands inside
        // multibyte sequences unless backed off.
        $text   = str_repeat('—', 200);
        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(str_split($text, 7)),
        ));

        foreach ($chunks as $chunk) {
            self::assertTrue(mb_check_encoding($chunk, 'UTF-8'), 'each yielded chunk must be valid UTF-8');
        }
        self::assertSame($text, implode('', $chunks));
    }

    #[Test]
    public function aPolicyOnlyGuardrailDoesNotTriggerTheHoldback(): void
    {
        // A DENY guardrail is NOT StreamRedactable, so it must not pull the stream
        // onto the buffered path — chunks pass through 1:1, no re-chunking.
        $guardrail = new class implements GuardrailInterface {
            use GuardrailIdentityDoubleTrait;
            public function checkOutput(CompletionResponse $response): GuardrailResult
            {
                return GuardrailResult::deny('policy');
            }
        };
        $dispatcher = $this->dispatcher(guardrails: [$guardrail]);

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['one ', 'two ', 'three']),
        ));

        self::assertSame(['one ', 'two ', 'three'], $chunks);
    }

    #[Test]
    public function masksASecretPositionedPastTheOldBufferCap(): void
    {
        $dispatcher = $this->dispatcher(guardrails: [new SecretRedactionGuardrail()]);

        // > 50000 bytes of benign filler, THEN a secret. The old layout switched
        // to raw passthrough past MAX_GUARDRAIL_BUFFER_BYTES and leaked it; the
        // sliding window keeps redacting (bounded memory), so it is masked.
        $postSecret = 'sk-' . str_repeat('B', 20);
        $chunks     = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream([str_repeat('a', 60000), ' the key is ' . $postSecret . ' end']),
        ));

        $emitted = implode('', $chunks);
        self::assertStringContainsString('sk-***', $emitted, 'a secret past the old cap is now masked');
        self::assertStringNotContainsString($postSecret, $emitted);
        self::assertStringContainsString(str_repeat('a', 100), $emitted, 'benign filler still streams');
        // Live redaction no longer stops, so there is no cap-exceeded warning.
        self::assertNull($this->logger->firstMatching('warning', 'exceeded the live-redaction cap'));
    }

    #[Test]
    public function rejectsAnOverBudgetCallerEagerlyBeforeOpeningTheStream(): void
    {
        $opened     = false;
        $dispatcher = $this->dispatcher($this->budget(BudgetCheckResult::denied('cost_per_day', 5.0, 4.0)));

        try {
            // Budget is checked in stream() itself, before a generator exists,
            // so the throw happens at call time — not on first iteration.
            $dispatcher->stream(
                $this->context([BudgetMiddleware::METADATA_BE_USER_UID => 7]),
                $this->configuration('primary'),
                function () use (&$opened): Generator {
                    $opened = true;
                    yield 'never';
                },
            );
            self::fail('Expected BudgetExceededException.');
        } catch (BudgetExceededException) {
            // expected
        }

        self::assertFalse($opened, 'The stream must not be opened for an over-budget caller.');
        self::assertSame([], $this->usage->calls);
        self::assertSame([], $this->telemetry->records);
    }

    #[Test]
    public function recordsEstimatedUsageAndTelemetryOnSuccess(): void
    {
        $model = $this->model(pricing: true, cost: 0.25);
        $dispatcher = $this->dispatcher();

        // 12 prompt chars => ceil(12/4) = 3 tokens; "Hello World" = 11 chars => ceil(11/4) = 3 tokens.
        iterator_to_array($dispatcher->stream(
            $this->context([
                BudgetMiddleware::METADATA_BE_USER_UID       => 42,
                StreamingDispatcher::METADATA_PROMPT_CHARS   => 12,
            ]),
            $this->configuration('primary', providerType: 'openai', modelId: 'gpt-4o', model: $model, uid: 9),
            $this->staticStream(['Hello', ' World']),
        ));

        self::assertCount(1, $this->usage->calls);
        $call = $this->usage->calls[0];
        self::assertSame('stream', $call['serviceType']);
        self::assertSame('openai', $call['provider']);
        self::assertSame(3, $call['metrics']['promptTokens']);
        self::assertSame(3, $call['metrics']['completionTokens']);
        self::assertSame(6, $call['metrics']['tokens']);
        self::assertSame(0.25, $call['metrics']['cost']);
        self::assertSame(9, $call['configurationUid']);
        // The served model's uid (getUid() => 3) is attributed, not the 0/null fallback.
        self::assertSame(3, $call['modelUid']);
        self::assertSame('gpt-4o', $call['modelId']);
        self::assertSame(42, $call['beUserUid']);
        // No task metadata was supplied, so readInt() yields its 0 default.
        self::assertSame(0, $call['taskUid']);

        self::assertCount(1, $this->telemetry->records);
        $record = $this->telemetry->records[0];
        self::assertSame('stream', $record->operation);
        self::assertSame('corr-1', $record->correlationId);
        self::assertSame('primary', $record->configurationIdentifier);
        // beUser is resolved from the caller-supplied metadata (42), not the
        // ambient backend.user aspect (0).
        self::assertSame(42, $record->beUser);
        self::assertTrue($record->success);
        self::assertSame('', $record->errorClass);
        self::assertFalse($record->cacheHit);
        self::assertSame(0, $record->fallbackAttempts);
        self::assertNotNull($record->timeToFirstTokenMs);
        self::assertGreaterThanOrEqual(0, $record->timeToFirstTokenMs);
        // latencyMs is a small elapsed duration (endNs - startNs); the additive
        // mutant would make it an astronomically large hrtime sum.
        self::assertGreaterThanOrEqual(0, $record->latencyMs);
        self::assertLessThan(60000, $record->latencyMs);
        self::assertLessThan(60000, $record->timeToFirstTokenMs);
        // A completed stream is never the "aborted before completion" path.
        self::assertNull($this->logger->firstMatching('info', 'aborted before completion'));
    }

    #[Test]
    public function omitsCostWhenTheServedModelHasNoPricing(): void
    {
        $dispatcher = $this->dispatcher();

        iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
            $this->configuration('primary', providerType: 'ollama', model: $this->model(pricing: false)),
            $this->staticStream(['hi']),
        ));

        self::assertCount(1, $this->usage->calls);
        self::assertArrayNotHasKey('cost', $this->usage->calls[0]['metrics']);
    }

    #[Test]
    public function fallsBackToTheAdHocProviderMetadataWhenTheConfigHasNoProviderType(): void
    {
        $dispatcher = $this->dispatcher();

        iterator_to_array($dispatcher->stream(
            $this->context([
                StreamingDispatcher::METADATA_PROMPT_CHARS => 4,
                StreamingDispatcher::METADATA_PROVIDER      => 'groq',
            ]),
            // Transient ad-hoc configuration: empty provider type.
            $this->configuration('ad-hoc:stream:groq'),
            $this->staticStream(['x']),
        ));

        self::assertSame('groq', $this->usage->calls[0]['provider']);
        // "x" is one byte => ceil(1/4) = 1 completion token; a -1 seed for the
        // byte counter (0 tokens) or round() instead of ceil() (0 tokens) both fail this.
        self::assertSame(1, $this->usage->calls[0]['metrics']['completionTokens']);
    }

    #[Test]
    public function settlesPartialUsageWhenTheConsumerBreaksEarly(): void
    {
        $dispatcher = $this->dispatcher();

        $generator = $dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 0]),
            $this->configuration('primary', providerType: 'openai'),
            $this->staticStream(['aaaa', 'bbbb', 'cccc']),
        );

        $seen = [];
        foreach ($generator as $chunk) {
            $seen[] = $chunk;
            break; // abandon after the first chunk
        }
        // Destroying the suspended generator runs its finally (settlement).
        unset($generator);

        self::assertSame(['aaaa'], $seen);
        self::assertCount(1, $this->usage->calls, 'Partial usage must be recorded on early break.');
        // Only the first 4-char chunk was drained => 1 completion token.
        self::assertSame(1, $this->usage->calls[0]['metrics']['completionTokens']);
        // 0 prompt chars => estimateTokens(0) = 0 (the <= 0 guard returns 0).
        self::assertSame(0, $this->usage->calls[0]['metrics']['promptTokens']);
        // The served config carries no model, so modelUid falls back to 0.
        self::assertSame(0, $this->usage->calls[0]['modelUid']);

        self::assertCount(1, $this->telemetry->records);
        $record = $this->telemetry->records[0];
        self::assertFalse($record->success, 'An abandoned stream did not complete.');
        self::assertSame('', $record->errorClass, 'An early break is not an exception.');

        // The abort branch (no success, no exception) logs the partial length.
        $abort = $this->requireLog('info', 'aborted before completion');
        self::assertSame(4, $abort['context']['completionChars']);
        self::assertSame('corr-1', $abort['context']['correlationId']);
        self::assertSame('stream', $abort['context']['operation']);
    }

    #[Test]
    public function recordsFailureTelemetryAndPartialUsageWhenTheStreamThrowsMidway(): void
    {
        $dispatcher = $this->dispatcher();

        $open = function (): Generator {
            yield 'partial';

            throw new RuntimeException('mid-stream boom', 1495872185);
        };

        $caught = false;
        try {
            iterator_to_array($dispatcher->stream(
                $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 0]),
                $this->configuration('primary', providerType: 'openai'),
                $open,
            ));
        } catch (RuntimeException $e) {
            $caught = true;
            self::assertSame('mid-stream boom', $e->getMessage());
        }

        self::assertTrue($caught, 'The original mid-stream exception must propagate.');

        // Output was produced before the failure => partial usage is billed.
        self::assertCount(1, $this->usage->calls);

        self::assertCount(1, $this->telemetry->records);
        $record = $this->telemetry->records[0];
        self::assertFalse($record->success);
        self::assertSame(RuntimeException::class, $record->errorClass);
    }

    #[Test]
    public function swapsToAFallbackConfigurationWhenThePrimaryFailsBeforeTheFirstChunk(): void
    {
        $fallback = $this->configuration('fallback', providerType: 'claude', modelId: 'claude', uid: 5);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($fallback);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            modelId: 'gpt-4o',
            fallbackChain: new FallbackChain(['primary', 'fallback']),
        );

        // A generator that fails on priming (rewind) with a retryable error;
        // the fallback yields real chunks.
        $open = function (LlmConfiguration $config): Generator {
            if ($config->getIdentifier() === 'primary') {
                throw new ProviderConnectionException('primary down', 1495872186);
            }

            yield 'served';
            yield '-by-fallback';
        };

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
            $primary,
            $open,
        ));

        self::assertSame(['served', '-by-fallback'], $chunks);

        // Telemetry names the REQUESTED primary; the swap shows as a fallback attempt.
        self::assertCount(1, $this->telemetry->records);
        $record = $this->telemetry->records[0];
        self::assertSame('primary', $record->configurationIdentifier);
        self::assertSame('openai', $record->provider);
        self::assertSame(1, $record->fallbackAttempts);
        self::assertTrue($record->success);

        // Usage attributes to the configuration that actually SERVED.
        self::assertSame('claude', $this->usage->calls[0]['provider']);
        self::assertSame(5, $this->usage->calls[0]['configurationUid']);
    }

    #[Test]
    public function countsEveryDispatchedSiblingIncludingRetryablyFailedOnesBeforeSuccess(): void
    {
        $fb1 = $this->configuration('fb1', providerType: 'groq');
        $fb2 = $this->configuration('fb2', providerType: 'claude', modelId: 'claude', uid: 7);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturnMap([['fb1', $fb1], ['fb2', $fb2]]);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            fallbackChain: new FallbackChain(['fb1', 'fb2']),
        );

        // Primary and fb1 fail priming retryably; fb2 serves. Both dispatched
        // siblings must be counted, not only the winner.
        $open = function (LlmConfiguration $config): Generator {
            if ($config->getIdentifier() === 'fb2') {
                yield 'served';

                return;
            }

            throw new ProviderConnectionException($config->getIdentifier() . ' down', 1495872190);
        };

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
            $primary,
            $open,
        ));

        self::assertSame(['served'], $chunks);
        self::assertSame(2, $this->telemetry->records[0]->fallbackAttempts);
        self::assertTrue($this->telemetry->records[0]->success);
    }

    #[Test]
    public function countsEveryDispatchedSiblingOnTotalExhaustion(): void
    {
        $fb1 = $this->configuration('fb1', providerType: 'groq');
        $fb2 = $this->configuration('fb2', providerType: 'claude');

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturnMap([['fb1', $fb1], ['fb2', $fb2]]);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            fallbackChain: new FallbackChain(['fb1', 'fb2']),
        );

        // Every candidate fails priming retryably: the chain is exhausted.
        $open = static function (LlmConfiguration $config): Generator {
            yield from [];

            throw new ProviderConnectionException($config->getIdentifier() . ' down', 1495872191);
        };

        $surfaced = null;
        try {
            iterator_to_array($dispatcher->stream(
                $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
                $primary,
                $open,
            ));
        } catch (ProviderConnectionException $e) {
            $surfaced = $e;
        }

        if ($surfaced === null) {
            self::fail('An exhausted streaming fallback chain must surface the last error.');
        }
        // The LAST retryable exception is surfaced (the ?? fallback default is
        // never reached because $lastRetryable is set) — code/message prove the
        // actual candidate error propagated, not a freshly built default.
        self::assertSame(1495872191, $surfaced->getCode());
        self::assertSame('fb2 down', $surfaced->getMessage());
        self::assertSame([], $this->usage->calls, 'A stream that produced nothing bills no usage.');
        self::assertSame(2, $this->telemetry->records[0]->fallbackAttempts);
        self::assertFalse($this->telemetry->records[0]->success);
    }

    #[Test]
    public function doesNotFallBackAfterTheFirstChunkHasBeenYielded(): void
    {
        $fallback = $this->configuration('fallback', providerType: 'claude');

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($fallback);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            fallbackChain: new FallbackChain(['primary', 'fallback']),
        );

        // Fails AFTER the first chunk: a provider swap is no longer possible.
        $open = function (LlmConfiguration $config): Generator {
            yield 'first';

            throw new ProviderConnectionException('dies mid-stream', 1495872187);
        };

        $seen   = [];
        $caught = false;
        try {
            foreach ($dispatcher->stream($this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 0]), $primary, $open) as $chunk) {
                $seen[] = $chunk;
            }
        } catch (ProviderConnectionException) {
            $caught = true;
        }

        self::assertTrue($caught, 'A post-first-chunk failure must surface, not fall back.');
        self::assertSame(['first'], $seen);
        self::assertSame(0, $this->telemetry->records[0]->fallbackAttempts);
        self::assertFalse($this->telemetry->records[0]->success);
    }

    #[Test]
    public function propagatesANonRetryableFirstChunkFailureWithoutFallbackOrUsage(): void
    {
        $dispatcher = $this->dispatcher();

        // 4xx rate-unrelated response => non-retryable. Thrown on priming
        // (rewind) — `yield from []` makes this a generator so the throw fires
        // when the stream is primed, exercising the isRetryable=false branch.
        $open = static function (): Generator {
            yield from [];

            throw new ProviderResponseException('bad request', 400);
        };

        $caught = false;
        try {
            iterator_to_array($dispatcher->stream(
                $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
                $this->configuration('primary', providerType: 'openai'),
                $open,
            ));
        } catch (ProviderResponseException) {
            $caught = true;
        }

        self::assertTrue($caught);
        self::assertSame([], $this->usage->calls, 'A stream that produced nothing bills no usage.');
        self::assertCount(1, $this->telemetry->records);
        self::assertFalse($this->telemetry->records[0]->success);
        self::assertSame(ProviderResponseException::class, $this->telemetry->records[0]->errorClass);
    }

    #[Test]
    public function stillRecordsUsageWhenTelemetryIsDisabled(): void
    {
        $dispatcher = $this->dispatcher(extensionConfiguration: $this->extensionConfiguration(false));

        iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
            $this->configuration('primary', providerType: 'openai'),
            $this->staticStream(['ok']),
        ));

        self::assertCount(1, $this->usage->calls, 'Usage/budget accounting is independent of the telemetry toggle.');
        self::assertSame([], $this->telemetry->records, 'Telemetry must honour the disabled setting.');
    }

    #[Test]
    public function propagatesANonRetryablePrimingFailureWithoutDispatchingTheFallback(): void
    {
        $fallback = $this->configuration('fallback', providerType: 'claude', modelId: 'claude', uid: 5);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($fallback);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            modelId: 'gpt-4o',
            fallbackChain: new FallbackChain(['primary', 'fallback']),
        );

        // 400 on priming => non-retryable: it must throw immediately, never
        // reaching the fallback candidate.
        $open = static function (LlmConfiguration $config): Generator {
            if ($config->getIdentifier() === 'primary') {
                yield from [];

                throw new ProviderResponseException('bad request', 400);
            }

            yield 'must-not-be-served';
        };

        $caught = false;
        try {
            iterator_to_array($dispatcher->stream(
                $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
                $primary,
                $open,
            ));
        } catch (ProviderResponseException) {
            $caught = true;
        }

        self::assertTrue($caught, 'A non-retryable priming failure must propagate.');
        self::assertSame([], $this->usage->calls, 'No candidate served, so nothing is billed.');
        self::assertCount(1, $this->telemetry->records);
        self::assertFalse($this->telemetry->records[0]->success);
        self::assertSame(0, $this->telemetry->records[0]->fallbackAttempts, 'The fallback must not be dispatched.');
        self::assertSame(ProviderResponseException::class, $this->telemetry->records[0]->errorClass);
    }

    #[Test]
    public function treatsARateLimitedPrimingFailureAsRetryableAndSwapsToTheFallback(): void
    {
        $fallback = $this->configuration('fallback', providerType: 'claude', modelId: 'claude', uid: 5);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($fallback);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            modelId: 'gpt-4o',
            fallbackChain: new FallbackChain(['primary', 'fallback']),
        );

        // HTTP 429 on priming: a sibling provider might not be throttled, so a
        // ProviderResponseException with code 429 is the one that IS retryable.
        $open = static function (LlmConfiguration $config): Generator {
            if ($config->getIdentifier() === 'primary') {
                yield from [];

                throw new ProviderResponseException('rate limited', 429);
            }

            yield 'served-by-fallback';
        };

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
            $primary,
            $open,
        ));

        self::assertSame(['served-by-fallback'], $chunks, 'A 429 primary must fall back, not surface.');
        self::assertCount(1, $this->telemetry->records);
        self::assertTrue($this->telemetry->records[0]->success);
        self::assertSame(1, $this->telemetry->records[0]->fallbackAttempts);
        self::assertSame('claude', $this->usage->calls[0]['provider']);

        // The retry decision is logged with the failing configuration named.
        $warning = $this->requireLog('warning', 'attempt failed before first chunk');
        self::assertSame('primary', $warning['context']['configuration']);
        self::assertSame('corr-1', $warning['context']['correlationId']);
        self::assertSame('stream', $warning['context']['operation']);
    }

    #[Test]
    public function skipsAnInactiveFallbackConfiguration(): void
    {
        // Present but inactive: candidates() must drop it, like FallbackMiddleware.
        $inactive = $this->configuration('fb1', providerType: 'claude', active: false);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($inactive);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            fallbackChain: new FallbackChain(['primary', 'fb1']),
        );

        // Primary fails priming retryably; fb1 would serve IF it were considered.
        $open = static function (LlmConfiguration $config): Generator {
            if ($config->getIdentifier() === 'primary') {
                yield from [];

                throw new ProviderConnectionException('primary down', 1495872199);
            }

            yield 'must-not-be-served';
        };

        $caught = false;
        try {
            iterator_to_array($dispatcher->stream(
                $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
                $primary,
                $open,
            ));
        } catch (ProviderConnectionException) {
            $caught = true;
        }

        self::assertTrue($caught, 'With the only fallback inactive, the chain is exhausted and surfaces.');
        self::assertSame([], $this->usage->calls, 'The inactive fallback never served, so nothing is billed.');
        self::assertCount(1, $this->telemetry->records);
        self::assertFalse($this->telemetry->records[0]->success);
        self::assertSame(0, $this->telemetry->records[0]->fallbackAttempts, 'An inactive fallback is not dispatched.');
    }

    #[Test]
    public function attributesNoConfigurationUidWhenTheServedConfigurationIsUnpersisted(): void
    {
        $dispatcher = $this->dispatcher();

        iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
            // A transient / unpersisted configuration reports uid 0.
            $this->configuration('primary', providerType: 'openai', uid: 0),
            $this->staticStream(['ok']),
        ));

        self::assertCount(1, $this->usage->calls);
        self::assertNull(
            $this->usage->calls[0]['configurationUid'],
            'A uid of 0 (unpersisted) must map to a null configuration attribution.',
        );
    }

    #[Test]
    public function fallsBackToUnknownProviderWhenNeitherConfigNorMetadataNamesOne(): void
    {
        $dispatcher = $this->dispatcher();

        iterator_to_array($dispatcher->stream(
            $this->context([
                StreamingDispatcher::METADATA_PROMPT_CHARS => 4,
                // Present but empty: a blank string names no provider.
                StreamingDispatcher::METADATA_PROVIDER     => '',
            ]),
            $this->configuration('ad-hoc:stream', providerType: ''),
            $this->staticStream(['x']),
        ));

        self::assertSame('unknown', $this->usage->calls[0]['provider']);
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    /**
     * The first captured log record of the given level whose message contains
     * $needle. Fails the test (never-returning) when none matched, so the
     * caller can use the returned array without a null check.
     *
     * @return array{level: mixed, message: string, context: array<array-key, mixed>}
     */
    private function requireLog(string $level, string $needle): array
    {
        $record = $this->logger->firstMatching($level, $needle);
        if ($record === null) {
            self::fail(sprintf('Expected a "%s" log containing "%s".', $level, $needle));
        }

        return $record;
    }

    /**
     * @param iterable<GuardrailInterface> $guardrails
     */
    private function dispatcher(
        ?BudgetServiceInterface $budget = null,
        ?LlmConfigurationRepository $repository = null,
        ?ExtensionConfiguration $extensionConfiguration = null,
        iterable $guardrails = [],
    ): StreamingDispatcher {
        return new StreamingDispatcher(
            $budget ?? $this->budget(BudgetCheckResult::allowed()),
            $this->usage,
            $this->telemetry,
            $repository ?? self::createStub(LlmConfigurationRepository::class),
            $this->logger,
            $this->contextWithAmbientUser(0),
            $extensionConfiguration ?? $this->extensionConfiguration(true),
            guardrails: $guardrails,
        );
    }

    private function budget(BudgetCheckResult $result): BudgetServiceInterface
    {
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn($result);

        return $budget;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function context(array $metadata = []): ProviderCallContext
    {
        return new ProviderCallContext(ProviderOperation::Stream, 'corr-1', metadata: $metadata);
    }

    /**
     * @param list<string> $chunks
     *
     * @return callable(LlmConfiguration): Generator<int, string, mixed, void>
     */
    private function staticStream(array $chunks): callable
    {
        return static function () use ($chunks): Generator {
            yield from $chunks;
        };
    }

    private function configuration(
        string $identifier,
        string $providerType = '',
        string $modelId = '',
        ?Model $model = null,
        ?int $uid = null,
        ?FallbackChain $fallbackChain = null,
        bool $active = true,
    ): LlmConfiguration&MockObject {
        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('getIdentifier')->willReturn($identifier);
        $configuration->method('getProviderType')->willReturn($providerType);
        $configuration->method('getModelId')->willReturn($modelId);
        $configuration->method('getLlmModel')->willReturn($model);
        $configuration->method('getUid')->willReturn($uid);
        $configuration->method('isActive')->willReturn($active);
        $configuration->method('getFallbackChainDTO')->willReturn($fallbackChain ?? new FallbackChain());

        return $configuration;
    }

    private function model(bool $pricing, float $cost = 0.0): Model&MockObject
    {
        $model = $this->createMock(Model::class);
        $model->method('getUid')->willReturn(3);
        $model->method('hasPricing')->willReturn($pricing);
        $model->method('estimateCost')->willReturn($cost);

        return $model;
    }

    private function extensionConfiguration(bool $enabled): ExtensionConfiguration&MockObject
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')
            ->willReturn(['telemetry' => ['enabled' => $enabled ? '1' : '0']]);

        return $extensionConfiguration;
    }

    private function contextWithAmbientUser(int $id): Context
    {
        $aspect = self::createStub(AspectInterface::class);
        $aspect->method('get')->willReturn($id);

        $context = self::createStub(Context::class);
        $context->method('getAspect')->willReturn($aspect);

        return $context;
    }
}
