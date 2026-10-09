<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\DependencyInjection;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunRequestCodec;
use Netresearch\NrLlm\Service\Skill\SkillApprovalRepositoryInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;
use Netresearch\NrLlm\Service\Skill\SkillRecordLookupInterface;
use Netresearch\NrLlm\Service\Skill\SkillSourceLookupInterface;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicy;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolLoopServiceInterface;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Pins the production wiring of the agent loop's tool gate (ADR-120).
 *
 * {@see ToolLoopService} takes its {@see ToolCallPolicyInterface} as a required
 * constructor argument, so an install whose container cannot resolve one fails
 * to compile rather than running with a weaker gate. Nothing else asserts that:
 * every other test hand-constructs the loop, which is exactly the wiring this
 * test exists to be independent of.
 *
 * Two things are checked, and the second is the one with teeth. That the loop
 * resolves at all proves the required argument is autowirable. That the bound
 * policy is the real {@see ToolCallPolicy} proves the container reaches the
 * composite gate — the trust-zone axis included — and not some narrower
 * implementation that would satisfy the type while deciding less.
 *
 * Extends {@see FunctionalTestCase} directly rather than the project base,
 * whose setUp() converts a container-compile failure into a skipped test — the
 * precise regression this test exists to catch. Same rationale, and the same
 * scoped HashService warning suppression, as
 * {@see DashboardWidgetRegistrationTest}.
 */
final class ToolLoopGateWiringTest extends FunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
    ];

    protected function setUp(): void
    {
        set_error_handler(
            static fn(int $errno, string $errstr, string $errfile): bool => str_contains($errfile, 'Crypto/HashService.php')
                && (str_contains($errstr, 'TYPO3_CONF_VARS') || str_contains($errstr, 'array offset')),
            \E_WARNING,
        );

        try {
            parent::setUp();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function theAgentLoopResolvesWithItsRequiredToolGate(): void
    {
        $loop = $this->get(ToolLoopServiceInterface::class);

        self::assertInstanceOf(ToolLoopService::class, $loop);
    }

    #[Test]
    public function productionBindsTheCompositeToolCallPolicy(): void
    {
        // A narrower implementation would satisfy the type hint while deciding
        // less; the loop cannot tell the difference, so the container is pinned
        // here instead.
        $policy = $this->get(ToolCallPolicyInterface::class);

        self::assertInstanceOf(ToolCallPolicy::class, $policy);
    }

    #[Test]
    public function theQueuedRunCodecResolvesTheSkillAllowListAtEnqueue(): void
    {
        // The codec takes the policy as an optional collaborator, so a
        // container that did not inject it would queue runs without their
        // skill allow-list (ADR-038 item 5) and compile all the same.
        $codec = $this->get(AgentRunRequestCodec::class);
        self::assertInstanceOf(AgentRunRequestCodec::class, $codec);

        $skill = new Skill();
        $skill->setSource(1);
        $skill->setIdentifier('declaring');
        $skill->setAllowedTools('[]');
        $skill->setEnabled(true);

        $configuration = new LlmConfiguration();
        $configuration->addSkill($skill);

        $stored = $codec->dehydrate(new AgentRunRequest(
            configuration: $configuration,
            messages: [ChatMessage::user('go')],
            actor: AiActorContext::backendUser(1),
        ));

        self::assertSame(['toolNames' => []], $stored['skillAllowList'] ?? null);
    }

    /**
     * The pin check is an optional constructor argument (ADR-214 item 6), so
     * an install whose container could not build it would still compile — and
     * resume suspended runs under revoked instructions. Pinned here for that
     * reason.
     */
    #[Test]
    public function productionWiresTheSkillPinCheckIntoTheLoop(): void
    {
        $loop = $this->get(ToolLoopService::class);
        self::assertInstanceOf(ToolLoopService::class, $loop);

        self::assertInstanceOf(SkillPinCheck::class, (new ReflectionProperty(ToolLoopService::class, 'skillPinCheck'))->getValue($loop));
    }

    /**
     * The factory takes the approval store, the source lookup and the record
     * lookup as optional arguments (ADR-214 item 2). A container that left any
     * of them null would compile and silently compose every skill fenced, or
     * skip the record rule; pinned here.
     */
    #[Test]
    public function productionGivesTheSkillComposerFactoryItsInstructionCollaborators(): void
    {
        $factory = $this->get(SkillComposerFactory::class);
        self::assertInstanceOf(SkillComposerFactory::class, $factory);

        self::assertInstanceOf(SkillApprovalRepositoryInterface::class, (new ReflectionProperty(SkillComposerFactory::class, 'approvals'))->getValue($factory));
        self::assertInstanceOf(SkillSourceLookupInterface::class, (new ReflectionProperty(SkillComposerFactory::class, 'sources'))->getValue($factory));
        self::assertInstanceOf(SkillRecordLookupInterface::class, (new ReflectionProperty(SkillComposerFactory::class, 'records'))->getValue($factory));
        $policy = $factory->instructionPolicy();
        self::assertInstanceOf(SkillInstructionPolicy::class, $policy);
        self::assertInstanceOf(SkillRecordLookupInterface::class, (new ReflectionProperty(SkillInstructionPolicy::class, 'records'))->getValue($policy), 'the policy applies the record rule');
    }

    #[Test]
    public function productionInjectsTheInvocationRuleChain(): void
    {
        $loop = $this->get(ToolLoopService::class);
        self::assertInstanceOf(ToolLoopService::class, $loop);
        self::assertInstanceOf(
            ToolInvocationPolicy::class,
            (new ReflectionProperty(ToolLoopService::class, 'invocationPolicy'))->getValue(
                $loop,
            ),
        );
    }
}
