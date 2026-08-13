<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\InjectedContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The resume re-gate against the REAL, DB-backed snippet repository (ADR-166).
 *
 * The unit siblings in
 * {@see \Netresearch\NrLlm\Tests\Unit\Service\Tool\ToolLoopServiceTest} drive
 * {@see ToolLoopService::resume()} with a stubbed repository, so they pin the
 * wiring but cannot see the filter the defect lived in: a stub answers whatever
 * it was told to, active flag or not. Here the loop re-loads its forced set
 * through the production {@see PromptSnippetRepository} over real rows, which is
 * the only place the `is_active` clause can be observed.
 *
 * Every case is the same shape: a run suspends carrying snippet uids, an
 * operator changes the record while it waits, and the resumed send is asserted
 * on what reaches {@see InjectedContext} — the ADR-164 ceiling's input.
 */
#[CoversClass(ToolLoopService::class)]
#[CoversClass(PromptSnippetRepository::class)]
final class ResumeForcedSnippetReGateTest extends AbstractFunctionalTestCase
{
    private PromptSnippetRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('PromptSnippetsForcedSet.csv');

        $this->repository = $this->getService(PromptSnippetRepository::class);
    }

    #[Test]
    public function aSnippetDeactivatedWhileSuspendedKeepsItsClassOnResume(): void
    {
        // THE regression (ADR-166). uid 101 is classified secretAdjacent and was
        // switched off while the run waited for its approver. Its text is still
        // in the transcript and still goes on the wire, so the ceiling must still
        // see it. Resolving the forced set through an active-only lookup dropped
        // it, augmentationFrom() then returned null, and the send silently took
        // the configuration-only gate path.
        $seen = $this->resumeWithForcedSnippets([101]);

        self::assertInstanceOf(InjectedContext::class, $seen);
        self::assertSame(['forced-deactivated'], $this->identifiersOf($seen));
        self::assertSame(ToolDataClass::SECRET_ADJACENT, $seen->snippets[0]->getDataClassEnum());
    }

    #[Test]
    public function aClassLoweredWhileSuspendedTakesEffectOnResume(): void
    {
        // ADR-165's live-record rule, unchanged by ADR-166: uid 102 was
        // secretAdjacent when the run suspended and the operator lowered it to
        // publicContent. Identity over snapshot — the resume reflects the record
        // as it is now, in both directions.
        $seen = $this->resumeWithForcedSnippets([102]);

        self::assertInstanceOf(InjectedContext::class, $seen);
        self::assertSame(ToolDataClass::PUBLIC_CONTENT, $seen->snippets[0]->getDataClassEnum());
    }

    #[Test]
    public function aClassRaisedWhileSuspendedTakesEffectOnResume(): void
    {
        // The other direction of the same rule: uid 103 was publicContent and the
        // operator raised it. An operator who raises a class while a run is
        // suspended means it.
        $seen = $this->resumeWithForcedSnippets([103]);

        self::assertInstanceOf(InjectedContext::class, $seen);
        self::assertSame(ToolDataClass::SECRET_ADJACENT, $seen->snippets[0]->getDataClassEnum());
    }

    #[Test]
    public function aDeletedSnippetStillContributesNothingOnResume(): void
    {
        // ADR-165's "does not resurrect a deleted source", unchanged. uid 104 is
        // deleted; the ADR-166 lookup drops the is_active clause but leaves the
        // deleted restriction on, so the forced set resolves to nothing and the
        // send hands over null exactly as before.
        self::assertNull($this->resumeWithForcedSnippets([104]));
    }

    #[Test]
    public function aLegacyStateWithoutForcedUidsStillResumes(): void
    {
        // ADR-165's back-compat shape, unchanged: a row written before the field
        // existed rehydrates with no uids and must resume handing over nothing,
        // never refuse.
        $state = SuspendedRunState::fromArray([
            'messages'     => [$this->userTurn('carry on')],
            'pendingCalls' => [],
            'iterations'   => 1,
        ]);

        self::assertSame([], $state->forcedSnippetUids);
        self::assertNull($this->resume($state));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param list<int> $uids
     */
    private function resumeWithForcedSnippets(array $uids): ?InjectedContext
    {
        return $this->resume(new SuspendedRunState(
            [$this->userTurn('carry on')],
            [],
            1,
            0,
            0,
            forcedSnippetUids: $uids,
        ));
    }

    /**
     * Resume the state through a loop wired to the REAL repository and the REAL
     * composite gate, and return the {@see InjectedContext} the send carried.
     */
    private function resume(SuspendedRunState $state): ?InjectedContext
    {
        $seen  = null;
        $sends = 0;
        $mgr   = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback(
            function (
                array $messages,
                array $tools,
                LlmConfiguration $configuration,
                ?ToolOptions $options = null,
                ?AgentRunReference $run = null,
                ?InjectedContext $injectedContext = null,
            ) use (&$seen, &$sends): CompletionResponse {
                ++$sends;
                $seen = $injectedContext;

                return new CompletionResponse(
                    content: 'done',
                    model: 'test-model',
                    usage: UsageStatistics::fromTokens(0, 0),
                );
            },
        );

        $registry     = new ToolRegistry([new FakeTool('noop')]);
        $availability = new FakeToolAvailability($registry->names());
        $policy       = new ToolCallPolicy(
            $registry,
            $availability,
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        $loop = new ToolLoopService(
            $mgr,
            $registry,
            $policy,
            promptSnippetRepository: $this->repository,
        );

        $loop->resume($state, true, $this->localConfiguration(), ToolExecutionContext::none(), null, new RunTrace());

        // A null $seen must mean "the send carried no injected context", never
        // "the send never happened" — the two would otherwise be one assertion.
        self::assertSame(1, $sends, 'The resumed run never reached the manager.');

        return $seen;
    }

    /**
     * @return list<string>
     */
    private function identifiersOf(InjectedContext $context): array
    {
        return array_map(
            static fn(PromptSnippet $snippet): string => $snippet->getIdentifier(),
            $context->snippets,
        );
    }

    /**
     * A configuration whose provider sits in the LOCAL trust zone, whose ceiling
     * is SECRET_ADJACENT — so the gate's trust-zone axis genuinely runs and
     * permits, and nothing denies the run for a reason these tests are not
     * about. Same rationale as the unit sibling's localConfiguration().
     */
    private function localConfiguration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }

    /**
     * @return array<string, string>
     */
    private function userTurn(string $content): array
    {
        return ['role' => 'user', 'content' => $content];
    }
}
