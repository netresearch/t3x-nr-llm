<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Governance;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepository;
use Netresearch\NrLlm\Service\Governance\RecordedGovernanceEvent;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicy;
use Netresearch\NrLlm\Service\Tool\ToolInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Widgets\DataProvider\GovernanceBlocksOverTimeDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(GovernanceEventRepository::class)]
#[CoversClass(GovernanceEvent::class)]
#[CoversClass(RecordedGovernanceEvent::class)]
final class GovernanceEventRepositoryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_governance_event';

    private GovernanceEventRepository $repository;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->repository = new GovernanceEventRepository($this->connectionPool);
    }

    #[Test]
    public function recordInsertsOneRowWithAllFields(): void
    {
        $this->repository->record(new GovernanceEvent(
            correlationId: 'corr-1',
            decision: 'response_blocked',
            reason: 'deny',
            provider: 'openai',
            model: 'gpt-4o',
            configurationIdentifier: 'primary',
            beUser: 7,
            toolName: '',
            agentrunUid: 0,
            guardrail: 'Acme\\SecretGuardrail',
            detail: 'contained a secret pattern',
        ));

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row        = $connection->select(['*'], self::TABLE, ['correlation_id' => 'corr-1'])->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame('response_blocked', $row['decision']);
        self::assertSame('deny', $row['reason']);
        self::assertSame('openai', $row['provider']);
        self::assertSame('gpt-4o', $row['model']);
        self::assertSame('primary', $row['configuration_identifier']);
        self::assertSame(7, (int)$row['be_user']);
        self::assertSame('Acme\\SecretGuardrail', $row['guardrail']);
        self::assertSame('contained a secret pattern', $row['detail']);
        self::assertGreaterThan(0, (int)$row['crdate']);
    }

    #[Test]
    public function countByDecisionGroupsWithinTheWindow(): void
    {
        $now = time();
        $this->insert('tool_denied', 'trustZone', '', $now);
        $this->insert('tool_denied', 'requiresAdmin', '', $now);
        $this->insert('response_blocked', 'deny', '', $now);
        // Out of window: excluded.
        $this->insert('content_filter', 'content_filter', '', $now - (10 * 86400));

        $counts = $this->repository->countByDecision($now - (5 * 86400));
        ksort($counts);

        self::assertSame(['response_blocked' => 1, 'tool_denied' => 2], $counts);
    }

    #[Test]
    public function countToolDenialsByReasonCountsOnlyToolDeniedRows(): void
    {
        $now = time();
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now);
        $this->insert('tool_denied', 'trustZone', 'get_env', $now);
        $this->insert('tool_denied', 'requiresAdmin', 'list_be_users', $now);
        // A guardrail block also carries a reason, but must not count as a tool denial.
        $this->insert('response_blocked', 'deny', '', $now);

        $counts = $this->repository->countToolDenialsByReason(0);
        ksort($counts);

        self::assertSame(['requiresAdmin' => 1, 'trustZone' => 2], $counts);
    }

    #[Test]
    public function countToolDecisionsByNameGroupsByToolAndSkipsEmptyNames(): void
    {
        $now = time();
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now);
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now);
        $this->insert('tool_denied', 'requiresAdmin', 'get_env', $now);
        // Guardrail row has no tool name: excluded.
        $this->insert('response_blocked', 'deny', '', $now);

        $counts = $this->repository->countToolDecisionsByName(0);

        self::assertSame(2, $counts['fetch_logs']);
        self::assertSame(1, $counts['get_env']);
        self::assertArrayNotHasKey('', $counts);
    }

    #[Test]
    public function findForRunMatchesEitherKeyAndReturnsARowOnlyOnce(): void
    {
        $now  = time();
        $uuid = 'c0ffee00-0000-4000-8000-000000000042';

        // The three write points know different halves of the run's identity
        // (ADR-153): the gates write the uid, the guardrail middleware the
        // correlation id — and a row can carry both.
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now, agentRunUid: 7, correlationId: '');
        $this->insert('response_blocked', 'deny', '', $now + 1, agentRunUid: 0, correlationId: $uuid);
        $this->insert('context_blocked', 'secret_adjacent', '', $now + 2, agentRunUid: 7, correlationId: $uuid);
        // Another run, and a row belonging to no run at all.
        $this->insert('tool_denied', 'trustZone', 'get_env', $now, agentRunUid: 9, correlationId: 'other-uuid');
        $this->insert('response_blocked', 'deny', '', $now, agentRunUid: 0, correlationId: '');

        $events = $this->repository->findForRun(7, $uuid);

        self::assertCount(3, $events);
        self::assertSame(
            ['tool_denied', 'response_blocked', 'context_blocked'],
            array_map(static fn(RecordedGovernanceEvent $e): string => $e->decision, $events),
            'Oldest first.',
        );
        self::assertSame('fetch_logs', $events[0]->toolName);
    }

    #[Test]
    public function findForRunReturnsNothingWhenNeitherKeyIsKnown(): void
    {
        $this->insert('response_blocked', 'deny', '', time(), agentRunUid: 0, correlationId: '');

        // Both arguments at their "unknown" marker must not match the rows that
        // carry the same markers — that would list every unattributed decision.
        self::assertSame([], $this->repository->findForRun(0, ''));
    }

    #[Test]
    public function purgeOlderThanDeletesOnlyOlderRows(): void
    {
        $now = time();
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now - (10 * 86400));
        $this->insert('tool_denied', 'trustZone', 'fetch_logs', $now);

        $deleted = $this->repository->purgeOlderThan($now - (5 * 86400));

        self::assertSame(1, $deleted);
        self::assertSame(1, $this->connectionPool->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []));
    }

    private function insert(
        string $decision,
        string $reason,
        string $toolName,
        int $crdate,
        int $agentRunUid = 0,
        string $correlationId = '',
    ): void {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'                      => 0,
            'crdate'                   => $crdate,
            'correlation_id'           => $correlationId,
            'decision'                 => $decision,
            'reason'                   => $reason,
            'provider'                 => 'openai',
            'model'                    => 'gpt',
            'configuration_identifier' => 'primary',
            'be_user'                  => 0,
            'tool_name'                => $toolName,
            'agentrun_uid'             => $agentRunUid,
            'guardrail'                => '',
            'detail'                   => '',
        ]);
    }

    #[Test]
    #[DataProvider('invocationReasonCodes')]
    public function actualInvocationDenialsStaySeparateFromOfferabilityReasonCounts(
        string $reason,
    ): void {
        $this->insert('tool_denied', 'trustZone', 'restricted', time());
        $tool = self::createMock(ToolInterface::class);
        $tool
            ->method('getSpec')
            ->willReturn(
                ToolSpec::function(
                    'lookup',
                    'Lookup',
                    ['type' => 'object', 'properties' => []],
                ),
            );
        $tool->method('isEnabledByDefault')->willReturn(true);
        $tool->method('getGroup')->willReturn('test');
        $tool->expects(self::never())->method('execute');
        $registry = new ToolRegistry([$tool]);
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager
            ->method('chatWithToolsForConfiguration')
            ->willReturn(
                new CompletionResponse(
                    '',
                    'fixture',
                    UsageStatistics::fromTokens(0, 0),
                    toolCalls: [new ToolCall('call', 'lookup', [])],
                ),
                new CompletionResponse(
                    'done',
                    'fixture',
                    UsageStatistics::fromTokens(0, 0),
                ),
            );
        $offerability = self::createStub(ToolCallPolicyInterface::class);
        $offerability->method('filterOfferable')->willReturn(['lookup']);
        $offerability
            ->method('skillAllowListForRun')
            ->willReturn(new SkillToolAllowList(null));
        $rule = self::createStub(ToolInvocationRuleInterface::class);
        $rule->method('identifier')->willReturn('installation.boundary');
        $rule->method('requiresCompleteHistory')->willReturn(false);
        $rule
            ->method('decide')
            ->willReturn(ToolInvocationDecision::deny($reason));
        $loop = new ToolLoopService(
            $manager,
            $registry,
            $offerability,
            governanceEvents: $this->repository,
            invocationPolicy: new ToolInvocationPolicy([$rule]),
        );
        $result = $loop->runLoop(
            [ChatMessage::user('lookup')],
            new LlmConfiguration(),
            new ToolExecutionContext(
                AiActorContext::anonymous(),
                run: new AgentRunReference(7, 'invocation-run'),
            ),
            null,
        );
        self::assertTrue($result->trace[0]->isError);
        self::assertSame(
            ['trustZone' => 1],
            $this->repository->countToolDenialsByReason(),
        );
        $counts = $this->repository->countByDecision();
        ksort($counts);
        self::assertSame(
            ['invocation_denied' => 1, 'tool_denied' => 1],
            $counts,
        );
        $chart = GovernanceBlocksOverTimeDataProvider::shapeChartData(
            $counts,
            [
                'tool_denied' => 'Tool denied',
                'invocation_denied' => 'Invocation denied',
            ],
            'Events',
        );
        self::assertSame(['Tool denied', 'Invocation denied'], $chart['labels']);
        self::assertSame([1, 1], $chart['datasets'][0]['data']);
        $byName = $this->repository->countToolDecisionsByName();
        ksort($byName);
        self::assertSame(['lookup' => 1, 'restricted' => 1], $byName);
        $events = $this->repository->findForRun(7, 'invocation-run');
        self::assertCount(1, $events);
        self::assertSame('invocation_denied', $events[0]->decision);
        self::assertSame($reason, $events[0]->reason);
        self::assertSame(
            'invocationRule=installation.boundary',
            $events[0]->detail,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invocationReasonCodes(): iterable
    {
        yield 'installation code' => ['outside_site'];
        yield 'code matching the existing none reason' => ['none'];
    }
}
