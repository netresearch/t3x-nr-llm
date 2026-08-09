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
use Netresearch\NrLlm\Service\Agent\Exception\ApproverNotPermittedException;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Agent\ResumeCoordinator;
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
use Netresearch\NrLlm\Service\Tool\TrustZoneResolver;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * ADR-133: an approver may only release a write they could run themselves.
 *
 * The decision is made against the APPROVER's live backend user — the real
 * {@see ActingBackendUserResolver} over real `be_users` rows — and the real
 * composite gate ({@see ToolCallPolicy}), because both are the production code
 * this guard rests on. The run itself lives in the functional database and goes
 * through the real claim/release transitions, so "the run is decidable again" is
 * asserted on the stored row rather than on a double.
 *
 * The tool catalogue is fake: no builtin declares a write today (ADR-122), so a
 * write-declaring turn has to be constructed.
 */
#[CoversClass(ResumeCoordinator::class)]
final class ResumeCoordinatorApproverGateTest extends AbstractFunctionalTestCase
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
    public function aNonAdminWithTheApproveGrantMayNotReleaseAnAdminOnlyWrite(): void
    {
        // The confused deputy: the grant lets uid 2 DECIDE the admin's run, and
        // the turn would then execute under the admin's identity (ADR-083).
        $uuid = $this->suspendOn('delete_thing');

        try {
            $this->coordinator()->approve($this->grantedEditor(), $uuid, $this->decision(true, 2, 'delete_thing'));
            self::fail('Expected ApproverNotPermittedException');
        } catch (ApproverNotPermittedException $exception) {
            self::assertStringContainsString('requiresAdmin', $exception->getMessage(), 'the refusal names the reason');
            $this->assertStillWaiting($uuid);
        }
    }

    #[Test]
    public function theSameNonAdminReleasesAReadOnlyTurn(): void
    {
        // The other half: the gate judges write-declaring calls only, so the
        // grant keeps working for everything that changes nothing.
        $uuid = $this->suspendOn('list_pages');

        $this->coordinator()->approve($this->grantedEditor(), $uuid, $this->decision(true, 2, 'list_pages'));

        self::assertTrue($this->resumed, 'the turn was resumed');
        $this->assertSettledCompleted($uuid);
    }

    #[Test]
    public function aNonAdminReleasesAWriteTheyMayRunThemselves(): void
    {
        // The positive control: the gate is the tool policy's verdict about the
        // approver, not a blanket "non-admins may not approve writes".
        $uuid = $this->suspendOn('touch_thing');

        $this->coordinator()->approve($this->grantedEditor(), $uuid, $this->decision(true, 2, 'touch_thing'));

        self::assertTrue($this->resumed);
        $this->assertSettledCompleted($uuid);
    }

    #[Test]
    public function aServiceAccountMayNotReleaseAWriteDeclaringTurn(): void
    {
        // `touch_thing` has no admin flag, so the policy would ALLOW it for the
        // "no user" a service account presents — which is exactly why the
        // refusal cannot be left to the policy (ADR-133).
        $uuid = $this->suspendOn('touch_thing');

        try {
            $this->coordinator()->approve($this->serviceAccount(), $uuid, $this->decision(true, 0, 'touch_thing'));
            self::fail('Expected ApproverNotPermittedException');
        } catch (ApproverNotPermittedException $exception) {
            self::assertStringContainsString('Service account "nightly-approver"', $exception->getMessage());
            $this->assertStillWaiting($uuid);
        }
    }

    #[Test]
    public function aServiceAccountReleasesAReadOnlyTurn(): void
    {
        $uuid = $this->suspendOn('list_pages');

        $this->coordinator()->approve($this->serviceAccount(), $uuid, $this->decision(true, 0, 'list_pages'));

        self::assertTrue($this->resumed);
        $this->assertSettledCompleted($uuid);
    }

    // --- assertions --------------------------------------------------------

    /**
     * A refused decision leaves the run decidable: WAITING_FOR_APPROVAL with its
     * suspended state intact, not RUNNING and not terminal — and nothing ran.
     */
    private function assertStillWaiting(string $uuid): void
    {
        self::assertFalse($this->resumed, 'nothing was executed');

        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $run->statusEnum());
        self::assertNotNull($run->suspendedState, 'the state is written back, so the turn can be re-reviewed');
        self::assertSame(0, $run->finishedAt, 'a refused decision never settles the run');
    }

    private function assertSettledCompleted(string $uuid): void
    {
        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::COMPLETED, $run->statusEnum());
    }

    // --- helpers -----------------------------------------------------------

    /**
     * A non-admin backend user (uid 2) who may decide the admin's run ONLY
     * because of the ADR-130 grant — the principal ADR-133 is about.
     */
    private function grantedEditor(): AiActorContext
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
     * Start a run owned by the ADMIN (uid 1) and pause it on one pending call.
     *
     * @return string the run uuid
     */
    private function suspendOn(string $tool): string
    {
        $handle = $this->persister->begin(null, 1);
        self::assertNotNull($handle);
        self::assertTrue($this->persister->suspend($handle, $this->state($tool)));

        return $handle->uuid;
    }

    private function state(string $tool): SuspendedRunState
    {
        return new SuspendedRunState([], [ToolCall::function('c1', $tool, ['uid' => 42])->toArray()], 1, 0, 0);
    }

    /**
     * The coordinator with the REAL gate and the REAL backend-user resolver; only
     * the tool loop and the configuration lookup are doubled (this test is about
     * who may release a call, not about what the call does).
     */
    private function coordinator(): ResumeCoordinator
    {
        $registry = new ToolRegistry([
            new FakeTool('delete_thing', requiresAdmin: true, effect: ToolEffect::NON_IDEMPOTENT_WRITE),
            new FakeTool('touch_thing', effect: ToolEffect::NON_IDEMPOTENT_WRITE),
            new FakeTool('list_pages'),
        ]);

        $policy = new ToolCallPolicy(
            $registry,
            new ToolAvailabilityService($registry, new ToolStateRepository($this->connectionPool()), new ToolGroupStateRepository($this->connectionPool())),
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
        );

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($this->localConfiguration());

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

    /**
     * A loop that records whether the turn was resumed at all — the assertion
     * behind "nothing was executed" — and otherwise completes immediately.
     */
    private function toolLoop(): ToolLoopServiceInterface
    {
        $loop = self::createStub(ToolLoopServiceInterface::class);
        $loop->method('resume')->willReturnCallback(function (): ToolLoopResult {
            $this->resumed = true;

            return new ToolLoopResult('continued', [], 1, false, UsageStatistics::fromTokens(1, 1));
        });
        $loop->method('resumeWithInput')->willReturnCallback(static function (): ToolLoopResult {
            throw new RuntimeException('resumeWithInput must not be reached', 1785400001);
        });

        return $loop;
    }

    /**
     * A configuration whose provider sits in the LOCAL trust zone, so the
     * trust-zone axis of the real gate permits the fake tools and the assertions
     * are about the admin axis (see ToolLoopServiceBuiltinTest for the same
     * reasoning).
     */
    private function localConfiguration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-approver-gate');
        $configuration->setLlmModel($model);

        return $configuration;
    }

    private function connectionPool(): ConnectionPool
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        return $connectionPool;
    }
}
