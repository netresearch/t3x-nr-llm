<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RecordingAgentRunRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentRunPersister::class)]
#[CoversClass(AgentRunHandle::class)]
final class AgentRunPersisterTest extends TestCase
{
    #[Test]
    public function beginOpensRunAndReturnsHandleCarryingAGeneratedUuid(): void
    {
        $repository = new RecordingAgentRunRepository();
        $repository->nextUid = 42;
        $persister = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));

        $config = new LlmConfiguration();
        $config->setIdentifier('cfg-tools');

        $handle = $persister->begin($config, 7);

        self::assertNotNull($handle);
        self::assertSame(42, $handle->runUid);
        // RFC 4122 UUID: 36 chars, 8-4-4-4-12 hyphenation.
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $handle->uuid,
        );
        self::assertSame(0, $handle->sequence);
        self::assertCount(1, $repository->startedRuns);
        self::assertSame('cfg-tools', $repository->startedRuns[0]['configurationIdentifier']);
        self::assertSame(0, $repository->startedRuns[0]['configurationUid']);
        self::assertSame(7, $repository->startedRuns[0]['beUser']);
    }

    #[Test]
    public function beginToleratesANullConfiguration(): void
    {
        $repository = new RecordingAgentRunRepository();
        $persister  = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));

        $handle = $persister->begin(null, 0);

        self::assertNotNull($handle);
        self::assertSame('', $repository->startedRuns[0]['configurationIdentifier']);
        self::assertSame(0, $repository->startedRuns[0]['configurationUid']);
    }

    #[Test]
    public function recordStepAppendsSequentialEventsAndAdvancesTheHandle(): void
    {
        $repository = new RecordingAgentRunRepository();
        $persister  = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));
        $handle     = $persister->begin(null, 0);
        self::assertNotNull($handle);

        $persister->recordStep($handle, new RunStep(kind: RunStep::KIND_REQUEST, round: 1, durationMs: 0.0, messagesSent: [['role' => 'user', 'content' => 'hi']], toolSpecs: ['fetch_logs']));
        $persister->recordStep($handle, new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 12.5, content: 'answer', promptTokens: 7, completionTokens: 3, totalTokens: 10));
        $persister->recordStep($handle, new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 5.0, toolName: 'fetch_logs', toolArguments: ['limit' => 5], toolResult: 'ok', toolIsError: false));

        self::assertCount(3, $repository->events);
        self::assertSame([0, 1, 2], array_column($repository->events, 'sequence'));
        self::assertSame(['request', 'llm', 'tool'], array_column($repository->events, 'kind'));
        self::assertSame(3, $handle->sequence);

        // The event payload is the full RunStep snapshot, JSON-encoded.
        $llmPayload = json_decode($repository->events[1]['payloadJson'], true);
        self::assertIsArray($llmPayload);
        self::assertSame('answer', $llmPayload['content']);
        self::assertSame(10, $llmPayload['totalTokens']);
    }

    #[Test]
    public function settleCompletedWritesCompletedStatusAndSummedTotals(): void
    {
        $repository = new RecordingAgentRunRepository();
        $persister  = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));
        $handle     = $persister->begin(null, 0);
        self::assertNotNull($handle);

        $result = new ToolLoopResult('done', [], 2, false, UsageStatistics::fromTokens(10, 20, 0.05));
        $persister->settleCompleted($handle, $result);

        self::assertNotNull($repository->finished);
        self::assertSame('completed', $repository->finished['status']);
        self::assertSame(2, $repository->finished['iterations']);
        self::assertFalse($repository->finished['truncated']);
        self::assertSame(10, $repository->finished['promptTokens']);
        self::assertSame(20, $repository->finished['completionTokens']);
        self::assertSame(30, $repository->finished['totalTokens']);
        self::assertSame(0.05, $repository->finished['estimatedCost']);
        self::assertSame('', $repository->finished['errorClass']);
    }

    #[Test]
    public function settleCompletedCarriesTheTruncatedFlag(): void
    {
        $repository = new RecordingAgentRunRepository();
        $persister  = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));
        $handle     = $persister->begin(null, 0);
        self::assertNotNull($handle);

        $persister->settleCompleted($handle, new ToolLoopResult('', [], 5, true, UsageStatistics::fromTokens(0, 0)));

        self::assertNotNull($repository->finished);
        self::assertSame('completed', $repository->finished['status']);
        self::assertTrue($repository->finished['truncated']);
    }

    #[Test]
    public function settleFailedWritesFailedStatusAndTheExceptionClass(): void
    {
        $repository = new RecordingAgentRunRepository();
        $persister  = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));
        $handle     = $persister->begin(null, 0);
        self::assertNotNull($handle);

        $persister->settleFailed($handle, new RuntimeException('boom'));

        self::assertNotNull($repository->finished);
        self::assertSame('failed', $repository->finished['status']);
        self::assertSame(RuntimeException::class, $repository->finished['errorClass']);
    }

    #[Test]
    public function claimResumeReturnsFalseWhenTheRepositoryThrows(): void
    {
        $repository               = new RecordingAgentRunRepository();
        $repository->throwOnClaim = true;
        $persister                = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));
        $run                      = new AgentRun(1, 'uuid', 'waiting_for_approval', 0, '', 0, 0, false, 0, 0, 0, 0.0, '', 0, 0, 0, '{}');

        // Fail-closed: a store error refuses the resume rather than risk a
        // double-execute of the gated tool.
        self::assertFalse($persister->claimResume($run));
    }

    #[Test]
    public function beginReturnsNullWhenTheRepositoryThrows(): void
    {
        $repository               = new RecordingAgentRunRepository();
        $repository->throwOnStart = true;
        $persister                = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));

        self::assertNull($persister->begin(null, 0));
    }

    #[Test]
    public function recordStepSwallowsARepositoryErrorAndDoesNotAdvanceTheHandle(): void
    {
        $repository                = new RecordingAgentRunRepository();
        $repository->throwOnRecord = true;
        $persister                 = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));
        $handle                    = new AgentRunHandle(1, 'uuid');

        $persister->recordStep($handle, new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 1.0, content: 'x'));

        // No exception escaped, and the sequence only advances on a successful write.
        self::assertSame(0, $handle->sequence);
    }

    #[Test]
    public function settleSwallowsARepositoryError(): void
    {
        $repository                = new RecordingAgentRunRepository();
        $repository->throwOnFinish = true;
        $persister                 = new AgentRunPersister($repository, FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL));
        $handle                    = new AgentRunHandle(1, 'uuid');

        $persister->settleCompleted($handle, new ToolLoopResult('', [], 1, false, UsageStatistics::fromTokens(0, 0)));
        $persister->settleFailed($handle, new RuntimeException('boom'));

        // Both settle paths returned without throwing.
        self::assertNull($repository->finished);
    }
}
