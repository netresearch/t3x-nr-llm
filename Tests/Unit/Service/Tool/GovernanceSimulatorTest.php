<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Enum\SimulationVerdict;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RoutingDecision;
use Netresearch\NrLlm\Domain\ValueObject\RoutingReadout;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Context\InputContextClassifier;
use Netresearch\NrLlm\Service\Context\InputContextTrustGate;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\GovernanceSimulator;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The wiring ADR-157 rests on: WHICH user the actor-scoped axis is asked with.
 *
 * The readout's own tests render a simulation that was handed to them
 * ready-made, so they prove the template. These prove the resolution — that a
 * picked uid reaches the tool gate as the resolved user rather than as the
 * operator, and that a uid resolving to nothing fails closed instead of
 * quietly answering with the operator's rights, which would report privilege
 * the account does not have.
 */
#[CoversClass(GovernanceSimulator::class)]
final class GovernanceSimulatorTest extends TestCase
{
    #[Test]
    public function noPickedActorMeansTheOperatorAndResolvesNothing(): void
    {
        $operator = $this->user(1, 'root', admin: true);
        $policy   = $this->recordingPolicy();
        $resolver = $this->resolver(null);

        $simulation = $this->simulator($policy, $resolver)
            ->simulate('get_page_tree', $this->configuration(), 0, $operator);

        self::assertSame([], $resolver->asked, 'uid 0 is the operator; there is nothing to look up');
        self::assertSame($operator, $policy->askedWith);
        self::assertSame(1, $simulation->actor->uid);
        self::assertSame('root', $simulation->actor->username);
        self::assertTrue($simulation->actor->admin);
        self::assertTrue($simulation->actor->resolved);
    }

    #[Test]
    public function aPickedActorIsResolvedAndTheGateIsAskedWithThatUser(): void
    {
        $operator = $this->user(1, 'root', admin: true);
        $editor   = $this->user(7, 'editor', admin: false);
        $policy   = $this->recordingPolicy();
        $resolver = $this->resolver($editor);

        $simulation = $this->simulator($policy, $resolver)
            ->simulate('get_page_tree', $this->configuration(), 7, $operator);

        self::assertCount(1, $resolver->asked);
        self::assertSame(7, $resolver->asked[0]->backendUserUid);
        // The whole point of the picker: an admin operator must not leak their
        // own rights into the answer they asked for someone else.
        self::assertSame($editor, $policy->askedWith);
        self::assertSame(7, $simulation->actor->uid);
        self::assertSame('editor', $simulation->actor->username);
        self::assertFalse($simulation->actor->admin, 'privilege comes from the resolved record');
        self::assertTrue($simulation->actor->resolved);
    }

    #[Test]
    public function aUidThatResolvesToNothingFailsClosedInsteadOfFallingBackToTheOperator(): void
    {
        $operator = $this->user(1, 'root', admin: true);
        $policy   = $this->recordingPolicy();
        $resolver = $this->resolver(null);

        $simulation = $this->simulator($policy, $resolver)
            ->simulate('get_page_tree', $this->configuration(), 42, $operator);

        self::assertTrue($policy->asked, 'the gate is still asked — with no user, as the runtime asks it');
        self::assertNull($policy->askedWith);
        self::assertFalse($simulation->actor->resolved, 'and the readout can say so');
        self::assertSame(42, $simulation->actor->uid, 'naming the uid the operator picked');
        self::assertSame('', $simulation->actor->username);
    }

    #[Test]
    public function theRoutingAxisIsAskedForAToolCallingRun(): void
    {
        // ADR-138: the capability requirement follows the operation. Asking
        // with none would answer for a run this page does not describe.
        $configuration     = $this->configuration();
        $modelSelection    = $this->createMock(ModelSelectionServiceInterface::class);
        $modelSelection->expects(self::once())
            ->method('explainRouting')
            ->with($configuration, ProviderOperation::Tools, null)
            ->willReturn($this->routing());

        $simulation = $this->simulator($this->recordingPolicy(), $this->resolver(null), $modelSelection)
            ->simulate('get_page_tree', $configuration, 0, $this->user(1, 'root', admin: true));

        self::assertTrue($simulation->hasServingModel());
    }

    #[Test]
    public function theApprovalAxisReadsTheToolTheRegistryHolds(): void
    {
        $policy = $this->recordingPolicy();

        $simulation = $this->simulator($policy, $this->resolver(null), null, ToolEffect::NON_IDEMPOTENT_WRITE)
            ->simulate('get_page_tree', $this->configuration(), 0, $this->user(1, 'root', admin: true));

        self::assertTrue($simulation->approvalRequired);
        self::assertSame(SimulationVerdict::ALLOW_WITH_APPROVAL, $simulation->getVerdict());
    }

    #[Test]
    public function anUnknownToolNameBindsNoApproval(): void
    {
        // ToolRegistry::get() answers null, and ToolApprovalRule turns that into
        // "nothing to approve" rather than a suspend for a call that cannot run.
        $simulation = $this->simulator($this->recordingPolicy(), $this->resolver(null))
            ->simulate('no_such_tool', $this->configuration(), 0, $this->user(1, 'root', admin: true));

        self::assertFalse($simulation->approvalRequired);
    }

    private function simulator(
        RecordingToolCallPolicy $policy,
        RecordingActingBackendUserResolver $resolver,
        ?ModelSelectionServiceInterface $modelSelection = null,
        ToolEffect $effect = ToolEffect::READ_ONLY,
    ): GovernanceSimulator {
        if (!$modelSelection instanceof ModelSelectionServiceInterface) {
            $modelSelection = self::createStub(ModelSelectionServiceInterface::class);
            $modelSelection->method('explainRouting')->willReturn($this->routing());
        }

        return new GovernanceSimulator(
            $policy,
            $this->inputContextGate(),
            $modelSelection,
            new ToolRegistry([$this->tool($effect)]),
            $resolver,
        );
    }

    /**
     * The REAL gate over a repository double. It is the collaborator whose
     * answer the simulation reports, and doubling it away would leave the
     * simulator agreeing with nothing.
     */
    private function inputContextGate(): InputContextTrustGate
    {
        $repository = self::createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturn([]);

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        return new InputContextTrustGate(
            new InputContextClassifier(new ConfigurationSnippetResolver($repository, new PromptSnippetComposer())),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver($extensionConfiguration),
            null,
        );
    }

    private function routing(): RoutingReadout
    {
        $model = new Model();
        $model->setModelId('gpt-4o');

        return RoutingReadout::decided(
            new RoutingDecision($model, [], RoutingPolicyMode::BALANCED),
            null,
            false,
            false,
        );
    }

    private function recordingPolicy(): RecordingToolCallPolicy
    {
        return new RecordingToolCallPolicy();
    }

    private function resolver(?BackendUserAuthentication $answer): RecordingActingBackendUserResolver
    {
        return new RecordingActingBackendUserResolver($answer);
    }

    private function tool(ToolEffect $effect): ToolInterface
    {
        return new class ($effect) implements ToolInterface, ToolEffectInterface {
            public function __construct(private readonly ToolEffect $effect) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('get_page_tree', 'a tool', ['type' => 'object', 'properties' => []]);
            }

            /**
             * @param array<string, mixed> $arguments
             */
            public function execute(array $arguments, ToolExecutionContext $context): ToolResult
            {
                return ToolResult::text('ok');
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'test';
            }

            public function getEffect(): ToolEffect
            {
                return $this->effect;
            }
        };
    }

    private function user(int $uid, string $username, bool $admin): BackendUserAuthentication
    {
        $user       = new BackendUserAuthentication();
        $user->user = ['uid' => $uid, 'username' => $username, 'admin' => $admin ? 1 : 0];

        return $user;
    }

    private function configuration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setIdentifier('some-provider');
        $provider->setTrustZone(TrustZone::LOCAL->value);

        $model = new Model();
        $model->setModelId('some-model');
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('simulated');
        $configuration->setLlmModel($model);

        return $configuration;
    }
}

/**
 * Records the user the actor-scoped axis was asked with.
 *
 * A hand-written double rather than a mock callback: the assertion is about
 * WHICH user object arrived, and a stub returning a fixed decision cannot say.
 *
 * @internal
 */
final class RecordingToolCallPolicy implements ToolCallPolicyInterface
{
    public bool $asked = false;

    public ?BackendUserAuthentication $askedWith = null;

    public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user): ToolPolicyDecision
    {
        $this->asked     = true;
        $this->askedWith = $user;

        return new ToolPolicyDecision(
            $toolName,
            true,
            ToolDataClass::EDITOR_CONTENT,
            TrustZone::LOCAL,
            ToolDataClass::SECRET_ADJACENT,
        );
    }

    /**
     * @param list<string>|null $requested
     *
     * @return list<string>
     */
    public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
    {
        return $requested ?? [];
    }

    /**
     * @param list<string>|null $requested
     *
     * @return list<ToolPolicyDecision>
     */
    public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
    {
        return [];
    }
}

/**
 * Records every actor the simulator asked to resolve, and answers with the one
 * user the test handed it.
 *
 * @internal
 */
final class RecordingActingBackendUserResolver implements ActingBackendUserResolverInterface
{
    /** @var list<AiActorContext> */
    public array $asked = [];

    public function __construct(private readonly ?BackendUserAuthentication $answer) {}

    public function resolve(AiActorContext $actor): ?BackendUserAuthentication
    {
        $this->asked[] = $actor;

        return $this->answer;
    }
}
