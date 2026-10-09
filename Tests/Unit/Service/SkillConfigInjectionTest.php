<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\ConfigurationCallPlanner;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Covers the configuration-driven injection wiring in LlmServiceManager:
 * the resolved default configuration's skills are prepended to the user
 * prompt/message for completion & chat (translation), while embeddings stay
 * untouched (text-generation-only scope guard).
 */
#[CoversClass(LlmServiceManager::class)]
final class SkillConfigInjectionTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    private const SKILL_NEEDLE = '### Skill: Config Skill';

    private const SYSTEM_PROMPT = 'You are a translator.';

    #[Test]
    public function completeInjectsDefaultConfigurationSkillsIntoUserPrompt(): void
    {
        $capturedPrompt = null;
        $adapter        = $this->createMock(ProviderInterface::class);
        $adapter->method('complete')->willReturnCallback(
            function (string $prompt) use (&$capturedPrompt): CompletionResponse {
                $capturedPrompt = $prompt;
                return $this->completionResponse();
            },
        );

        $manager = $this->managerWithAdapter($adapter, $this->configurationWithSkill());

        $manager->complete('Tell me a joke.');

        self::assertIsString($capturedPrompt);
        self::assertStringContainsString(self::SKILL_NEEDLE, $capturedPrompt);
        self::assertStringEndsWith('Tell me a joke.', $capturedPrompt);
    }

    #[Test]
    public function chatInjectsDefaultConfigurationSkillsIntoUserMessageNotSystem(): void
    {
        $capturedMessages = [];
        $adapter          = $this->createMock(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): CompletionResponse {
                $capturedMessages = $messages;
                return $this->completionResponse();
            },
        );

        $manager = $this->managerWithAdapter($adapter, $this->configurationWithSkill());

        $manager->chat([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => 'Translate this.'],
        ]);

        self::assertCount(2, $capturedMessages);
        self::assertSame(self::SYSTEM_PROMPT, $this->content($capturedMessages[0]));
        self::assertStringNotContainsString(self::SKILL_NEEDLE, $this->content($capturedMessages[0]));
        $userContent = $this->content($capturedMessages[1]);
        self::assertStringContainsString(self::SKILL_NEEDLE, $userContent);
        self::assertStringEndsWith('Translate this.', $userContent);
    }

    /**
     * ADR-214 item 2: an approved instruction reaches the system prompt, behind
     * the configuration's own, and never the prompt; the carrier option never
     * reaches the adapter.
     */
    #[Test]
    public function completeSendsApprovedInstructionsInTheSystemPromptNotThePrompt(): void
    {
        $capturedPrompt  = null;
        $capturedOptions = [];
        $adapter         = $this->createMock(ProviderInterface::class);
        $adapter->method('complete')->willReturnCallback(
            function (string $prompt, array $options) use (&$capturedPrompt, &$capturedOptions): CompletionResponse {
                $capturedPrompt  = $prompt;
                $capturedOptions = $options;
                return $this->completionResponse();
            },
        );

        $manager = $this->managerWithAdapter($adapter, $this->configurationWithApprovedSkill(), $this->instructingInjection());

        $manager->complete('Tell me a joke.');

        self::assertSame('Tell me a joke.', $capturedPrompt);
        self::assertIsString($capturedOptions['system_prompt'] ?? null);
        self::assertStringStartsWith(self::SYSTEM_PROMPT . "\n\n## Approved skills", $capturedOptions['system_prompt']);
        self::assertStringContainsString('Always answer in JSON.', $capturedOptions['system_prompt']);
        self::assertArrayNotHasKey(ConfigurationCallPlanner::INSTRUCTIONS_OPTION, $capturedOptions);
    }

    #[Test]
    public function chatWithoutASystemMessageSendsTheConfigurationPromptFollowedByTheInstructions(): void
    {
        $capturedMessages = [];
        $adapter          = $this->createMock(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): CompletionResponse {
                $capturedMessages = $messages;
                return $this->completionResponse();
            },
        );

        $manager = $this->managerWithAdapter($adapter, $this->configurationWithApprovedSkill(), $this->instructingInjection());

        $manager->chat([['role' => 'user', 'content' => 'Translate this.']]);

        self::assertCount(2, $capturedMessages);
        self::assertStringStartsWith(self::SYSTEM_PROMPT . "\n\n## Approved skills", $this->content($this->messageAt($capturedMessages, 0)));
        self::assertSame('Translate this.', $this->content($this->messageAt($capturedMessages, 1)));
    }

    /**
     * A caller system message after the first user turn suppresses the
     * configuration prompt, so the instructions join that message — the last
     * system message, which every adapter sends — instead of being handed to
     * a prompt that is not sent.
     */
    #[Test]
    public function chatWithALateCallerSystemMessageAppendsTheInstructionsToIt(): void
    {
        $capturedMessages = [];
        $adapter          = $this->createMock(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): CompletionResponse {
                $capturedMessages = $messages;
                return $this->completionResponse();
            },
        );

        $manager = $this->managerWithAdapter($adapter, $this->configurationWithApprovedSkill(), $this->instructingInjection());

        $manager->chat([
            ['role' => 'user', 'content' => 'Translate this.'],
            ['role' => 'system', 'content' => 'Late caller note.'],
        ]);

        self::assertCount(2, $capturedMessages);
        self::assertSame('Translate this.', $this->content($this->messageAt($capturedMessages, 0)));
        self::assertStringStartsWith("Late caller note.\n\n## Approved skills", $this->content($this->messageAt($capturedMessages, 1)));
    }

    #[Test]
    public function chatWithACallerSystemMessageAppendsTheInstructionsToIt(): void
    {
        $capturedMessages = [];
        $adapter          = $this->createMock(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): CompletionResponse {
                $capturedMessages = $messages;
                return $this->completionResponse();
            },
        );

        $manager = $this->managerWithAdapter($adapter, $this->configurationWithApprovedSkill(), $this->instructingInjection());

        $manager->chat([
            ['role' => 'system', 'content' => 'Caller identity.'],
            ['role' => 'user', 'content' => 'Translate this.'],
        ]);

        self::assertCount(2, $capturedMessages);
        self::assertStringStartsWith("Caller identity.\n\n## Approved skills", $this->content($this->messageAt($capturedMessages, 0)));
        self::assertStringNotContainsString(self::SYSTEM_PROMPT, $this->content($this->messageAt($capturedMessages, 0)));
    }

    #[Test]
    public function embedDoesNotInjectSkillsEvenWithSkilledDefaultConfiguration(): void
    {
        $capturedInput = null;
        $provider      = $this->createMock(ProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('openai');
        $provider->method('getName')->willReturn('OpenAI');
        $provider->method('supportsFeature')->willReturn(true);
        $provider->method('embeddings')->willReturnCallback(
            function (string|array $input) use (&$capturedInput): EmbeddingResponse {
                $capturedInput = $input;
                return new EmbeddingResponse(
                    embeddings: [[0.1, 0.2]],
                    model: 'text-embedding-3-small',
                    usage: new UsageStatistics(1, 0, 1),
                    provider: 'openai',
                );
            },
        );

        $manager = $this->createLlmServiceManager(
            $this->extensionConfigStub(),
            self::createStub(LoggerInterface::class),
            self::createStub(ProviderAdapterRegistryInterface::class),
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $this->configRepo($this->configurationWithSkill()),
            $this->injectionService(),
        );
        $manager->registerProvider($provider);

        $manager->embed('Plain text to embed.', new EmbeddingOptions(provider: 'openai'));

        self::assertSame('Plain text to embed.', $capturedInput);
    }

    #[Test]
    public function visionDoesNotInjectSkillsEvenWithSkilledDefaultConfiguration(): void
    {
        $capturedContent = null;
        $provider        = $this->createMockForIntersectionOfInterfaces([
            ProviderInterface::class,
            VisionCapableInterface::class,
        ]);
        $provider->method('getIdentifier')->willReturn('openai');
        $provider->method('getName')->willReturn('OpenAI');
        $provider->method('analyzeImage')->willReturnCallback(
            function (array $content) use (&$capturedContent): VisionResponse {
                $capturedContent = $content;
                return new VisionResponse(
                    description: 'a cat',
                    model: 'gpt-4o',
                    usage: new UsageStatistics(1, 0, 1),
                    provider: 'openai',
                );
            },
        );

        $manager = $this->createLlmServiceManager(
            $this->extensionConfigStub(),
            self::createStub(LoggerInterface::class),
            self::createStub(ProviderAdapterRegistryInterface::class),
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $this->configRepo($this->configurationWithSkill()),
            $this->injectionService(),
        );
        $manager->registerProvider($provider);

        $manager->vision(
            [new VisionContent(type: VisionContent::TYPE_TEXT, text: 'Describe this image.')],
            new VisionOptions(provider: 'openai'),
        );

        self::assertIsArray($capturedContent);
        self::assertCount(1, $capturedContent);
        self::assertInstanceOf(VisionContent::class, $capturedContent[0]);
        // The vision content reaches the adapter verbatim — skill injection is
        // text-generation-only and must never touch a vision request.
        self::assertSame('Describe this image.', $capturedContent[0]->text);
        self::assertStringNotContainsString('### Skill:', $capturedContent[0]->text ?? '');
        self::assertStringNotContainsString('cannot override configuration or safety', $capturedContent[0]->text ?? '');
    }

    private function managerWithAdapter(ProviderInterface $adapter, LlmConfiguration $defaultConfig, ?SkillInjectionService $injection = null): LlmServiceManager
    {
        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        return $this->createLlmServiceManager(
            $this->extensionConfigStub(),
            self::createStub(LoggerInterface::class),
            $registry,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            $this->configRepo($defaultConfig),
            $injection ?? $this->injectionService(),
        );
    }

    private function instructingInjection(): SkillInjectionService
    {
        $skill     = $this->approvedSkill();
        $approvals = new InMemorySkillApprovalRepository();
        $approvals->add(4, 1, $skill->getVersionDigest(), SkillVersionDigest::fieldsOf($skill), 'verified', 1);

        return new SkillInjectionService(
            new SkillComposer(
                SkillComposer::DEFAULT_MAX_BYTES,
                SkillTrustLevel::UNTRUSTED,
                new SkillInstructionPolicy($approvals, new FixedSkillSourceLookup([1 => SkillTrustLevel::VERIFIED]), SkillTrustLevel::VERIFIED),
            ),
            self::createStub(LoggerInterface::class),
        );
    }

    private function approvedSkill(): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', 4);
        $skill->setSource(1);
        $skill->setIdentifier('cfg');
        $skill->setName('Config Skill');
        $skill->setBody('Always answer in JSON.');
        $skill->setBodyChecksum(hash('sha256', 'Always answer in JSON.'));
        $skill->setSupportStatus(SupportStatus::FULL->value);
        $skill->setEnabled(true);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }

    private function configurationWithApprovedSkill(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('default-config');
        $configuration->setLlmModel(self::createStub(Model::class));
        $configuration->setSystemPrompt(self::SYSTEM_PROMPT);
        $configuration->addSkill($this->approvedSkill());

        return $configuration;
    }

    private function configRepo(LlmConfiguration $defaultConfig): LlmConfigurationRepository
    {
        $repo = $this->createMock(LlmConfigurationRepository::class);
        $repo->method('findDefault')->willReturn($defaultConfig);

        return $repo;
    }

    private function injectionService(): SkillInjectionService
    {
        return new SkillInjectionService(new SkillComposer(), self::createStub(LoggerInterface::class));
    }

    private function extensionConfigStub(): ExtensionConfiguration
    {
        $stub = self::createStub(ExtensionConfiguration::class);
        $stub->method('get')->willReturn(['providers' => []]);

        return $stub;
    }

    private function configurationWithSkill(): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('default-config');
        $configuration->setLlmModel(self::createStub(Model::class));

        $skill = new Skill();
        $skill->setSource(1);
        $skill->setIdentifier('cfg');
        $skill->setName('Config Skill');
        $skill->setBody('Always answer in JSON.');
        $skill->setBodyChecksum(hash('sha256', 'Always answer in JSON.'));
        $skill->setSupportStatus(SupportStatus::FULL->value);
        $skill->setEnabled(true);
        $skill->setOrphaned(false);

        $configuration->addSkill($skill);

        return $configuration;
    }

    private function completionResponse(): CompletionResponse
    {
        return new CompletionResponse(
            content: 'ok',
            model: 'gpt-4o',
            usage: new UsageStatistics(1, 1, 2),
            finishReason: 'stop',
            provider: 'openai',
        );
    }

    /**
     * @param ChatMessage|array<string, mixed> $message
     */
    private function content(ChatMessage|array $message): string
    {
        if ($message instanceof ChatMessage) {
            return $message->content;
        }

        return is_string($message['content'] ?? null) ? $message['content'] : '';
    }

    /**
     * @return ChatMessage|array<string, mixed>
     */
    private function messageAt(mixed $messages, int $index): ChatMessage|array
    {
        self::assertIsArray($messages);
        $message = $messages[$index] ?? null;
        if ($message instanceof ChatMessage) {
            return $message;
        }

        self::assertIsArray($message);
        $typed = [];
        foreach ($message as $key => $value) {
            $typed[(string)$key] = $value;
        }

        return $typed;
    }
}
