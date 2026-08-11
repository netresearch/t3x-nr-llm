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
use Netresearch\NrLlm\Service\Agent\Exception\StaleInputTurnException;
use Netresearch\NrLlm\Service\Agent\Exception\SubmitterNotPermittedException;
use Netresearch\NrLlm\Service\Agent\InputSubmission;
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
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeInputTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * ADR-150: a submitter may only feed a tool they could run themselves, and only
 * for the turn their form was rendered from.
 *
 * The input sibling of {@see ResumeCoordinatorApproverGateTest}, and deliberately
 * built the same way: the real {@see ActingBackendUserResolver} over real
 * `be_users` rows, the real composite gate ({@see ToolCallPolicy}), and a run
 * that lives in the functional database and goes through the real
 * claim/release transitions — so "the run is submittable again" is asserted on
 * the stored row.
 *
 * The tool catalogue is fake because it has to be: no builtin implements
 * {@see \Netresearch\NrLlm\Service\Tool\RequiresInputInterface}, and
 * {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\InputPauseCoverageTest}
 * pins that. {@see FakeInputTool} declares NO effect, like any input-requiring
 * tool must (ADR-105 / ADR-134) — which is exactly why the approver gate's
 * "writes only" filter cannot be reused here: it would select nothing.
 */
#[CoversClass(ResumeCoordinator::class)]
final class ResumeCoordinatorSubmitterGateTest extends AbstractFunctionalTestCase
{
    private const SCHEMA = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']];

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
    public function aNonAdminWithTheApproveGrantMayNotFeedAnAdminOnlyTool(): void
    {
        // The confused deputy #690 is about: the grant lets uid 2 SUBMIT on the
        // admin's run, and the call would then execute under the admin's
        // identity (ADR-083) with values uid 2 supplied. The tool declares no
        // write, so nothing on the ADR-133 axis would have caught it.
        $uuid = $this->suspendOn('ask_admin');

        try {
            $this->coordinator()->submitInput($this->grantedEditor(), $uuid, $this->submission('ask_admin'));
            self::fail('Expected SubmitterNotPermittedException');
        } catch (SubmitterNotPermittedException $exception) {
            self::assertStringContainsString('requiresAdmin', $exception->getMessage(), 'the refusal names the reason');
            self::assertStringContainsString('ask_admin', $exception->getMessage(), 'the refusal names the tool');
            $this->assertStillWaiting($uuid);
        }
    }

    #[Test]
    public function theSameNonAdminFeedsAToolTheyMayRunThemselves(): void
    {
        // The positive control: the gate is the tool policy's verdict about the
        // submitter, not a blanket "non-admins may not submit".
        $uuid = $this->suspendOn('ask_user');

        $this->coordinator()->submitInput($this->grantedEditor(), $uuid, $this->submission('ask_user'));

        self::assertTrue($this->resumed, 'the turn was resumed');
        $this->assertSettledCompleted($uuid);
    }

    #[Test]
    public function aServiceAccountMayNotSupplyInputAtAll(): void
    {
        // `ask_user` has no admin flag, so the policy would ALLOW it for the
        // "no user" a service account presents — which is why the refusal
        // cannot be left to the policy (the same reasoning as ADR-133).
        $uuid = $this->suspendOn('ask_user');

        try {
            $this->coordinator()->submitInput($this->serviceAccount(), $uuid, $this->submission('ask_user', 0));
            self::fail('Expected SubmitterNotPermittedException');
        } catch (SubmitterNotPermittedException $exception) {
            self::assertStringContainsString('Service account "nightly-submitter"', $exception->getMessage());
            $this->assertStillWaiting($uuid);
        }
    }

    #[Test]
    public function aSubmissionForADifferentTurnIsRefused(): void
    {
        // The stale form: the values were written against a turn whose pending
        // call carried other arguments. Nothing executes, and the run is handed
        // back so the CURRENT form can be re-opened.
        $uuid  = $this->suspendOn('ask_user');
        $stale = (new PendingTurnDigest())->forInputState($this->state('ask_user', ['uid' => 99]));

        try {
            $this->coordinator()->submitInput(
                $this->grantedEditor(),
                $uuid,
                new InputSubmission(['city' => 'Berlin'], 2, $stale),
            );
            self::fail('Expected StaleInputTurnException');
        } catch (StaleInputTurnException) {
            $this->assertStillWaiting($uuid);
        }
    }

    #[Test]
    public function aSubmissionWithoutADigestIsRefusedLikeAWrongOne(): void
    {
        // "No digest" and "the wrong digest" prove the same thing: the turn the
        // form was rendered from is not known.
        $uuid = $this->suspendOn('ask_user');

        try {
            $this->coordinator()->submitInput($this->grantedEditor(), $uuid, new InputSubmission(['city' => 'Berlin'], 2));
            self::fail('Expected StaleInputTurnException');
        } catch (StaleInputTurnException) {
            $this->assertStillWaiting($uuid);
        }
    }

    #[Test]
    public function aDigestOverThePendingCallsAloneIsNotEnough(): void
    {
        // The input digest covers the target tool and the declared schema on top
        // of the pending calls (ADR-150), so the approval digest — which covers
        // the calls only — cannot stand in for it. This is what makes a matching
        // digest also a statement about the schema the values were validated
        // against.
        $uuid            = $this->suspendOn('ask_user');
        $callsOnlyDigest = (new PendingTurnDigest())->forState($this->state('ask_user'));

        try {
            $this->coordinator()->submitInput(
                $this->grantedEditor(),
                $uuid,
                new InputSubmission(['city' => 'Berlin'], 2, $callsOnlyDigest),
            );
            self::fail('Expected StaleInputTurnException');
        } catch (StaleInputTurnException) {
            $this->assertStillWaiting($uuid);
        }
    }

    // --- assertions --------------------------------------------------------

    /**
     * A refused submission leaves the run submittable: WAITING_FOR_INPUT with
     * its suspended state intact, not RUNNING and not terminal — and nothing
     * ran.
     */
    private function assertStillWaiting(string $uuid): void
    {
        self::assertFalse($this->resumed, 'nothing was executed');

        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::WAITING_FOR_INPUT, $run->statusEnum());
        self::assertNotNull($run->suspendedState, 'the state is written back, so the form can be re-opened');
        self::assertSame(0, $run->finishedAt, 'a refused submission never settles the run');
    }

    private function assertSettledCompleted(string $uuid): void
    {
        $run = $this->persister->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::COMPLETED, $run->statusEnum());
    }

    // --- helpers -----------------------------------------------------------

    /**
     * A non-admin backend user (uid 2) who may act on the admin's run ONLY
     * because of the ADR-130 grant — the principal ADR-150 is about.
     */
    private function grantedEditor(): AiActorContext
    {
        return AiActorContext::backendUser(2, grants: [BackendUserGrant::AGENT_APPROVE]);
    }

    private function serviceAccount(): AiActorContext
    {
        return AiActorContext::serviceAccount('nightly-submitter', [ServiceAccountScope::AGENT_APPROVE]);
    }

    private function submission(string $tool, int $submittedBy = 2): InputSubmission
    {
        return new InputSubmission(
            ['city' => 'Berlin'],
            $submittedBy,
            (new PendingTurnDigest())->forInputState($this->state($tool)),
        );
    }

    /**
     * Start a run owned by the ADMIN (uid 1) and pause it for input on one
     * pending call.
     *
     * @return string the run uuid
     */
    private function suspendOn(string $tool): string
    {
        $handle = $this->persister->begin(null, 1);
        self::assertNotNull($handle);
        self::assertTrue($this->persister->suspendForInput($handle, $this->state($tool)));

        return $handle->uuid;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function state(string $tool, array $arguments = ['uid' => 42]): SuspendedRunState
    {
        return new SuspendedRunState(
            [],
            [ToolCall::function('c1', $tool, $arguments)->toArray()],
            1,
            0,
            0,
            null,
            [],
            $tool,
            self::SCHEMA,
        );
    }

    /**
     * The coordinator with the REAL gate, the REAL backend-user resolver and the
     * REAL schema validator; only the tool loop and the configuration lookup are
     * doubled (this test is about who may submit, not about what the call does).
     */
    private function coordinator(): ResumeCoordinator
    {
        $registry = new ToolRegistry([
            new FakeInputTool('ask_admin', self::SCHEMA, requiresAdmin: true),
            new FakeInputTool('ask_user', self::SCHEMA),
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
        $loop->method('resumeWithInput')->willReturnCallback(function (): ToolLoopResult {
            $this->resumed = true;

            return new ToolLoopResult('continued', [], 1, false, UsageStatistics::fromTokens(1, 1));
        });
        $loop->method('resume')->willReturnCallback(static function (): ToolLoopResult {
            throw new RuntimeException('resume must not be reached on the input path', 1786000001);
        });

        return $loop;
    }

    /**
     * A configuration whose provider sits in the LOCAL trust zone, so the
     * trust-zone axis of the real gate permits the fake tools and the assertions
     * are about the admin axis.
     */
    private function localConfiguration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-submitter-gate');
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
