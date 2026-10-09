<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Where the agent loop puts approved skill instructions (ADR-214 item 2): in
 * the transcript's system message, behind the effective system prompt — never
 * in a bare system message that would make the shaping stage drop the
 * configuration's own prompt, and never in the user turn.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceInstructionAssemblyTest extends TestCase
{
    private const CONFIG_SYSTEM_PROMPT = 'You are the configured assistant.';

    private const BODY = 'Always answer in JSON.';

    private const USER_TURN = 'translate this';

    #[Test]
    public function withoutASystemMessageTheEffectivePromptIsBakedInFrontOfTheInstructions(): void
    {
        $sent = $this->runAndCapture([ChatMessage::user(self::USER_TURN)], null);

        self::assertCount(2, $sent);
        self::assertInstanceOf(ChatMessage::class, $sent[0]);
        self::assertTrue($sent[0]->isSystem());
        self::assertStringStartsWith(self::CONFIG_SYSTEM_PROMPT . "\n\n## Approved skills", $sent[0]->content);
        self::assertStringContainsString(self::BODY, $sent[0]->content);
        self::assertInstanceOf(ChatMessage::class, $sent[1]);
        self::assertSame(self::USER_TURN, $sent[1]->content, 'the user turn carries no copy of the instruction');
    }

    #[Test]
    public function aCallersSystemMessageReceivesTheInstructions(): void
    {
        $sent = $this->runAndCapture([ChatMessage::system('Chat identity.'), ChatMessage::user(self::USER_TURN)], null);

        self::assertCount(2, $sent);
        self::assertInstanceOf(ChatMessage::class, $sent[0]);
        self::assertStringStartsWith("Chat identity.\n\n## Approved skills", $sent[0]->content);
        self::assertStringNotContainsString(self::CONFIG_SYSTEM_PROMPT, $sent[0]->content, 'a caller system message still suppresses the configuration prompt');
    }

    #[Test]
    public function onThePlaygroundPathTheInstructionsJoinTheBakedLead(): void
    {
        $snippet = new PromptSnippet();
        $snippet->setName('tone');
        $snippet->setSnippet('Use the formal register.');

        $sent = $this->runAndCapture([ChatMessage::user(self::USER_TURN)], new RunAugmentation(forcedSnippets: [$snippet]));

        self::assertCount(3, $sent);
        self::assertInstanceOf(ChatMessage::class, $sent[0]);
        self::assertStringStartsWith(self::CONFIG_SYSTEM_PROMPT . "\n\n## Approved skills", $sent[0]->content);
    }

    #[Test]
    public function anUnapprovedSkillAddsNoSystemMessage(): void
    {
        $sent = $this->runAndCapture([ChatMessage::user(self::USER_TURN)], null, approve: false);

        self::assertCount(1, $sent);
        self::assertInstanceOf(ChatMessage::class, $sent[0]);
        self::assertTrue($sent[0]->isUser());
        self::assertStringContainsString(self::BODY, $sent[0]->content);
    }

    /**
     * @param list<ChatMessage> $messages
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function runAndCapture(array $messages, ?RunAugmentation $augmentation, bool $approve = true): array
    {
        $sent    = [];
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(
            static function (array $received) use (&$sent): CompletionResponse {
                $sent = $received;

                return new CompletionResponse('done', 'test-model', UsageStatistics::fromTokens(1, 1));
            },
        );

        $skill     = $this->skill();
        $approvals = new InMemorySkillApprovalRepository();
        if ($approve) {
            $approvals->add(3, 1, $skill->getVersionDigest(), SkillVersionDigest::fieldsOf($skill), 'verified', 1);
        }

        $composer = new SkillComposer(
            SkillComposer::DEFAULT_MAX_BYTES,
            SkillTrustLevel::UNTRUSTED,
            new SkillInstructionPolicy($approvals, new FixedSkillSourceLookup([1 => SkillTrustLevel::VERIFIED]), SkillTrustLevel::VERIFIED),
        );
        $registry = new ToolRegistry([new FakeTool('noop')]);
        $service  = new ToolLoopService(
            $manager,
            $registry,
            new ToolCallPolicy(
                $registry,
                new FakeToolAvailability($registry->names()),
                new AllowedToolsResolver($composer, $registry),
                new ToolDataClassResolver($registry),
                new TrustZoneResolver(),
                new DataClassEnforcementResolver(),
            ),
            skillInjection: new SkillInjectionService($composer, new NullLogger()),
            snippetComposer: new PromptSnippetComposer(),
        );

        $service->runLoop($messages, $this->configuration($skill), ToolExecutionContext::none(), null, null, null, null, $augmentation);

        self::assertNotSame([], $sent, 'The loop did not reach the provider call.');

        /** @var list<ChatMessage|array<string, mixed>> $sent */
        return $sent;
    }

    private function configuration(Skill $skill): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('instruction-assembly');
        $configuration->setLlmModel($model);
        $configuration->setSystemPrompt(self::CONFIG_SYSTEM_PROMPT);
        $configuration->addSkill($skill);

        return $configuration;
    }

    private function skill(): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', 3);
        $skill->setSource(1);
        $skill->setIdentifier('cfg');
        $skill->setName('Config Skill');
        $skill->setBody(self::BODY);
        $skill->setBodyChecksum(hash('sha256', self::BODY));
        $skill->setSupportStatus('full');
        $skill->setEnabled(true);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }
}
