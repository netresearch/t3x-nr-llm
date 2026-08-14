<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\SelfApprovalDeniedException;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Agent\ResumeCoordinator;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * ADR-172: with `require_second_approver` set, the backend user who started a
 * run may not release it.
 *
 * The run lives in the functional database and goes through the real
 * claim/release transitions, so "the run is still decidable" is asserted on the
 * stored row. `touch_thing` declares a write but no admin flag, so the ADR-133
 * gate admits both principals used here and the four-eyes gate is the only
 * thing that can refuse.
 */
#[CoversClass(ResumeCoordinator::class)]
final class ResumeCoordinatorFourEyesGateTest extends AbstractFunctionalTestCase
{
    private AgentRunPersister $persister;

    private bool $resumed = false;

    protected function setUp(): void
    {
        parent::setUp();

        // BeUsers.csv: uid 1 = admin (the run owner), uid 2 = non-admin editor.
        $this->importFixture('BeUsers.csv');
        $this->persister = new AgentRunPersister(
            new AgentRunRepository($this->connectionPool(), $this->get(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
    }

    #[Test]
    public function theInitiatorMayNotApproveTheirOwnRun(): void
    {
        $uuid = $this->suspendOn('touch_thing', 1);

        try {
            $this->coordinator(true)->approve($this->initiator(), $uuid, $this->decision(true, 1, 'touch_thing'));
            self::fail('Expected SelfApprovalDeniedException');
        } catch (SelfApprovalDeniedException $exception) {
            self::assertStringContainsString('requires a second approver', $exception->getMessage());
            $this->assertStillWaiting($uuid);
        }
    }

    #[Test]
    public function beingAnAdministratorIsNoExemption(): void
    {
        // uid 1 is an administrator. Acting on every run is not the same as
        // being a second pair of eyes on one's own.
        $uuid = $this->suspendOn('touch_thing', 1);

        try {
            $this->coordinator(true)->approve(
                AiActorContext::backendUser(1, isAdmin: true),
                $uuid,
                $this->decision(true, 1, 'touch_thing'),
            );
            self::fail('Expected SelfApprovalDeniedException');
        } catch (SelfApprovalDeniedException) {
            $this->assertStillWaiting($uuid);
        }
    }

    #[Test]
    public function theInitiatorMayStillDenyTheirOwnRun(): void
    {
        // The control exists to stop a write from EXECUTING. A denial never runs
        // the pending call — it resumes the loop with the refusal so the model
        // learns of it — so refusing the denial would only strand the turn while
        // the person who wants it gone is turned away.
        $uuid = $this->suspendOn('touch_thing', 1);

        $this->coordinator(true)->approve($this->initiator(), $uuid, $this->decision(false, 1, 'touch_thing'));

        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertNotSame(
            AgentRunStatus::WAITING_FOR_APPROVAL,
            $run->statusEnum(),
            'the denial was accepted, so the run left the fence',
        );
    }

    #[Test]
    public function aColleagueReleasesTheSameRun(): void
    {
        $uuid = $this->suspendOn('touch_thing', 1);

        $this->coordinator(true)->approve($this->colleague(), $uuid, $this->decision(true, 2, 'touch_thing'));

        self::assertTrue($this->resumed, 'the second pair of eyes released the turn');
        $this->assertSettledCompleted($uuid);
    }

    #[Test]
    public function theInitiatorApprovesWhenTheSwitchIsOff(): void
    {
        // The default, and what every pre-existing record does.
        $uuid = $this->suspendOn('touch_thing', 1);

        $this->coordinator(false)->approve($this->initiator(), $uuid, $this->decision(true, 1, 'touch_thing'));

        self::assertTrue($this->resumed);
        $this->assertSettledCompleted($uuid);
    }

    #[Test]
    public function aServiceAccountIsNeverTheInitiator(): void
    {
        // A run a service account started records beUser 0, which matches
        // nobody. Four-eyes constrains humans; the scope mechanism is where a
        // machine caller's authorisation lives.
        $uuid = $this->suspendOn('list_pages', 0);

        $this->coordinator(true)->approve($this->serviceAccount(), $uuid, $this->decision(true, 0, 'list_pages'));

        self::assertTrue($this->resumed);
        $this->assertSettledCompleted($uuid);
    }

    // --- assertions --------------------------------------------------------

    private function assertStillWaiting(string $uuid): void
    {
        self::assertFalse($this->resumed, 'nothing was executed');

        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $run->statusEnum());
        self::assertNotNull($run->suspendedState, 'a colleague can still decide it');
        self::assertSame(0, $run->finishedAt, 'a refused decision never settles the run');
    }

    private function assertSettledCompleted(string $uuid): void
    {
        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::COMPLETED, $run->statusEnum());
    }

    // --- helpers -----------------------------------------------------------

    private function initiator(): AiActorContext
    {
        return AiActorContext::backendUser(1, grants: [BackendUserGrant::AGENT_APPROVE]);
    }

    private function colleague(): AiActorContext
    {
        return AiActorContext::backendUser(2, grants: [BackendUserGrant::AGENT_APPROVE]);
    }

    private function serviceAccount(): AiActorContext
    {
        return AiActorContext::serviceAccount('nightly-approver', [ServiceAccountScope::AGENT_APPROVE]);
    }

    private function decision(bool $approved, int $decidedBy, string $tool): ApprovalDecision
    {
        return new ApprovalDecision($approved, $decidedBy, (new PendingTurnDigest())->forState($this->state($tool)));
    }

    /**
     * @return string the run uuid
     */
    private function suspendOn(string $tool, int $beUser): string
    {
        $handle = $this->persister->begin(null, $beUser);
        self::assertNotNull($handle);
        self::assertTrue($this->persister->suspend($handle, $this->state($tool)));

        return $handle->uuid;
    }

    private function state(string $tool): SuspendedRunState
    {
        return new SuspendedRunState([], [ToolCall::function('c1', $tool, ['uid' => 42])->toArray()], 1, 0, 0);
    }

    private function coordinator(bool $fourEyes): ResumeCoordinator
    {
        $registry = new ToolRegistry([
            new FakeTool('touch_thing', effect: ToolEffect::NON_IDEMPOTENT_WRITE),
            new FakeTool('list_pages'),
        ]);

        $policy = new ToolCallPolicy(
            $registry,
            new ToolAvailabilityService($registry, new ToolStateRepository($this->connectionPool()), new ToolGroupStateRepository($this->connectionPool())),
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($this->localConfiguration($fourEyes));

        $loop = $this->toolLoop();

        return new ResumeCoordinator(
            $this->persister,
            $configurationRepository,
            $loop,
            new AgentRunExecutor($loop, $this->persister),
            null,
            new ToolEffectResolver($registry),
            new PendingTurnDigest(),
            $policy,
            new ActingBackendUserResolver(),
            new NullLogger(),
        );
    }

    private function toolLoop(): ToolLoopServiceInterface
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('resume')->willReturnCallback(function (): ToolLoopResult {
            $this->resumed = true;

            return new ToolLoopResult('continued', [], 1, false, UsageStatistics::fromTokens(1, 1));
        });
        $loop->method('resumeWithInput')->willReturnCallback(static function (): ToolLoopResult {
            throw new RuntimeException('resumeWithInput must not be reached', 1786800001);
        });

        return $loop;
    }

    /**
     * A LOCAL-trust-zone configuration, so the trust-zone axis of the real gate
     * permits the fake tools and the four-eyes switch is the variable.
     */
    private function localConfiguration(bool $fourEyes): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-four-eyes');
        $configuration->setLlmModel($model);
        $configuration->setRequireSecondApprover($fourEyes);

        return $configuration;
    }

    private function connectionPool(): ConnectionPool
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        return $connectionPool;
    }
}
