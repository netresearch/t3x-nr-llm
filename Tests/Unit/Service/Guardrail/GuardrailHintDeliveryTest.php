<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail;

use Generator;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailHint;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailBlockRecorder;
use Netresearch\NrLlm\Service\Guardrail\StreamAnnotatingGuardrailInterface;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;

/**
 * A hint reaches the caller on every path that delivers a text, and a stored
 * block keeps the policy facts rather than the quoted text (ADR-288).
 */
#[CoversClass(GuardrailMiddleware::class)]
#[CoversClass(StreamingDispatcher::class)]
#[CoversClass(GuardrailBlockRecorder::class)]
#[CoversClass(GuardrailHint::class)]
#[CoversClass(GuardrailResult::class)]
final class GuardrailHintDeliveryTest extends AbstractUnitTestCase
{
    #[Test]
    public function aDeliveredResponseCarriesTheHintsInItsMetadata(): void
    {
        $result = (new GuardrailMiddleware([$this->annotator(GuardrailResult::allowWithHints([$this->hint()]))]))->handle(
            $this->context(),
            static fn (): CompletionResponse => new CompletionResponse('Text', 'm', UsageStatistics::fromTokens(1, 1), metadata: ['kept' => 1]),
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame(1, $result->metadata['kept'] ?? null);
        self::assertSame([$this->hint()->toArray()], $result->metadata[GuardrailHint::METADATA_KEY] ?? null);
        self::assertEquals([$this->hint()], GuardrailHint::fromMetadata($result->metadata));
    }

    #[Test]
    public function aReplayedAnswerLosesTheHintsOfAnEarlierScreen(): void
    {
        $result = (new GuardrailMiddleware([$this->annotator(GuardrailResult::allow())]))->handle(
            $this->context(),
            fn (): CompletionResponse => new CompletionResponse('Text', 'm', UsageStatistics::fromTokens(1, 1), metadata: [GuardrailHint::METADATA_KEY => [$this->hint()->toArray()]]),
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertArrayNotHasKey(GuardrailHint::METADATA_KEY, $result->metadata ?? []);
    }

    #[Test]
    public function aResponseWithoutHintsIsHandedOnAsItCame(): void
    {
        $response = new CompletionResponse('Text', 'm', UsageStatistics::fromTokens(1, 1));

        self::assertSame($response, (new GuardrailMiddleware([$this->annotator(GuardrailResult::allow())]))->handle($this->context(), static fn (): CompletionResponse => $response));
    }

    #[Test]
    public function anAnnotatedStreamIsDeliveredLiveAndReturnsItsHints(): void
    {
        $seenAtTheEnd = '';
        $stream       = $this->dispatcher($this->annotator(GuardrailResult::allowWithHints([$this->hint()]), function (string $text) use (&$seenAtTheEnd): void {
            $seenAtTheEnd = $text;
        }))->stream($this->context(), $this->configuration(), $this->opener());

        $chunks = [];
        foreach ($stream as $chunk) {
            $chunks[] = $chunk;
        }

        self::assertSame(['Hallo ', 'du'], $chunks);
        self::assertSame('Hallo du', $seenAtTheEnd);
        self::assertEquals([$this->hint()], GuardrailHint::fromStreamReturn($stream->getReturn()));
    }

    #[Test]
    public function aBlockingVerdictOnADeliveredStreamIsOnlyLogged(): void
    {
        $logger = new RecordingLogger();
        $stream = $this->dispatcher($this->annotator(GuardrailResult::deny('late')), logger: $logger)->stream($this->context(), $this->configuration(), $this->opener());

        self::assertSame(['Hallo ', 'du'], iterator_to_array($stream, false));
        self::assertNotNull($logger->firstMatching('warning', 'already delivered'));
    }

    #[Test]
    public function aHeldStreamReturnsTheHintsOfTheGuardrailsThatLetItPass(): void
    {
        $stream = $this->dispatcher($this->annotator(GuardrailResult::allowWithHints([$this->hint()]), holds: true))->stream($this->context(), $this->configuration(), $this->opener());

        self::assertSame(['Hallo du'], iterator_to_array($stream, false));
        self::assertEquals([$this->hint()], GuardrailHint::fromStreamReturn($stream->getReturn()));
    }

    #[Test]
    public function aStreamWithoutAnnotatorsReturnsNoHints(): void
    {
        $stream = $this->dispatcher(null)->stream($this->context(), $this->configuration(), $this->opener());

        self::assertSame(['Hallo ', 'du'], iterator_to_array($stream, false));
        self::assertSame([], GuardrailHint::fromStreamReturn($stream->getReturn()));
    }

    #[Test]
    public function aStoredBlockKeepsTheAuditReasonAndTheEditorReadsTheQuote(): void
    {
        $events     = [];
        $repository = self::createStub(GovernanceEventRepositoryInterface::class);
        $repository->method('record')->willReturnCallback(static function (GovernanceEvent $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            (new GuardrailMiddleware([$this->annotator(GuardrailResult::deny('Quote: "Hallo du"', 'Rule 1 broken'))], governanceEvents: $repository))->handle(
                $this->context(),
                static fn (): CompletionResponse => new CompletionResponse('Hallo du', 'm', UsageStatistics::fromTokens(1, 1)),
            );
            self::fail('Not blocked.');
        } catch (GuardrailViolationException $blocked) {
            self::assertSame('Quote: "Hallo du"', $blocked->getMessage());
        }

        self::assertCount(1, $events);
        self::assertSame('Rule 1 broken', $events[0]->detail);
    }

    #[Test]
    public function aBlockWithoutAnAuditReasonStoresItsReason(): void
    {
        self::assertNull(GuardrailResult::deny('x')->auditReason);
        self::assertSame([], GuardrailResult::allow()->hints);
    }

    #[Test]
    public function metadataThatIsNoHintIsSkipped(): void
    {
        self::assertSame([], GuardrailHint::fromMetadata(null));
        self::assertSame([], GuardrailHint::fromMetadata([GuardrailHint::METADATA_KEY => 'x']));
        self::assertCount(1, GuardrailHint::fromMetadata([GuardrailHint::METADATA_KEY => [['kind' => 'violation'], ['kind' => 'violation', 'message' => 'm'], 'x']]));
        self::assertSame([], GuardrailHint::fromStreamReturn(null));
        self::assertSame([], GuardrailHint::fromStreamReturn(['x']));
    }

    private function hint(): GuardrailHint
    {
        return new GuardrailHint('rule-set-judge', GuardrailHint::KIND_VIOLATION, 'Rule "Siezen" at "Hallo du"', 'Siezen', 'Hallo du');
    }

    /**
     * @param (callable(string): void)|null $onCheck
     */
    private function annotator(GuardrailResult $result, ?callable $onCheck = null, bool $holds = false): StreamAnnotatingGuardrailInterface
    {
        return new class ($result, $onCheck, $holds) implements StreamAnnotatingGuardrailInterface {
            /** @var (callable(string): void)|null */
            private readonly mixed $onCheck;

            /**
             * @param (callable(string): void)|null $onCheck
             */
            public function __construct(private readonly GuardrailResult $result, ?callable $onCheck, private readonly bool $holds)
            {
                $this->onCheck = $onCheck;
            }

            public function getIdentifier(): string
            {
                return 'annotator';
            }

            public function isMandatory(): bool
            {
                return true;
            }

            public function checkOutput(CompletionResponse $response): GuardrailResult
            {
                return GuardrailResult::allow();
            }

            public function checkOutputInContext(CompletionResponse $response, ProviderCallContext $context): GuardrailResult
            {
                if ($this->onCheck !== null) {
                    ($this->onCheck)($response->content);
                }

                return $this->result;
            }

            public function holdsStream(ProviderCallContext $context): bool
            {
                return $this->holds;
            }

            public function annotatesStream(ProviderCallContext $context): bool
            {
                return !$this->holds;
            }
        };
    }

    private function context(): ProviderCallContext
    {
        return new ProviderCallContext(ProviderOperation::Chat, 'c', $this->configuration());
    }

    private function configuration(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', 3);

        return $configuration;
    }

    /**
     * @return callable(): Generator<int, string, mixed, void>
     */
    private function opener(): callable
    {
        return static function (): Generator {
            yield 'Hallo ';
            yield 'du';
        };
    }

    private function dispatcher(?StreamAnnotatingGuardrailInterface $guardrail, ?RecordingLogger $logger = null): StreamingDispatcher
    {
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(BudgetCheckResult::allowed());
        $settings = self::createStub(ExtensionConfiguration::class);
        $settings->method('get')->willReturn(['telemetry' => ['enabled' => '0']]);

        return new StreamingDispatcher(
            $budget,
            new RecordingUsageTracker(),
            new InMemoryTelemetryRepository(),
            new FallbackCandidateResolver(self::createStub(LlmConfigurationRepository::class)),
            $logger ?? new RecordingLogger(),
            new Context(),
            $settings,
            guardrails: $guardrail instanceof StreamAnnotatingGuardrailInterface ? [$guardrail] : [],
        );
    }
}
