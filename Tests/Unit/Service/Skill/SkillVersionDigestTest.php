<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Closure;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillVersionDigest::class)]
final class SkillVersionDigestTest extends TestCase
{
    #[Test]
    public function digestCarriesItsFormatVersionInFrontOfTheHexValue(): void
    {
        $digest = SkillVersionDigest::of($this->skill());

        self::assertMatchesRegularExpression('/^1:[0-9a-f]{64}$/', $digest);
        self::assertTrue(SkillVersionDigest::isWellFormed($digest));
        self::assertFalse(SkillVersionDigest::isWellFormed(hash('sha256', 'x')), 'a bare body checksum is not a version digest');
        self::assertFalse(SkillVersionDigest::isWellFormed(''));
    }

    /**
     * @return iterable<string, array{Closure(Skill): void}>
     */
    public static function fieldsTheModelReads(): iterable
    {
        yield 'name' => [static fn(Skill $s) => $s->setName('Other name')];
        yield 'description' => [static fn(Skill $s) => $s->setDescription('Other description')];
        yield 'body' => [static fn(Skill $s) => $s->setBody('Other body.')];
        yield 'support status' => [static fn(Skill $s) => $s->setSupportStatus('partial')];
        yield 'a tool added' => [static fn(Skill $s) => $s->setAllowedTools('["GetTca","GetEnv","ListPages"]')];
        yield 'a tool removed' => [static fn(Skill $s) => $s->setAllowedTools('["GetTca"]')];
        yield 'declaration dropped' => [static fn(Skill $s) => $s->setAllowedTools('')];
        yield 'declared empty' => [static fn(Skill $s) => $s->setAllowedTools('[]')];
        yield 'process marker' => [static fn(Skill $s) => $s->setProcess(true)];
    }

    /**
     * @param Closure(Skill): void $change
     */
    #[Test]
    #[DataProvider('fieldsTheModelReads')]
    public function everyFieldTheModelReadsIsPartOfTheVersion(Closure $change): void
    {
        $skill  = $this->skill();
        $before = SkillVersionDigest::of($skill);

        $change($skill);

        self::assertNotSame($before, SkillVersionDigest::of($skill));
    }

    #[Test]
    public function fieldsNothingReadsAreNotPartOfTheVersion(): void
    {
        $skill  = $this->skill();
        $before = SkillVersionDigest::of($skill);

        $skill->setRawFrontmatter('{"name":"x","unread":"key"}');
        $skill->setSourceSha(str_repeat('a', 40));
        $skill->setBodyChecksum('anything');
        $skill->setTrustLevel('first_party');
        $skill->setEnabled(false);

        self::assertSame($before, SkillVersionDigest::of($skill));
    }

    #[Test]
    public function toolOrderDuplicatesAndNonStringEntriesAreNotANewVersion(): void
    {
        $skill  = $this->skill();
        $before = SkillVersionDigest::of($skill);

        $skill->setAllowedTools('["GetEnv","GetTca","GetEnv",5,null]');

        self::assertSame($before, SkillVersionDigest::of($skill));
    }

    #[Test]
    public function noDeclarationAndADeclaredEmptyListAreDifferentVersions(): void
    {
        $none  = $this->skill();
        $empty = $this->skill();
        $none->setAllowedTools('');
        $empty->setAllowedTools('[]');

        self::assertNotSame(SkillVersionDigest::of($none), SkillVersionDigest::of($empty));
    }

    /**
     * The input is length-prefixed, so moving bytes from one field into the
     * next cannot produce the same serialisation.
     */
    #[Test]
    public function bytesMovedBetweenFieldsAreADifferentVersion(): void
    {
        $first  = $this->skill();
        $second = $this->skill();
        $first->setName('Alpha');
        $first->setDescription("Beta\ndescription=4:Gamma");

        $second->setName("Alpha\ndescription=4:Beta");
        $second->setDescription('Gamma');

        self::assertNotSame(SkillVersionDigest::of($first), SkillVersionDigest::of($second));
    }

    #[Test]
    public function aSnapshotOfTheFieldsHashesToTheRecordDigest(): void
    {
        $skill = $this->skill();

        self::assertSame(SkillVersionDigest::of($skill), SkillVersionDigest::ofFields(SkillVersionDigest::fieldsOf($skill)));
    }

    #[Test]
    public function verifiedReturnsTheStoredDigestWhenTheFieldsStillMatch(): void
    {
        $skill = $this->skill();
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        self::assertSame($skill->getVersionDigest(), SkillVersionDigest::verified($skill));
    }

    /**
     * @param Closure(Skill): void $change
     */
    #[Test]
    #[DataProvider('fieldsTheModelReads')]
    public function verifiedFailsWhenAStoredFieldWasEditedAfterTheSync(Closure $change): void
    {
        $skill = $this->skill();
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        $change($skill);

        self::assertNull(SkillVersionDigest::verified($skill));
    }

    #[Test]
    public function aLegacyRowVerifiesAgainstItsBodyChecksumAndHasNoDigest(): void
    {
        $skill = $this->skill();
        $skill->setVersionDigest('');

        self::assertSame('', SkillVersionDigest::verified($skill));

        $skill->setBody('Tampered body.');

        self::assertNull(SkillVersionDigest::verified($skill));
    }

    private function skill(): Skill
    {
        $skill = new Skill();
        $skill->setSource(1);
        $skill->setIdentifier('1:skills/a/SKILL.md');
        $skill->setName('Alpha');
        $skill->setDescription('Does alpha things.');
        $skill->setBody('Always greet politely.');
        $skill->setBodyChecksum(hash('sha256', 'Always greet politely.'));
        $skill->setSupportStatus('full');
        $skill->setAllowedTools('["GetTca","GetEnv"]');
        $skill->setProcess(false);

        return $skill;
    }
}
