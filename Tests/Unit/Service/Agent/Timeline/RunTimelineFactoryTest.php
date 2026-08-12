<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\Timeline;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Service\Agent\Timeline\RunTimelineEntry;
use Netresearch\NrLlm\Service\Agent\Timeline\RunTimelineFactory;
use Netresearch\NrLlm\Service\Governance\RecordedGovernanceEvent;
use Netresearch\NrLlm\Service\Telemetry\TelemetryCall;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryGovernanceEventRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunTimelineFactory::class)]
final class RunTimelineFactoryTest extends TestCase
{
    private const RUN_UUID = 'c0ffee00-0000-4000-8000-000000000042';

    #[Test]
    public function theThreeStreamsAreJoinedOnTheRunAndOrderedInTime(): void
    {
        $telemetry = new InMemoryTelemetryRepository();
        $telemetry->callsByCorrelation[self::RUN_UUID] = [$this->call(1_700_000_020)];

        $governance             = new InMemoryGovernanceEventRepository();
        $governance->runEvents  = [$this->governanceEvent(1_700_000_030)];

        $factory = new RunTimelineFactory($telemetry, $governance);

        $timeline = $factory->build($this->agentRun(), [
            $this->event(0, 'request', 1_700_000_010),
            $this->event(1, 'llm', 1_700_000_020),
        ]);

        self::assertSame(
            [
                RunTimelineEntry::SOURCE_STEP,
                RunTimelineEntry::SOURCE_STEP,
                RunTimelineEntry::SOURCE_CALL,
                RunTimelineEntry::SOURCE_GOVERNANCE,
            ],
            array_map(static fn(RunTimelineEntry $e): string => $e->source, $timeline),
        );

        // Both keys of the run's identity are used, because the three write
        // points know different halves of it.
        self::assertSame(self::RUN_UUID, $telemetry->correlationAsked);
        self::assertSame(7, $governance->runUidAsked);
        self::assertSame(self::RUN_UUID, $governance->runCorrelationAsked);
    }

    #[Test]
    public function aRowWithoutASequenceSortsAfterTheStepOfTheSameSecond(): void
    {
        // A provider call happens inside the step that triggered it, and both
        // land on the same crdate; the step must stay first.
        $telemetry = new InMemoryTelemetryRepository();
        $telemetry->callsByCorrelation[self::RUN_UUID] = [$this->call(1_700_000_010)];

        $factory = new RunTimelineFactory($telemetry, new InMemoryGovernanceEventRepository());

        $timeline = $factory->build($this->agentRun(), [$this->event(3, 'llm', 1_700_000_010)]);

        self::assertSame(RunTimelineEntry::SOURCE_STEP, $timeline[0]->source);
        self::assertSame(RunTimelineEntry::SOURCE_CALL, $timeline[1]->source);
    }

    #[Test]
    public function aStepContributesItsMetadataAndNeverItsContent(): void
    {
        $factory = new RunTimelineFactory(new InMemoryTelemetryRepository(), new InMemoryGovernanceEventRepository());

        // A payload as an installation at PrivacyLevel::FULL would store it —
        // the content keys are present and must still not be rendered.
        $timeline = $factory->build($this->agentRun(), [
            new AgentRunEvent(
                uid: 5,
                run: 7,
                sequence: 0,
                kind: 'llm',
                round: 1,
                durationMs: 12.5,
                payload: [
                    'kind'             => 'llm',
                    'finishReason'     => 'stop',
                    'promptTokens'     => 120,
                    'completionTokens' => 30,
                    'content'          => 'the secret answer',
                    'thinking'         => 'the secret reasoning',
                    'messagesSent'     => [['role' => 'user', 'content' => 'the secret prompt']],
                    'toolArguments'    => ['path' => '/etc/passwd'],
                    'toolResult'       => 'root:x:0:0',
                    'raw'              => ['choices' => []],
                ],
                crdate: 1_700_000_010,
            ),
        ]);

        $detail = $timeline[0]->detail;
        self::assertStringContainsString('finishReason=stop', $detail);
        self::assertStringContainsString('promptTokens=120', $detail);
        self::assertStringNotContainsString('secret', $detail);
        self::assertStringNotContainsString('passwd', $detail);
        self::assertStringNotContainsString('root:x', $detail);
    }

    #[Test]
    public function aFailedToolStepIsMarkedFailedAndASuccessfulOneOk(): void
    {
        $factory = new RunTimelineFactory(new InMemoryTelemetryRepository(), new InMemoryGovernanceEventRepository());

        $timeline = $factory->build($this->agentRun(), [
            $this->toolEvent(0, true, 1_700_000_010),
            $this->toolEvent(1, false, 1_700_000_011),
        ]);

        self::assertSame(RunTimelineEntry::OUTCOME_FAILED, $timeline[0]->outcome);
        self::assertSame(RunTimelineEntry::OUTCOME_OK, $timeline[1]->outcome);
    }

    #[Test]
    public function aStepThatStatesNoOutcomeGetsNone(): void
    {
        $factory = new RunTimelineFactory(new InMemoryTelemetryRepository(), new InMemoryGovernanceEventRepository());

        $timeline = $factory->build($this->agentRun(), [$this->event(0, 'request', 1_700_000_010)]);

        self::assertSame(RunTimelineEntry::OUTCOME_NONE, $timeline[0]->outcome);
    }

    #[Test]
    public function aCallThatWasNotSwappedDoesNotRepeatItselfAsItsOwnServer(): void
    {
        $telemetry = new InMemoryTelemetryRepository();
        $telemetry->callsByCorrelation[self::RUN_UUID] = [
            $this->call(1_700_000_020),
            new TelemetryCall(
                operation: 'tools',
                provider: 'openai',
                model: 'gpt-5',
                servedProvider: 'ollama',
                servedModel: 'qwen3:4b',
                success: true,
                errorClass: '',
                latencyMs: 900,
                cacheHit: false,
                fallbackAttempts: 1,
                timeToFirstTokenMs: null,
                crdate: 1_700_000_021,
            ),
        ];

        $factory  = new RunTimelineFactory($telemetry, new InMemoryGovernanceEventRepository());
        $timeline = $factory->build($this->agentRun(), []);

        self::assertStringNotContainsString('servedProvider', $timeline[0]->detail);
        self::assertStringContainsString('servedProvider=ollama', $timeline[1]->detail);
        self::assertStringContainsString('fallbackAttempts=1', $timeline[1]->detail);
    }

    private function agentRun(): AgentRun
    {
        return new AgentRun(
            uid: 7,
            uuid: self::RUN_UUID,
            status: 'completed',
            configurationUid: 3,
            configurationIdentifier: 'editorial',
            beUser: 1,
            iterations: 2,
            truncated: false,
            totalPromptTokens: 120,
            totalCompletionTokens: 30,
            totalTokens: 150,
            estimatedCost: 0.0012,
            errorClass: '',
            terminationReason: 'completed',
            startedAt: 1_700_000_000,
            finishedAt: 1_700_000_040,
            crdate: 1_700_000_000,
        );
    }

    private function event(int $sequence, string $kind, int $crdate): AgentRunEvent
    {
        return new AgentRunEvent(
            uid: 100 + $sequence,
            run: 7,
            sequence: $sequence,
            kind: $kind,
            round: 1,
            durationMs: 0.0,
            payload: ['kind' => $kind, 'contentRedacted' => true],
            crdate: $crdate,
        );
    }

    private function toolEvent(int $sequence, bool $isError, int $crdate): AgentRunEvent
    {
        return new AgentRunEvent(
            uid: 200 + $sequence,
            run: 7,
            sequence: $sequence,
            kind: 'tool',
            round: 1,
            durationMs: 4.0,
            payload: ['kind' => 'tool', 'toolName' => 'get_page', 'toolIsError' => $isError],
            crdate: $crdate,
        );
    }

    private function call(int $crdate): TelemetryCall
    {
        return new TelemetryCall(
            operation: 'tools',
            provider: 'ollama',
            model: 'qwen3:4b',
            servedProvider: 'ollama',
            servedModel: 'qwen3:4b',
            success: true,
            errorClass: '',
            latencyMs: 1200,
            cacheHit: false,
            fallbackAttempts: 0,
            timeToFirstTokenMs: null,
            crdate: $crdate,
        );
    }

    private function governanceEvent(int $crdate): RecordedGovernanceEvent
    {
        return new RecordedGovernanceEvent(
            decision: 'tool_denied',
            reason: 'trustZone',
            toolName: 'fetch_logs',
            guardrail: '',
            detail: 'zone=external_global;ceiling=editor_content;observedOnly=0',
            crdate: $crdate,
        );
    }
}
