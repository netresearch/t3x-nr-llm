<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Streaming;

use Generator;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryGovernanceEventRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use Netresearch\NrLlm\Tests\Unit\Service\Streaming\Fixture\HoldingGuardrail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;

/**
 * A stream a contextual guardrail asks for is held back and screened whole
 * (ADR-238); any other stream arrives as before.
 */
#[CoversClass(StreamingDispatcher::class)]
final class StreamingTermHoldTest extends AbstractUnitTestCase
{
    #[Test]
    public function aHeldStreamIsReleasedAsOneChunkAfterTheVerdict(): void
    {
        $guardrail = new HoldingGuardrail(GuardrailResult::allow());

        $delivered = iterator_to_array($this->dispatcher($guardrail)->stream($this->context(), $this->configuration(), $this->opener()), false);

        self::assertSame(['Ein langer Text'], $delivered);
        self::assertSame(['Ein langer Text'], $guardrail->screened);
    }

    #[Test]
    public function aBlockedStreamDeliversNothingAndIsRecorded(): void
    {
        $events     = new InMemoryGovernanceEventRepository();
        $dispatcher = $this->dispatcher(new HoldingGuardrail(GuardrailResult::deny('Der Grund')), $events);
        $delivered  = [];

        try {
            foreach ($dispatcher->stream($this->context(), $this->configuration(), $this->opener()) as $chunk) {
                $delivered[] = $chunk;
            }

            self::fail('The stream was not blocked.');
        } catch (GuardrailViolationException $blocked) {
            self::assertSame('Der Grund', $blocked->getMessage());
        }

        self::assertSame([], $delivered);
        self::assertCount(1, $events->recorded);
        self::assertSame(GovernanceDecision::RESPONSE_BLOCKED->value, $events->recorded[0]->decision);
        self::assertSame('Der Grund', $events->recorded[0]->detail);
    }

    #[Test]
    public function theProviderIsCountedWhenAGuardrailRefusesItsText(): void
    {
        $usage      = new RecordingUsageTracker();
        $dispatcher = $this->dispatcher(new HoldingGuardrail(GuardrailResult::deny('Der Grund')), null, $usage);

        try {
            iterator_to_array($dispatcher->stream($this->context(), $this->configuration(), $this->opener()), false);
            self::fail('The stream was not blocked.');
        } catch (GuardrailViolationException) {
        }

        self::assertCount(1, $usage->calls);
    }

    #[Test]
    public function aGuardrailThatAsksForRetryOnAFinishedStreamBlocks(): void
    {
        $this->expectException(GuardrailViolationException::class);

        iterator_to_array($this->dispatcher(new HoldingGuardrail(GuardrailResult::retry('nochmal')))->stream($this->context(), $this->configuration(), $this->opener()), false);
    }

    #[Test]
    public function aRedactVerdictReplacesTheHeldText(): void
    {
        $delivered = iterator_to_array(
            $this->dispatcher(new HoldingGuardrail(GuardrailResult::redact('anders')))->stream($this->context(), $this->configuration(), $this->opener()),
            false,
        );

        self::assertSame(['anders'], $delivered);
    }

    #[Test]
    public function aStreamNoGuardrailHoldsArrivesInItsChunks(): void
    {
        $guardrail = new HoldingGuardrail(GuardrailResult::deny('nie'), holds: false);

        $delivered = iterator_to_array($this->dispatcher($guardrail)->stream($this->context(), $this->configuration(), $this->opener()), false);

        self::assertSame(['Ein ', 'langer ', 'Text'], $delivered);
        self::assertSame([], $guardrail->screened);
    }

    #[Test]
    public function theGuardrailSeesTheConfigurationOfTheStream(): void
    {
        $guardrail     = new HoldingGuardrail(GuardrailResult::allow());
        $configuration = $this->configuration();

        iterator_to_array($this->dispatcher($guardrail)->stream($this->context(), $configuration, $this->opener()), false);

        self::assertSame($configuration, $guardrail->seenConfiguration);
    }

    private function context(): ProviderCallContext
    {
        return new ProviderCallContext(ProviderOperation::Stream, 'held', metadata: [ProviderCallContext::METADATA_SITE => 'shop']);
    }

    private function configuration(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('held');

        return $configuration;
    }

    /**
     * @return callable(): Generator<int, string, mixed, void>
     */
    private function opener(): callable
    {
        return static function (): Generator {
            yield 'Ein ';
            yield 'langer ';
            yield 'Text';
        };
    }

    private function dispatcher(HoldingGuardrail $guardrail, ?GovernanceEventRepositoryInterface $events = null, ?RecordingUsageTracker $usage = null): StreamingDispatcher
    {
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(BudgetCheckResult::allowed());
        $settings = self::createStub(ExtensionConfiguration::class);
        $settings->method('get')->willReturn(['telemetry' => ['enabled' => '0']]);

        return new StreamingDispatcher(
            $budget,
            $usage ?? new RecordingUsageTracker(),
            new InMemoryTelemetryRepository(),
            new FallbackCandidateResolver(self::createStub(LlmConfigurationRepository::class)),
            new RecordingLogger(),
            new Context(),
            $settings,
            guardrails: [$guardrail],
            governanceEvents: $events,
        );
    }
}
