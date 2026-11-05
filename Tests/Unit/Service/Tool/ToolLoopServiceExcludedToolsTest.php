<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\ShiftingPreviewTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The tools a configuration excludes (ADR-261): never offered, refused when the
 * model calls one anyway, and a change while a run waits can take a tool away
 * but never give one back.
 *
 * Every assertion reads what the provider is offered or what a call answers,
 * the consumer of the exclusion, never the list helper itself.
 */
#[CoversClass(ToolLoopService::class)]
#[CoversClass(ToolCallPolicy::class)]
#[CoversClass(AllowedToolsResolver::class)]
final class ToolLoopServiceExcludedToolsTest extends TestCase
{
    /** @var list<list<string>> the tool names offered on each provider round, in order */
    private array $offered = [];

    /** @var (callable(): void)|null runs inside each provider round, after the offer was recorded */
    private $duringRound;

    #[Test]
    public function anExcludedToolIsNotOfferedWhileAnotherConfigurationStillGetsIt(): void
    {
        $registry = $this->registry('read_a', 'delete_record');

        $this->service($registry, [$this->response('done')])->runLoop(
            [$this->userTurn('go')],
            $this->configuration('delete_record'),
            ToolExecutionContext::none(),
            null,
        );
        $this->service($registry, [$this->response('done')])->runLoop(
            [$this->userTurn('go')],
            $this->configuration(),
            ToolExecutionContext::none(),
            null,
        );

        self::assertSame([['read_a'], ['delete_record', 'read_a']], $this->sorted($this->offered));
    }

    #[Test]
    public function aCallToAnExcludedToolIsRefusedWhenTheModelSendsItAnyway(): void
    {
        $delete  = new ShiftingPreviewTool('delete_record');
        $service = $this->service(new ToolRegistry([new FakeTool('read_a'), $delete]), [
            $this->response('', [new ToolCall('call_1', 'delete_record', ['uid' => 7])]),
            $this->response('done'),
        ]);

        $result = $service->runLoop(
            [$this->userTurn('go')],
            $this->configuration('delete_record'),
            ToolExecutionContext::none(),
            null,
        );

        self::assertCount(1, $result->trace);
        self::assertSame('delete_record', $result->trace[0]->name);
        self::assertTrue($result->trace[0]->isError);
        self::assertStringContainsString('not permitted', $result->trace[0]->result);
        self::assertSame(0, $delete->executions);
    }

    #[Test]
    public function theGateIsAskedAgainWhenACallItOfferedIsExecuted(): void
    {
        // Offered at the start of the round, excluded while the model answered:
        // the call is decided against the exclusion that holds when it runs,
        // not against the set the round was offered.
        $configuration     = $this->configuration();
        $this->duringRound = static function () use ($configuration): void {
            $configuration->setExcludedTools('delete_record');
        };
        $service = $this->service(new ToolRegistry([new FakeTool('read_a'), new FakeTool('delete_record', 'DELETED')]), [
            $this->response('', [new ToolCall('call_1', 'delete_record', [])]),
            $this->response('done'),
        ]);

        $result = $service->runLoop([$this->userTurn('go')], $configuration, ToolExecutionContext::none(), null);

        self::assertSame(['delete_record', 'read_a'], $this->sortedNames($this->offered[0]), 'the round offered the tool');
        self::assertCount(1, $result->trace);
        self::assertTrue($result->trace[0]->isError);
        self::assertStringNotContainsString('DELETED', $result->trace[0]->result);
    }

    #[Test]
    public function aToolRegisteredLaterIsNotExcluded(): void
    {
        $configuration = $this->configuration('read_a');

        $this->service($this->registry('read_a', 'read_b'), [$this->response('done')])
            ->runLoop([$this->userTurn('go')], $configuration, ToolExecutionContext::none(), null);
        $this->service($this->registry('read_a', 'read_b', 'read_c'), [$this->response('done')])
            ->runLoop([$this->userTurn('go')], $configuration, ToolExecutionContext::none(), null);

        self::assertSame([['read_b'], ['read_b', 'read_c']], $this->sorted($this->offered));
    }

    #[Test]
    public function anExclusionRemovedWhileTheRunWaitsDoesNotGiveTheToolBack(): void
    {
        $configuration = $this->configuration('read_b');
        $service       = $this->service($this->registry('approve', 'read_a', 'read_b'), [
            $this->response('', [new ToolCall('call_1', 'approve', [])]),
            $this->response('done'),
        ]);

        $state = $this->suspend($service, $configuration);

        $configuration->setExcludedTools('');

        $service->resume($state, true, $configuration, ToolExecutionContext::none());

        self::assertSame(['approve', 'read_a'], $this->sortedNames(array_pop($this->offered)));
    }

    #[Test]
    public function anExclusionAddedWhileTheRunWaitsTakesTheToolAway(): void
    {
        $configuration = $this->configuration();
        $service       = $this->service($this->registry('approve', 'read_a', 'read_b'), [
            $this->response('', [new ToolCall('call_1', 'approve', [])]),
            $this->response('done'),
        ]);

        $state = $this->suspend($service, $configuration);

        $configuration->setExcludedTools('read_a');

        $service->resume($state, true, $configuration, ToolExecutionContext::none());

        self::assertSame(['approve', 'read_b'], $this->sortedNames(array_pop($this->offered)));
    }

    #[Test]
    public function anApprovedCallToAToolExcludedWhileTheRunWaitedIsNotExecuted(): void
    {
        $approve       = new ShiftingPreviewTool('approve');
        $configuration = $this->configuration();
        $service       = $this->service(new ToolRegistry([$approve, new FakeTool('read_a')]), [
            $this->response('', [new ToolCall('call_1', 'approve', [])]),
            $this->response('done'),
        ]);

        $state = $this->suspend($service, $configuration);

        $configuration->setExcludedTools('approve');

        $service->resume($state, true, $configuration, ToolExecutionContext::none());

        self::assertSame(0, $approve->executions, 'an approval does not override an exclusion made while the run waited');
    }

    #[Test]
    public function theStoredListOfASuspendedRunCarriesTheExclusionsItStartedUnder(): void
    {
        $configuration = $this->configuration('read_b');
        $service       = $this->service($this->registry('approve', 'read_a', 'read_b'), [
            $this->response('', [new ToolCall('call_1', 'approve', [])]),
        ]);

        $state = $this->suspend($service, $configuration);

        self::assertInstanceOf(SkillToolAllowList::class, $state->skillAllowList);
        $restored = SkillToolAllowList::fromStored(json_decode((string) json_encode($state->skillAllowList->toStored()), true));
        self::assertInstanceOf(SkillToolAllowList::class, $restored);
        self::assertSame(['read_b'], $restored->excludedToolNames);
    }

    #[Test]
    public function aQueuedRunIsHeldToTheExclusionsItWasEnqueuedUnder(): void
    {
        $service = $this->service($this->registry('read_a', 'read_b'), [$this->response('done')]);

        $service->runLoop(
            [$this->userTurn('go')],
            $this->configuration(),
            ToolExecutionContext::none(),
            null,
            skillAllowList: new SkillToolAllowList(null, false, ['read_a']),
        );

        self::assertSame([['read_b']], $this->offered);
    }

    /**
     * @param list<CompletionResponse> $queue
     */
    private function service(ToolRegistry $registry, array $queue): ToolLoopService
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback(
            function (array $messages, array $tools) use (&$queue): CompletionResponse {
                $names = [];
                foreach ($tools as $spec) {
                    self::assertInstanceOf(ToolSpec::class, $spec);
                    $names[] = $spec->name;
                }

                $this->offered[] = $names;
                if ($this->duringRound !== null) {
                    ($this->duringRound)();
                }

                $next = array_shift($queue);
                if (!$next instanceof CompletionResponse) {
                    throw new RuntimeException('Scripted response queue underflow.', 1793100001);
                }

                return $next;
            },
        );

        return new ToolLoopService(
            $mgr,
            $registry,
            new ToolCallPolicy(
                $registry,
                new FakeToolAvailability($registry->names()),
                new AllowedToolsResolver(new SkillComposer(), $registry),
                new ToolDataClassResolver($registry),
                new TrustZoneResolver(),
                new DataClassEnforcementResolver(),
            ),
        );
    }

    private function suspend(ToolLoopService $service, LlmConfiguration $configuration): SuspendedRunState
    {
        try {
            $service->runLoop([$this->userTurn('go')], $configuration, ToolExecutionContext::none(), null);
        } catch (ToolApprovalRequiredException $e) {
            return $e->state;
        }

        self::fail('Expected the run to suspend for approval.');
    }

    /**
     * Fake read tools plus, for the name `approve`, a tool that suspends for
     * approval.
     */
    private function registry(string ...$names): ToolRegistry
    {
        return new ToolRegistry(array_map(
            static fn (string $name): ToolInterface => $name === 'approve' ? new ShiftingPreviewTool($name) : new FakeTool($name),
            $names,
        ));
    }

    /**
     * A configuration in the LOCAL trust zone, so the zone axis permits the
     * fake tools and the exclusion is the axis under test.
     */
    private function configuration(string $excludedTools = ''): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);
        $configuration->setExcludedTools($excludedTools);

        return $configuration;
    }

    /**
     * @param list<ToolCall>|null $toolCalls
     */
    private function response(string $content, ?array $toolCalls = null): CompletionResponse
    {
        return new CompletionResponse(
            content: $content,
            model: 'test-model',
            usage: UsageStatistics::fromTokens(0, 0),
            toolCalls: $toolCalls,
        );
    }

    /**
     * @return array<string, string>
     */
    private function userTurn(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }

    /**
     * @param list<string>|null $names
     *
     * @return list<string>
     */
    private function sortedNames(?array $names): array
    {
        self::assertNotNull($names, 'The provider was not asked again.');
        sort($names);

        return $names;
    }

    /**
     * @param list<list<string>> $rounds
     *
     * @return list<list<string>>
     */
    private function sorted(array $rounds): array
    {
        return array_map($this->sortedNames(...), $rounds);
    }
}
