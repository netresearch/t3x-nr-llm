<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillRecordLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral boundaries that previously survived native mutations.
 */
#[CoversClass(SkillComposer::class)]
final class SkillCompositionBoundaryTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function invalidKinds(): array
    {
        return [
            'checksum' => ['checksum'],
            'version digest' => ['digest'],
            'process' => ['process'],
        ];
    }

    #[Test]
    #[DataProvider('invalidKinds')]
    public function invalidFirstSkillDoesNotDiscardAValidTail(
        string $kind,
    ): void {
        $bad = $this->skill('invalid', 'Invalid', 'original');
        if ($kind === 'checksum') {
            $bad->setBody('edited after checksum');
        } elseif ($kind === 'digest') {
            $bad->setVersionDigest(SkillVersionDigest::of($bad));
            $bad->setDescription('edited after version digest');
        } else {
            $bad->setProcess(true);
        }

        $tail = $this->skill('tail', 'Tail', 'valid tail content');
        $result = (new SkillComposer())->composeBlock([$bad, $tail], []);
        self::assertSame(['tail'], $result->included);
        self::assertSame(['invalid'], $result->dropped);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('valid tail content', $result->block);
        self::assertStringNotContainsString('original', $result->block);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function skippedKinds(): array
    {
        return [
            'disabled' => ['disabled'],
            'orphaned' => ['orphaned'],
            'below trust floor' => ['trust'],
            'duplicate' => ['duplicate'],
        ];
    }

    #[Test]
    #[DataProvider('skippedKinds')]
    public function candidateSelectionContinuesAfterASkippedEntry(
        string $kind,
    ): void {
        $first = $this->skill('first', 'First', 'first content');
        $first->setTrustLevel(SkillTrustLevel::VERIFIED->value);

        $tail = $this->skill('tail', 'Tail', 'valid tail content');
        $tail->setTrustLevel(SkillTrustLevel::VERIFIED->value);

        $configuration = [$first];
        $expected = [$tail];
        if ($kind === 'disabled') {
            $first->setEnabled(false);
        } elseif ($kind === 'orphaned') {
            $first->setOrphaned(true);
        } elseif ($kind === 'trust') {
            $first->setTrustLevel(SkillTrustLevel::UNTRUSTED->value);
        } else {
            $configuration[] = $first;
            $expected = [$first, $tail];
        }

        $composer = new SkillComposer(minTrustLevel: SkillTrustLevel::VERIFIED);
        self::assertSame(
            $expected,
            $composer->effectiveSkills($configuration, [$tail]),
        );
        $result = $composer->composeBlock($configuration, [$tail]);
        self::assertContains('tail', $result->included);
        self::assertStringContainsString('valid tail content', $result->block);
    }

    #[Test]
    public function sourceAndIdentifierRemainDistinctEvenWhenConcatenationsMatch(): void
    {
        $first = $this->skill('23', 'First', 'first source content', source: 1);
        $second = $this->skill('3', 'Second', 'second source content', source: 12);
        $composer = new SkillComposer();
        self::assertSame(
            [$first, $second],
            $composer->effectiveSkills([$first], [$second]),
        );
        $result = $composer->composeBlock([$first], [$second]);
        self::assertSame(['23', '3'], $result->included);
        self::assertSame([], $result->dropped);
        self::assertStringContainsString('first source content', $result->block);
        self::assertStringContainsString(
            'second source content',
            $result->block,
        );
    }

    #[Test]
    public function aBlockExactlyAtItsByteBudgetIsRetained(): void
    {
        $skill = $this->skill(
            'multibyte',
            'Größe',
            'Mehrsprachig: Grüße — 日本語.',
        );
        $wide = (new SkillComposer())->composeBlock([$skill], []);
        self::assertSame(['multibyte'], $wide->included);
        $bytes = strlen($wide->block);
        $exact = (new SkillComposer(maxBytes: $bytes))->composeBlock([$skill], []);
        self::assertSame($wide->block, $exact->block);
        self::assertSame(['multibyte'], $exact->included);
        self::assertSame([], $exact->warnings);
        $oneByteTooSmall = (new SkillComposer(maxBytes: $bytes - 1))->composeBlock([$skill], []);
        self::assertSame('', $oneByteTooSmall->block);
        self::assertSame([], $oneByteTooSmall->included);
        self::assertSame(['multibyte'], $oneByteTooSmall->dropped);
    }

    #[Test]
    #[DataProvider('dropBudgets')]
    public function repeatedTaskDropsPreserveTheBaselineWithinTheExactBudget(bool $belowTwoBlocks): void
    {
        $baseline = $this->skill('baseline', 'Baseline', 'configuration content');
        $tasks = [
            $this->skill('task-one', 'Task one', 'first task content'),
            $this->skill('task-two', 'Task two', 'second task content'),
        ];
        $baselineBlock = (new SkillComposer())->composeBlock([$baseline], [])->block;
        $budget = $belowTwoBlocks ? strlen(
            (new SkillComposer())->composeBlock([$baseline], [$tasks[0]])->block,
        ) - 1 : strlen($baselineBlock);
        $result = (new SkillComposer(maxBytes: $budget))->composeBlock(
            [$baseline],
            $tasks,
        );
        self::assertLessThanOrEqual($budget, strlen($result->block));
        self::assertSame($baselineBlock, $result->block);
        self::assertSame(['baseline'], $result->included);
        self::assertSame(['task-one', 'task-two'], $result->dropped);
        self::assertCount(2, $result->warnings);
    }

    #[Test]
    public function mixedInstructionsAndDataPreserveChannelsAndExactVersionPins(): void
    {
        $first = $this->skill(
            'approved-one',
            'Approved one',
            'first approved instruction',
            uid: 41,
        );
        $second = $this->skill(
            'approved-two',
            'Approved two',
            'second approved instruction',
            uid: 42,
        );
        $data = $this->skill('reference', 'Reference', 'unapproved reference data');
        $approvals = new InMemorySkillApprovalRepository();
        foreach ([$first, $second] as $skill) {
            $skill->setVersionDigest(SkillVersionDigest::of($skill));
            $approvals->add(
                (int)$skill->getUid(),
                $skill->getSource(),
                $skill->getVersionDigest(),
                SkillVersionDigest::fieldsOf($skill),
                'verified',
                1,
            );
        }

        $policy = new SkillInstructionPolicy(
            $approvals,
            new FixedSkillSourceLookup([7 => SkillTrustLevel::VERIFIED]),
            SkillTrustLevel::VERIFIED,
            new FixedSkillRecordLookup([41, 42]),
        );
        $result = (new SkillComposer(instructionPolicy: $policy))->composeBlock(
            [$first, $data, $second],
            [],
        );
        self::assertSame(
            ['approved-one', 'reference', 'approved-two'],
            $result->included,
        );
        self::assertSame([], $result->dropped);
        self::assertSame(
            ['approved-one', 'approved-two'],
            $result->instructionIncluded,
        );
        self::assertSame(
            [
                [
                    'skill' => 41,
                    'source' => 7,
                    'digest' => $first->getVersionDigest(),
                ],
                [
                    'skill' => 42,
                    'source' => 7,
                    'digest' => $second->getVersionDigest(),
                ],
            ],
            $this->pinArrays($result->instructionPins),
        );
        self::assertStringContainsString(
            'first approved instruction',
            $result->instructions,
        );
        self::assertStringContainsString(
            'second approved instruction',
            $result->instructions,
        );
        self::assertStringNotContainsString(
            'unapproved reference data',
            $result->instructions,
        );
        self::assertStringContainsString(
            'unapproved reference data',
            $result->block,
        );
        self::assertStringNotContainsString(
            'first approved instruction',
            $result->block,
        );
        self::assertStringNotContainsString(
            'second approved instruction',
            $result->block,
        );
        self::assertStringContainsString('UNTRUSTED', $result->block);
        self::assertSame([], $result->warnings);
    }

    private function skill(
        string $identifier,
        string $name,
        string $body,
        int $source = 7,
        int $uid = 0,
    ): Skill {
        $skill = new Skill();
        $skill->_setProperty('uid', $uid);
        $skill->setSource($source);
        $skill->setIdentifier($identifier);
        $skill->setName($name);
        $skill->setBody($body);
        $skill->setBodyChecksum(hash('sha256', $body));
        $skill->setSupportStatus('full');
        $skill->setEnabled(true);
        return $skill;
    }

    /** @param list<mixed> $pins
     * @return list<array{skill:int,source:int,digest:string}>
     */
    private function pinArrays(array $pins): array
    {
        self::assertCount(2, $pins);
        $typed = [];
        foreach ($pins as $pin) {
            self::assertInstanceOf(SkillPin::class, $pin);
            $typed[] = $pin;
        }

        return SkillPin::listToArray($typed);
    }

    /**
     * @return array<string,array{bool}>
     */
    public static function dropBudgets(): array
    {
        return ['exactly baseline' => [false], 'one byte below two blocks' => [true]];
    }
}
