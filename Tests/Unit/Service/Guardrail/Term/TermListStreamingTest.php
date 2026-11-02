<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Guardrail\Term;

use Generator;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\CallMetadataFactory;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListGuardrail;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListMessages;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListRule;
use Netresearch\NrLlm\Service\Guardrail\Term\TermMatcher;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Service\Streaming\ToolRoundTextRelay;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * A list naming the chat's configuration leaves its live text running, and a
 * direct stream on the same configuration stays held (ADR-238, ADR-240). The
 * real guardrail in the real dispatcher, with the metadata the manager builds.
 */
#[CoversClass(TermListGuardrail::class)]
#[CoversClass(StreamingDispatcher::class)]
final class TermListStreamingTest extends AbstractUnitTestCase
{
    #[Test]
    public function aListNamingTheChatConfigurationLeavesItsLiveTextRunning(): void
    {
        $received = [];

        $relay = $this->dispatcher()->toolTextRelay(
            $this->call(ProviderOperation::Tools, (new AgentRunReference(5, 'u'))->offeringTools(['search_records'])),
            $this->configuration(),
            static function (string $text) use (&$received): void {
                $received[] = $text;
            },
        );

        self::assertInstanceOf(ToolRoundTextRelay::class, $relay);
        $relay->begin();
        $relay->push('Ein Rabatt für alle ');
        $relay->finish();
        self::assertStringContainsString('Rabatt', implode('', $received));
    }

    #[Test]
    public function theSameListHoldsTheTextOfALoopOutsideARun(): void
    {
        self::assertNull($this->dispatcher()->toolTextRelay(
            $this->call(ProviderOperation::Tools, null),
            $this->configuration(),
            static function (): void {},
        ));
    }

    #[Test]
    public function theSameListHoldsTheTextOfARunGivenNoTools(): void
    {
        self::assertNull($this->dispatcher()->toolTextRelay(
            $this->call(ProviderOperation::Tools, new AgentRunReference(5, 'u')),
            $this->configuration(),
            static function (): void {},
        ));
    }

    #[Test]
    public function aDirectStreamWithTheTermIsHeldAndDeliversNothing(): void
    {
        $delivered = [];

        try {
            foreach ($this->dispatcher()->stream($this->call(ProviderOperation::Stream, null), $this->configuration(), $this->opener()) as $chunk) {
                $delivered[] = $chunk;
            }

            self::fail('The stream was not blocked.');
        } catch (GuardrailViolationException) {
            self::assertSame([], $delivered);
        }
    }

    #[Test]
    public function aStreamInsideAChatArrivesInItsChunks(): void
    {
        $stream = $this->dispatcher()->stream(
            $this->call(ProviderOperation::Stream, (new AgentRunReference(5, 'u'))->offeringTools([])),
            $this->configuration(),
            $this->opener(),
        );

        self::assertSame(['Ein ', 'Rabatt'], iterator_to_array($stream, false));
    }

    private function call(ProviderOperation $operation, ?AgentRunReference $run): ProviderCallContext
    {
        return new ProviderCallContext($operation, 'c', metadata: (new CallMetadataFactory())->agentRun($run));
    }

    private function configuration(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', 7);

        return $configuration;
    }

    /**
     * @return callable(): Generator<int, string, mixed, void>
     */
    private function opener(): callable
    {
        return static function (): Generator {
            yield 'Ein ';
            yield 'Rabatt';
        };
    }

    private function dispatcher(): StreamingDispatcher
    {
        $resolver = self::createStub(TermListResolverInterface::class);
        $resolver->method('forConfiguration')->willReturn([new TermListRule('Marke', '', '', ['Rabatt'], [], '')]);
        $language = self::createStub(LanguageService::class);
        $language->method('sL')->willReturn('blocked %1$s %2$s');
        $factory = self::createStub(LanguageServiceFactory::class);
        $factory->method('createFromUserPreferences')->willReturn($language);
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(BudgetCheckResult::allowed());
        $settings = self::createStub(ExtensionConfiguration::class);
        $settings->method('get')->willReturn(['telemetry' => ['enabled' => '0']]);

        return new StreamingDispatcher(
            $budget,
            new RecordingUsageTracker(),
            new InMemoryTelemetryRepository(),
            new FallbackCandidateResolver(self::createStub(LlmConfigurationRepository::class)),
            new RecordingLogger(),
            new Context(),
            $settings,
            guardrails: [new TermListGuardrail($resolver, new TermMatcher(), new TermListMessages($factory), new RecordingLogger())],
        );
    }
}
