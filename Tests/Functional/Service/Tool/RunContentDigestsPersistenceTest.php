<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Tests\Fixture\AgentRunRepositoryFactory;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A run's content digests (ADR-323) against the real schema: stored at the
 * `metadata` level when the operator opted in, without the text or the
 * unkeyed request identity, and purged with the run.
 */
#[CoversClass(AgentRunPersister::class)]
#[CoversClass(RunStepPrivacyFilter::class)]
final class RunContentDigestsPersistenceTest extends AbstractFunctionalTestCase
{
    private const QUESTION = 'Which pages mention the spring campaign?';

    private const IDENTITY = 'v1:0fde2089bb682218c3f0484a095e256d4a1a1766870593f31e4068702dc58de8';

    private AgentRunRepository $repository;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;
        // The guard filters each step a second time at the run's stored level,
        // as on an installation: both passes run at `metadata` here.
        $this->repository = AgentRunRepositoryFactory::create(
            $connectionPool,
            $this->get(AgentStateCodec::class),
            new FixedPrivacyPolicy(PrivacyLevel::METADATA, 30, new ContentRedactor(), true),
        );
    }

    #[Test]
    public function theDigestsAreStoredWithoutTheTextAndPurgedWithTheRun(): void
    {
        $redactor  = new ContentRedactor();
        $filter    = new RunStepPrivacyFilter(new FixedPrivacyPolicy(PrivacyLevel::METADATA, 30, $redactor, true), $redactor);
        $persister = new AgentRunPersister($this->repository, $filter, new NullLogger());
        $handle    = $persister->begin(null, 1);
        self::assertNotNull($handle);

        self::assertTrue($persister->recordStep($handle, new RunStep(
            kind: RunStep::KIND_REQUEST,
            round: 1,
            durationMs: 0.0,
            messagesSent: [ChatMessage::system('You answer about pages.')->toArray(), ChatMessage::user(self::QUESTION)->toArray()],
        )));
        self::assertTrue($persister->recordStep($handle, new RunStep(
            kind: RunStep::KIND_LLM,
            round: 1,
            durationMs: 1.0,
            content: 'Two pages mention it.',
            requestIdentity: self::IDENTITY,
        )));
        self::assertTrue($persister->settleCompleted($handle, new ToolLoopResult('done', [], 1, false, UsageStatistics::fromTokens(1, 1))));

        $events = $this->repository->findEvents($handle->runUid);
        self::assertCount(2, $events);
        self::assertSame(['system', 'user'], array_column($events[0]->payload['messagesSentDigests'] ?? [], 'role'));
        self::assertMatchesRegularExpression('/^v1:[a-f0-9]{64}$/D', $this->text($events[1]->payload, 'contentDigest'));
        self::assertMatchesRegularExpression('/^v1:[a-f0-9]{64}$/D', $this->text($events[1]->payload, 'requestDigest'));

        $stored = implode("\n", $this->connectionPool->getConnectionForTable('tx_nrllm_agentrun_event')
            ->select(['payload'], 'tx_nrllm_agentrun_event', ['run' => $handle->runUid])
            ->fetchFirstColumn());
        self::assertStringNotContainsString(self::QUESTION, $stored);
        self::assertStringNotContainsString('Two pages mention it.', $stored);
        self::assertStringNotContainsString(substr(self::IDENTITY, 3), $stored, 'the unkeyed identity is never stored');

        $this->repository->purgeOlderThan(time() + 1);

        self::assertSame([], $this->repository->findEvents($handle->runUid));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function text(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
