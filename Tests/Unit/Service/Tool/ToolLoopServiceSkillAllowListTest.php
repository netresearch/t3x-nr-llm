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
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
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
use Netresearch\NrLlm\Service\Tool\Exception\ToolInputRequiredException;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeInputTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\PreviewingApprovalTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\ShiftingPreviewTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The run's skill allow-list (ADR-038 item 5): resolved once at run start over
 * the configuration's skills AND the run's forced skills, stored with a
 * suspended run, and intersected with the live list on resume (ADR-165).
 *
 * Every assertion reads what the provider is offered or what a call answers —
 * the consumer of the list — never the list helper itself.
 */
#[CoversClass(ToolLoopService::class)]
#[CoversClass(ToolCallPolicy::class)]
#[CoversClass(AllowedToolsResolver::class)]
final class ToolLoopServiceSkillAllowListTest extends TestCase
{
    /** @var list<list<string>> the tool names offered on each provider round, in order */
    private array $offered = [];

    #[Test]
    public function aForcedSkillsDeclarationRestrictsTheToolsTheRunIsOffered(): void
    {
        $service = $this->service($this->registry('read_a', 'read_b'), [$this->response('done')]);

        $service->runLoop(
            [$this->userTurn('go')],
            $this->configuration(),
            ToolExecutionContext::none(),
            null,
            augmentation: new RunAugmentation(forcedSkills: [$this->skill('forced', '["read_a"]', uid: 5)]),
        );

        self::assertSame([['read_a']], $this->offered);
    }

    #[Test]
    public function aForcedSkillsDeclarationRefusesACallToAToolItDoesNotName(): void
    {
        $service = $this->service($this->registry('read_a', 'read_b'), [
            $this->response('', [new ToolCall('call_1', 'read_b', [])]),
            $this->response('done'),
        ]);

        $result = $service->runLoop(
            [$this->userTurn('go')],
            $this->configuration(),
            ToolExecutionContext::none(),
            null,
            augmentation: new RunAugmentation(forcedSkills: [$this->skill('forced', '["read_a"]', uid: 5)]),
        );

        self::assertCount(1, $result->trace);
        self::assertSame('read_b', $result->trace[0]->name);
        self::assertTrue($result->trace[0]->isError);
        self::assertStringContainsString('not permitted', $result->trace[0]->result);
    }

    #[Test]
    public function aToolTheConfigurationOrAForcedSkillNamesIsStillOffered(): void
    {
        $service = $this->service($this->registry('read_a', 'read_b', 'read_c'), [$this->response('done')]);

        $service->runLoop(
            [$this->userTurn('go')],
            $this->configuration($this->skill('attached', '["read_a"]')),
            ToolExecutionContext::none(),
            null,
            augmentation: new RunAugmentation(forcedSkills: [$this->skill('forced', '["read_b"]', uid: 5)]),
        );

        self::assertSame([['read_a', 'read_b']], $this->sorted($this->offered));
    }

    #[Test]
    public function aResumeWhoseLiveListResolvesToNullDoesNotWidenTheRun(): void
    {
        $declaring     = $this->skill('declaring', '["approve","read_a"]');
        $configuration = $this->configuration($declaring);
        $service       = $this->service($this->registry('approve', 'read_a', 'read_b'), [
            $this->response('', [new ToolCall('call_1', 'approve', [])]),
            $this->response('done'),
        ]);

        $state = $this->suspend($service, $configuration);

        // The only declaring skill is disabled while the run waits: the live
        // list resolves to null, which on its own means every tool.
        $declaring->setEnabled(false);

        $service->resume($state, true, $configuration, ToolExecutionContext::none());

        self::assertSame(['approve', 'read_a'], $this->sortedNames(array_pop($this->offered)));
    }

    #[Test]
    public function anInputResumeWhoseLiveListResolvesToNullDoesNotWidenTheRun(): void
    {
        $declaring     = $this->skill('declaring', '["ask_user","read_a"]');
        $configuration = $this->configuration($declaring);
        $service       = $this->service($this->registry('ask_user', 'read_a', 'read_b'), [
            $this->response('', [new ToolCall('call_1', 'ask_user', [])]),
            $this->response('done'),
        ]);

        $state = $this->suspendForInput($service, $configuration);
        self::assertInstanceOf(SkillToolAllowList::class, $state->skillAllowList, 'an input pause stores the list too');

        $declaring->setEnabled(false);

        $service->resumeWithInput($state, ['city' => 'Berlin'], $configuration, ToolExecutionContext::none());

        self::assertSame(['ask_user', 'read_a'], $this->sortedNames(array_pop($this->offered)));
    }

    #[Test]
    public function aQueuedRunIsHeldToTheListItWasEnqueuedUnder(): void
    {
        // Enqueued under ["read_a"]; its only declaring skill was disabled in
        // the queue, so the list resolved at execution is null.
        $service = $this->service($this->registry('read_a', 'read_b'), [$this->response('done')]);

        $service->runLoop(
            [$this->userTurn('go')],
            $this->configuration($this->skill('declaring', '["read_a"]', enabled: false)),
            ToolExecutionContext::none(),
            null,
            skillAllowList: new SkillToolAllowList(['read_a']),
        );

        self::assertSame([['read_a']], $this->offered);
    }

    #[Test]
    public function aResumeWithANarrowerLiveListNarrowsTheRun(): void
    {
        $declaring     = $this->skill('declaring', '["approve","read_a","read_b"]');
        $configuration = $this->configuration($declaring);
        $service       = $this->service($this->registry('approve', 'read_a', 'read_b'), [
            $this->response('', [new ToolCall('call_1', 'approve', [])]),
            $this->response('done'),
        ]);

        $state = $this->suspend($service, $configuration);

        $declaring->setAllowedTools('["approve","read_a"]');

        $service->resume($state, true, $configuration, ToolExecutionContext::none());

        self::assertSame(['approve', 'read_a'], $this->sortedNames(array_pop($this->offered)));
    }

    #[Test]
    public function aForcedSkillsToolsSurviveTheResume(): void
    {
        $forced = $this->skill('forced', '["approve","read_a"]', uid: 9);
        $skills = self::createStub(SkillRepository::class);
        $skills->method('findExistingByUids')->willReturn([$forced]);

        $approve       = new ShiftingPreviewTool('approve');
        $configuration = $this->configuration($this->skill('attached', '["read_b"]'));
        $service       = $this->service(new ToolRegistry([$approve, new FakeTool('read_a'), new FakeTool('read_b'), new FakeTool('read_c')]), [
            $this->response('', [new ToolCall('call_1', 'approve', [])]),
            $this->response('done'),
        ], $skills);

        $state = $this->suspend($service, $configuration, new RunAugmentation(forcedSkills: [$forced]));

        $result = $service->resume($state, true, $configuration, ToolExecutionContext::none());

        // Re-derived from the configuration alone, the live list would be
        // ["read_b"], and the approved call would be refused as no longer
        // permitted instead of executed.
        self::assertSame(1, $approve->executions, 'the approved call of a tool only the forced skill grants must run');
        self::assertSame(['approve', 'read_a', 'read_b'], $this->sortedNames(array_pop($this->offered)));
        self::assertSame('done', $result->finalContent);
    }

    #[Test]
    public function aSuspensionInsideAResumedRunStoresTheIntersectedList(): void
    {
        $declaring     = $this->skill('declaring', '["approve","read_a","read_b"]');
        $configuration = $this->configuration($declaring);
        $service       = $this->service($this->registry('approve', 'read_a', 'read_b'), [
            $this->response('', [new ToolCall('call_1', 'approve', [])]),
            $this->response('', [new ToolCall('call_2', 'approve', [])]),
        ]);

        $state = $this->suspend($service, $configuration);
        $declaring->setAllowedTools('["approve","read_a"]');

        try {
            $service->resume($state, true, $configuration, ToolExecutionContext::none());
            self::fail('Expected the continuation to suspend again.');
        } catch (ToolApprovalRequiredException $again) {
            self::assertInstanceOf(SkillToolAllowList::class, $again->state->skillAllowList);
            self::assertSame(['approve', 'read_a'], $this->sortedNames($again->state->skillAllowList->toolNames ?? []));
        }
    }

    #[Test]
    public function aReSuspensionForAMovedPreviewKeepsTheStoredList(): void
    {
        $tool          = new ShiftingPreviewTool('attach_file');
        $declaring     = $this->skill('declaring', '["attach_file","read_a"]');
        $configuration = $this->configuration($declaring);
        $service       = $this->service(new ToolRegistry([$tool, new FakeTool('read_a'), new FakeTool('read_b')]), [
            $this->response('', [new ToolCall('call_1', 'attach_file', ['uid' => 7])]),
        ]);

        $state = $this->suspend($service, $configuration);
        $tool->lines = ['3 reference(s) → 4, appended last'];

        try {
            $service->resume($state, true, $configuration, ToolExecutionContext::none());
            self::fail('Expected the moved preview to re-suspend the run.');
        } catch (ToolApprovalRequiredException $again) {
            self::assertSame([0], $again->state->staleCallIndexes);
            self::assertInstanceOf(SkillToolAllowList::class, $again->state->skillAllowList);
            self::assertSame(['attach_file', 'read_a'], $this->sortedNames($again->state->skillAllowList->toolNames ?? []));
        }
    }

    /**
     * @param list<CompletionResponse> $queue
     */
    private function service(ToolRegistry $registry, array $queue, ?SkillRepository $skills = null): ToolLoopService
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

                $next = array_shift($queue);
                if (!$next instanceof CompletionResponse) {
                    throw new RuntimeException('Scripted response queue underflow.', 1791500001);
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
            skillRepository: $skills,
        );
    }

    private function suspendForInput(ToolLoopService $service, LlmConfiguration $configuration): SuspendedRunState
    {
        try {
            $service->runLoop([$this->userTurn('go')], $configuration, ToolExecutionContext::none(), null);
        } catch (ToolInputRequiredException $e) {
            return $e->state;
        }

        self::fail('Expected the run to suspend for input.');
    }

    private function suspend(ToolLoopService $service, LlmConfiguration $configuration, ?RunAugmentation $augmentation = null): SuspendedRunState
    {
        try {
            $service->runLoop([$this->userTurn('go')], $configuration, ToolExecutionContext::none(), null, augmentation: $augmentation);
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
            static fn(string $name): ToolInterface => match ($name) {
                'approve'  => new PreviewingApprovalTool($name),
                'ask_user' => new FakeInputTool($name),
                default    => new FakeTool($name),
            },
            $names,
        ));
    }

    private function skill(string $identifier, string $allowedTools, ?int $uid = null, bool $enabled = true): Skill
    {
        $skill = new Skill();
        $skill->setSource(1);
        $skill->setIdentifier($identifier);
        $skill->setAllowedTools($allowedTools);
        $skill->setEnabled($enabled);
        if ($uid !== null) {
            $skill->_setProperty('uid', $uid);
        }

        return $skill;
    }

    /**
     * A configuration in the LOCAL trust zone, so the zone axis permits the
     * fake tools and the skill axis is the one under test.
     */
    private function configuration(Skill ...$skills): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);
        foreach ($skills as $skill) {
            $configuration->addSkill($skill);
        }

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
