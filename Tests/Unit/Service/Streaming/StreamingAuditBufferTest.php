<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Streaming;

use Generator;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Tests\Fixture\GuardrailIdentityDoubleTrait;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(StreamingDispatcher::class)]
final class StreamingAuditBufferTest extends AbstractUnitTestCase
{
    /**
     * @param list<string> $chunks
     * @param list<string> $expectedAudit
     */
    #[Test]
    #[DataProvider('streams')]
    public function auditPrefixIsBoundedWhileDeliveryAndAccountingKeepAllRawBytes(
        array $chunks,
        array $expectedAudit,
        int $expectedCompletionTokens,
    ): void {
        $guardrail = new class implements GuardrailInterface {
            use GuardrailIdentityDoubleTrait;

            /** @var list<string> */
            public array $screened = [];

            public function checkOutput(
                CompletionResponse $response,
            ): GuardrailResult {
                $this->screened[] = $response->content;
                return GuardrailResult::allow();
            }
        };
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(BudgetCheckResult::allowed());
        $settings = self::createStub(ExtensionConfiguration::class);
        $settings
            ->method('get')
            ->willReturn(['telemetry' => ['enabled' => '0']]);
        $usage = new RecordingUsageTracker();
        $dispatcher = new StreamingDispatcher(
            $budget,
            $usage,
            new InMemoryTelemetryRepository(),
            new FallbackCandidateResolver(
                self::createStub(LlmConfigurationRepository::class),
            ),
            new RecordingLogger(),
            new Context(),
            $settings,
            guardrails: [$guardrail],
        );
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('audit-prefix');

        $opener = static function () use ($chunks): Generator {
            yield from $chunks;
        };
        $delivered = iterator_to_array(
            $dispatcher->stream(
                new ProviderCallContext(
                    ProviderOperation::Stream,
                    'audit-prefix',
                ),
                $configuration,
                $opener,
            ),
        );

        self::assertSame($chunks, $delivered);
        self::assertSame($expectedAudit, $guardrail->screened);
        self::assertCount(1, $usage->calls);
        self::assertSame(0, $usage->calls[0]['metrics']['promptTokens']);
        self::assertSame(
            $expectedCompletionTokens,
            $usage->calls[0]['metrics']['completionTokens'],
        );
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, int}>
     */
    public static function streams(): iterable
    {
        yield 'single oversized delta' => [[str_repeat('a', 50000) . str_repeat('z', 25000)], [str_repeat('a', 50000)], 18750];
        yield 'later delta crosses the bound' => [
            [str_repeat('a', 49990), str_repeat('b', 10) . str_repeat('z', 30), str_repeat('c', 20)],
            [str_repeat('a', 49990) . str_repeat('b', 10)],
            12513,
        ];
        yield 'short response retained in full' => [['a dozen....!', 'more'], ['a dozen....!more'], 4];
        yield 'exact bound followed by more output' => [
            [
                str_repeat('a', 40000),
                str_repeat('b', 10000),
                str_repeat('c', 12),
            ],
            [str_repeat('a', 40000) . str_repeat('b', 10000)],
            12503,
        ];
        yield 'filled first delta excludes the tail' => [
            [str_repeat('a', 50000), str_repeat('b', 10000)],
            [str_repeat('a', 50000)],
            15000,
        ];
        yield 'empty successful stream has no audit text' => [[], [], 0];
    }
}
