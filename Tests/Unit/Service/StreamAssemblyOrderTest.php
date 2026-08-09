<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Generator;
use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Characterisation of the order in which context reaches a streaming request
 * (#637).
 *
 * Streaming assembles in two steps that sit far apart in the manager: the
 * resolved default configuration's skills are prepended to the first USER
 * message on the way in (`streamChat()`), and the configuration's system prompt
 * is prepended to the whole list inside the opener, immediately before the
 * adapter call. The resulting order is what these tests state.
 */
#[CoversClass(LlmServiceManager::class)]
final class StreamAssemblyOrderTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    private const SYSTEM_PROMPT = 'You are the configured assistant.';

    private const SKILL_HEADING = '### Skill: Config Skill';

    private const USER_TURN = 'Hello';

    /** @var list<ChatMessage|array<string, mixed>> */
    private array $sent = [];

    #[Test]
    public function streamingSendsTheConfigurationSystemPromptAheadOfTheSkillAugmentedUserTurn(): void
    {
        iterator_to_array($this->manager()->streamChat([['role' => 'user', 'content' => self::USER_TURN]]));

        self::assertSame(['system', 'user'], $this->roles());
        self::assertSame(self::SYSTEM_PROMPT, $this->contentOf($this->sent[0]));

        $userContent = $this->contentOf($this->sent[1]);
        self::assertStringContainsString(self::SKILL_HEADING, $userContent);
        self::assertStringEndsWith(self::USER_TURN, $userContent);
        // The skill block never enters the system role.
        self::assertStringNotContainsString(self::SKILL_HEADING, $this->contentOf($this->sent[0]));
    }

    /**
     * A caller-supplied system message occupies position 0, so the shaper's
     * guard fires and the configuration's system prompt is not sent at all.
     * Per-call precedence is the documented rule; the skill block still goes to
     * the first user message, not to the caller's system message.
     */
    #[Test]
    public function aCallerSuppliedSystemMessageReplacesTheConfigurationSystemPrompt(): void
    {
        iterator_to_array($this->manager()->streamChat([
            ['role' => 'system', 'content' => 'Caller system message.'],
            ['role' => 'user', 'content' => self::USER_TURN],
        ]));

        self::assertSame(['system', 'user'], $this->roles());
        self::assertSame('Caller system message.', $this->contentOf($this->sent[0]));
        foreach ($this->sent as $message) {
            self::assertStringNotContainsString(self::SYSTEM_PROMPT, $this->contentOf($message));
        }

        self::assertStringContainsString(self::SKILL_HEADING, $this->contentOf($this->sent[1]));
    }

    private function manager(): LlmServiceManager
    {
        $adapter = $this->createMockForIntersectionOfInterfaces([
            ProviderInterface::class,
            StreamingCapableInterface::class,
        ]);
        $adapter->method('getIdentifier')->willReturn('capture');
        $adapter->method('supportsStreaming')->willReturn(true);
        $adapter->method('streamChatCompletion')->willReturnCallback(
            function (array $messages): Generator {
                /** @var list<ChatMessage|array<string, mixed>> $messages */
                $this->sent = $messages;

                yield 'chunk';
            },
        );

        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findDefault')->willReturn($this->configuration());

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['providers' => []]);

        return $this->createLlmServiceManager(
            $extensionConfiguration,
            self::createStub(LoggerInterface::class),
            $registry,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $configurationRepository,
            new SkillInjectionService(new SkillComposer(), self::createStub(LoggerInterface::class)),
        );
    }

    private function configuration(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('assembly-order');
        $configuration->setLlmModel(new Model());
        $configuration->setSystemPrompt(self::SYSTEM_PROMPT);

        $body  = 'Always answer in JSON.';
        $skill = new Skill();
        $skill->setSource(1);
        $skill->setIdentifier('cfg');
        $skill->setName('Config Skill');
        $skill->setBody($body);
        $skill->setBodyChecksum(hash('sha256', $body));
        $skill->setSupportStatus(SupportStatus::FULL->value);
        $skill->setEnabled(true);
        $skill->setOrphaned(false);

        $configuration->addSkill($skill);

        return $configuration;
    }

    /**
     * @return list<string>
     */
    private function roles(): array
    {
        return array_map(
            static function (ChatMessage|array $message): string {
                if ($message instanceof ChatMessage) {
                    return $message->role;
                }

                return is_string($message['role'] ?? null) ? $message['role'] : '';
            },
            $this->sent,
        );
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    private function contentOf(ChatMessage|array $message): string
    {
        if ($message instanceof ChatMessage) {
            return $message->content;
        }

        return is_string($message['content'] ?? null) ? $message['content'] : '';
    }
}
