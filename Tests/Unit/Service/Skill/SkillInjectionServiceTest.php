<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

#[CoversClass(SkillInjectionService::class)]
final class SkillInjectionServiceTest extends TestCase
{
    private const PREAMBLE_NEEDLE = 'cannot override configuration or safety';

    private const USER_INPUT = 'Summarise the changelog.';

    #[Test]
    public function augmentPromptPrependsComposedBlockToUserPrompt(): void
    {
        $subject = $this->subject();
        $skill   = $this->makeSkill('alpha', 'Alpha Skill', 'Always cite sources.');

        $augmented = $subject->augmentPrompt(self::USER_INPUT, [$skill], []);

        self::assertStringContainsString(self::PREAMBLE_NEEDLE, $augmented);
        self::assertStringContainsString('### Skill: Alpha Skill', $augmented);
        self::assertStringContainsString('Always cite sources.', $augmented);
        self::assertStringEndsWith("\n\n" . self::USER_INPUT, $augmented);
        // The skill block precedes the user input.
        self::assertLessThan(
            strpos($augmented, self::USER_INPUT),
            strpos($augmented, '### Skill: Alpha Skill'),
        );
    }

    #[Test]
    public function augmentPromptReturnsPromptUnchangedWhenNoSkills(): void
    {
        self::assertSame(
            self::USER_INPUT,
            $this->subject()->augmentPrompt(self::USER_INPUT, [], []),
        );
    }

    #[Test]
    public function augmentPromptCombinesConfigBaselineBeforeTaskAdditive(): void
    {
        $subject     = $this->subject();
        $configSkill = $this->makeSkill('cfg', 'Config Skill', 'Config baseline.');
        $taskSkill   = $this->makeSkill('task', 'Task Skill', 'Task additive.');

        $augmented = $subject->augmentPrompt(self::USER_INPUT, [$configSkill], [$taskSkill]);

        self::assertLessThan(
            strpos($augmented, '### Skill: Task Skill'),
            strpos($augmented, '### Skill: Config Skill'),
        );
    }

    #[Test]
    public function augmentPromptSkipsChecksumMismatchAndLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $subject = new SkillInjectionService(new SkillComposer(), $logger);
        $skill   = $this->makeSkill('tampered', 'Tampered Skill', 'Body.', checksum: 'deadbeef');

        $augmented = $subject->augmentPrompt(self::USER_INPUT, [$skill], []);

        self::assertSame(self::USER_INPUT, $augmented);
    }

    #[Test]
    public function augmentMessagesPrependsBlockToFirstUserMessageOnly(): void
    {
        $subject  = $this->subject();
        $skill    = $this->makeSkill('alpha', 'Alpha Skill', 'Always cite sources.');
        $messages = [
            ['role' => 'system', 'content' => 'You are a translator.'],
            ['role' => 'user', 'content' => 'First user message.'],
            ['role' => 'user', 'content' => 'Second user message.'],
        ];

        $augmented = $subject->augmentMessages($messages, [$skill], []);

        self::assertIsArray($augmented[0]);
        self::assertSame('You are a translator.', $augmented[0]['content']);
        self::assertIsArray($augmented[1]);
        self::assertIsString($augmented[1]['content']);
        self::assertStringContainsString('### Skill: Alpha Skill', $augmented[1]['content']);
        self::assertStringEndsWith('First user message.', $augmented[1]['content']);
        self::assertIsArray($augmented[2]);
        self::assertSame('Second user message.', $augmented[2]['content']);
    }

    #[Test]
    public function augmentMessagesLeavesMessagesUntouchedWhenNoUserMessage(): void
    {
        $subject  = $this->subject();
        $skill    = $this->makeSkill('alpha', 'Alpha Skill', 'Always cite sources.');
        $messages = [
            ['role' => 'system', 'content' => 'You are a translator.'],
        ];

        self::assertSame($messages, $subject->augmentMessages($messages, [$skill], []));
    }

    #[Test]
    public function augmentMessagesHandlesChatMessageValueObjects(): void
    {
        $subject  = $this->subject();
        $skill    = $this->makeSkill('alpha', 'Alpha Skill', 'Always cite sources.');
        $messages = [
            ChatMessage::system('You are a translator.'),
            ChatMessage::user('Translate this.'),
        ];

        $augmented = $subject->augmentMessages($messages, [$skill], []);

        self::assertInstanceOf(ChatMessage::class, $augmented[0]);
        self::assertTrue($augmented[0]->isSystem());
        self::assertSame('You are a translator.', $augmented[0]->content);
        self::assertInstanceOf(ChatMessage::class, $augmented[1]);
        self::assertTrue($augmented[1]->isUser());
        self::assertStringContainsString('### Skill: Alpha Skill', $augmented[1]->content);
        self::assertStringEndsWith('Translate this.', $augmented[1]->content);
    }

    #[Test]
    public function toListFiltersObjectStorageToSkills(): void
    {
        $storage = new ObjectStorage();
        $storage->attach($this->makeSkill('one', 'One', 'Body one.'));
        $storage->attach($this->makeSkill('two', 'Two', 'Body two.'));

        $list = SkillInjectionService::toList($storage);

        self::assertCount(2, $list);
        self::assertContainsOnlyInstancesOf(Skill::class, $list);
    }

    private function subject(): SkillInjectionService
    {
        return new SkillInjectionService(new SkillComposer(), self::createStub(LoggerInterface::class));
    }

    private function makeSkill(
        string $identifier,
        string $name,
        string $body,
        int $source = 1,
        SupportStatus $support = SupportStatus::FULL,
        ?string $checksum = null,
    ): Skill {
        $skill = new Skill();
        $skill->setSource($source);
        $skill->setIdentifier($identifier);
        $skill->setName($name);
        $skill->setBody($body);
        $skill->setBodyChecksum($checksum ?? hash('sha256', $body));
        $skill->setSupportStatus($support->value);
        $skill->setEnabled(true);
        $skill->setOrphaned(false);

        return $skill;
    }
}
