<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
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
    public function composeIntoPromptPrependsComposedBlockToUserPrompt(): void
    {
        $subject = $this->subject();
        $skill   = $this->makeSkill('alpha', 'Alpha Skill', 'Always cite sources.');

        $augmented = $subject->composeIntoPrompt(self::USER_INPUT, [$skill], [])['prompt'];

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
    public function composeIntoPromptReturnsPromptUnchangedWhenNoSkills(): void
    {
        self::assertSame(
            self::USER_INPUT,
            $this->subject()->composeIntoPrompt(self::USER_INPUT, [], [])['prompt'],
        );
    }

    #[Test]
    public function composeIntoPromptCombinesConfigBaselineBeforeTaskAdditive(): void
    {
        $subject     = $this->subject();
        $configSkill = $this->makeSkill('cfg', 'Config Skill', 'Config baseline.');
        $taskSkill   = $this->makeSkill('task', 'Task Skill', 'Task additive.');

        $augmented = $subject->composeIntoPrompt(self::USER_INPUT, [$configSkill], [$taskSkill])['prompt'];

        self::assertLessThan(
            strpos($augmented, '### Skill: Task Skill'),
            strpos($augmented, '### Skill: Config Skill'),
        );
    }

    #[Test]
    public function composeIntoPromptSkipsChecksumMismatchAndLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $subject = new SkillInjectionService(new SkillComposer(), $logger);
        $skill   = $this->makeSkill('tampered', 'Tampered Skill', 'Body.', checksum: 'deadbeef');

        $augmented = $subject->composeIntoPrompt(self::USER_INPUT, [$skill], [])['prompt'];

        self::assertSame(self::USER_INPUT, $augmented);
    }

    #[Test]
    public function composeIntoMessagesPrependsBlockToFirstUserMessageOnly(): void
    {
        $subject  = $this->subject();
        $skill    = $this->makeSkill('alpha', 'Alpha Skill', 'Always cite sources.');
        $messages = [
            ['role' => 'system', 'content' => 'You are a translator.'],
            ['role' => 'user', 'content' => 'First user message.'],
            ['role' => 'user', 'content' => 'Second user message.'],
        ];

        $augmented = $subject->composeIntoMessages($messages, [$skill], [])['messages'];

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
    public function composeIntoMessagesLeavesMessagesUntouchedWhenNoUserMessage(): void
    {
        $subject  = $this->subject();
        $skill    = $this->makeSkill('alpha', 'Alpha Skill', 'Always cite sources.');
        $messages = [
            ['role' => 'system', 'content' => 'You are a translator.'],
        ];

        self::assertSame($messages, $subject->composeIntoMessages($messages, [$skill], [])['messages']);
    }

    /**
     * A multimodal user turn carries `content` as a list of OpenAI-style parts
     * (`{type: text}`, `{type: image_url}`), which `MessageShaper::normalise()`
     * deliberately passes through untouched for the adapters to convert. The
     * block belongs on THAT turn, as a leading text part -- previously the
     * predicate required a string, so the block skipped ahead to a later turn.
     */
    #[Test]
    public function composeIntoMessagesPrependsATextPartToAMultimodalUserMessage(): void
    {
        $subject  = $this->subject();
        $skill    = $this->makeSkill('alpha', 'Alpha Skill', 'Always cite sources.');
        $messages = [
            ['role' => 'system', 'content' => 'You are a translator.'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'What is in this image?'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']],
            ]],
            ['role' => 'user', 'content' => 'A later text-only turn.'],
        ];

        $augmented = $subject->composeIntoMessages($messages, [$skill], [])['messages'];

        self::assertIsArray($augmented[1]);
        $content = $augmented[1]['content'];
        self::assertIsArray($content);
        self::assertCount(3, $content, 'the block is one added part, nothing is replaced');

        $first = $content[0];
        self::assertIsArray($first);
        self::assertSame('text', $first['type']);
        self::assertIsString($first['text']);
        self::assertStringContainsString('### Skill: Alpha Skill', $first['text']);

        // The original parts survive in order.
        self::assertSame(['type' => 'text', 'text' => 'What is in this image?'], $content[1]);
        self::assertSame(['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']], $content[2]);

        // And the later text turn is untouched: still only one injection site.
        self::assertIsArray($augmented[2]);
        self::assertSame('A later text-only turn.', $augmented[2]['content']);
    }

    /**
     * The silent case the issue asks to close: a block was composed and there
     * was nowhere to put it. Returning the list unchanged is right; doing it
     * without a word is not.
     */
    #[Test]
    public function composeIntoMessagesWarnsWhenAComposedBlockFindsNoUserMessage(): void
    {
        $logger  = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('no user message'));

        $subject  = new SkillInjectionService(new SkillComposer(), $logger);
        $skill    = $this->makeSkill('alpha', 'Alpha Skill', 'Always cite sources.');
        $messages = [['role' => 'system', 'content' => 'You are a translator.']];

        self::assertSame($messages, $subject->composeIntoMessages($messages, [$skill], [])['messages']);
    }

    /**
     * No skills, no block, nothing to warn about -- the warning must mark a
     * lost block, not every skill-less call.
     */
    #[Test]
    public function composeIntoMessagesIsSilentWhenThereIsNoBlockToPlace(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $subject  = new SkillInjectionService(new SkillComposer(), $logger);
        $messages = [['role' => 'system', 'content' => 'You are a translator.']];

        self::assertSame($messages, $subject->composeIntoMessages($messages, [], [])['messages']);
    }

    #[Test]
    public function composeIntoMessagesHandlesChatMessageValueObjects(): void
    {
        $subject  = $this->subject();
        $skill    = $this->makeSkill('alpha', 'Alpha Skill', 'Always cite sources.');
        $messages = [
            ChatMessage::system('You are a translator.'),
            ChatMessage::user('Translate this.'),
        ];

        $augmented = $subject->composeIntoMessages($messages, [$skill], [])['messages'];

        self::assertInstanceOf(ChatMessage::class, $augmented[0]);
        self::assertTrue($augmented[0]->isSystem());
        self::assertSame('You are a translator.', $augmented[0]->content);
        self::assertInstanceOf(ChatMessage::class, $augmented[1]);
        self::assertTrue($augmented[1]->isUser());
        self::assertStringContainsString('### Skill: Alpha Skill', $augmented[1]->content);
        self::assertStringEndsWith('Translate this.', $augmented[1]->content);
    }

    #[Test]
    public function approvedInstructionsAreAppendedToTheCallersSystemMessageNotTheUserTurn(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are the editor assistant.'],
            ['role' => 'user', 'content' => self::USER_INPUT],
        ];

        $injected = $this->instructingSubject()->composeIntoMessages($messages, [$this->approvedSkill()]);

        self::assertSame('', $injected['instructions'], 'placed, nothing left over');
        $system = $injected['messages'][0];
        self::assertIsArray($system);
        self::assertIsString($system['content']);
        self::assertStringStartsWith("You are the editor assistant.\n\n## Approved skills", $system['content']);
        self::assertStringContainsString('Follow the house style.', $system['content']);
        self::assertIsArray($injected['messages'][1]);
        self::assertSame(self::USER_INPUT, $injected['messages'][1]['content'], 'the user turn gets no fenced copy');
        $skill = $this->approvedSkill();
        self::assertEquals([new SkillPin(5, 1, $skill->getVersionDigest())], $injected['pins'], 'a placed instruction is pinned too');
    }

    #[Test]
    public function approvedInstructionsJoinASystemChatMessage(): void
    {
        $messages = [ChatMessage::system('You are the editor assistant.'), ChatMessage::user(self::USER_INPUT)];

        $injected = $this->instructingSubject()->composeIntoMessages($messages, [$this->approvedSkill()]);

        $system = $injected['messages'][0];
        self::assertInstanceOf(ChatMessage::class, $system);
        self::assertTrue($system->isSystem());
        self::assertStringContainsString('Follow the house style.', $system->content);
    }

    /**
     * Without a system message the sections are handed back: placing them in
     * a new system message here would suppress the configuration's own prompt.
     */
    #[Test]
    public function approvedInstructionsAreHandedBackWhenThereIsNoSystemMessage(): void
    {
        $messages = [['role' => 'user', 'content' => self::USER_INPUT]];

        $injected = $this->instructingSubject()->composeIntoMessages($messages, [$this->approvedSkill()]);

        self::assertSame($messages, $injected['messages']);
        self::assertStringContainsString('Follow the house style.', $injected['instructions']);
        self::assertEquals([new SkillPin(5, 1, $this->approvedSkill()->getVersionDigest())], $injected['pins']);
    }

    /**
     * A system message after the first user turn is outside the head the
     * context window keeps, so the instructions are handed back instead of
     * being placed where they could be evicted.
     */
    #[Test]
    public function aSystemMessageAfterTheFirstUserTurnDoesNotReceiveTheInstructions(): void
    {
        $messages = [
            ['role' => 'user', 'content' => self::USER_INPUT],
            ['role' => 'system', 'content' => 'Late system note.'],
        ];

        $injected = $this->instructingSubject()->composeIntoMessages($messages, [$this->approvedSkill()]);

        self::assertSame($messages, $injected['messages']);
        self::assertStringContainsString('Follow the house style.', $injected['instructions']);
    }

    #[Test]
    public function aFencedSkillIsNotPinned(): void
    {
        $messages = [['role' => 'user', 'content' => self::USER_INPUT]];

        self::assertSame([], $this->subject()->composeIntoMessages($messages, [$this->approvedSkill()])['pins']);
    }

    #[Test]
    public function aPromptNeverCarriesApprovedInstructions(): void
    {
        $injected = $this->instructingSubject()->composeIntoPrompt(self::USER_INPUT, [$this->approvedSkill()]);

        self::assertSame(self::USER_INPUT, $injected['prompt']);
        self::assertStringContainsString('Follow the house style.', $injected['instructions']);
        self::assertSame(['guide'], $injected['included']);
    }

    #[Test]
    public function appendingToAPartListAddsATextPart(): void
    {
        $messages = [
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Base.']]],
            ['role' => 'user', 'content' => 'Hi'],
        ];

        $placed = SkillInjectionService::appendToFirstSystemMessage($messages, 'Added.');

        self::assertNotNull($placed);
        self::assertIsArray($placed[0]);
        self::assertSame([['type' => 'text', 'text' => 'Base.'], ['type' => 'text', 'text' => 'Added.']], $placed[0]['content']);
        self::assertNull(SkillInjectionService::appendToFirstSystemMessage([['role' => 'user', 'content' => 'Hi']], 'Added.'));
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

    private function instructingSubject(): SkillInjectionService
    {
        $approvals = new InMemorySkillApprovalRepository();
        $skill     = $this->approvedSkill();
        $approvals->add(5, 1, $skill->getVersionDigest(), SkillVersionDigest::fieldsOf($skill), 'verified', 1);

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
        $skill = $this->makeSkill('guide', 'Guide', 'Follow the house style.');
        $skill->_setProperty('uid', 5);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
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
