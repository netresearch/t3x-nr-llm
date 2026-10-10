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
use Netresearch\NrLlm\Exception\SkillInstructionWithdrawnException;
use Netresearch\NrLlm\Service\Skill\SkillApprovalRepositoryInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillRecordLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(SkillComposerFactory::class)]
#[CoversClass(SkillInstructionPolicy::class)]
#[CoversClass(SkillPinCheck::class)]
#[CoversClass(SkillVersionDigest::class)]
final class SkillPolicyBoundaryTest extends TestCase
{
    #[Test]
    public function aHeldFirstPinDoesNotHideARevokedLaterPin(): void
    {
        $approvals = new InMemorySkillApprovalRepository();
        $fields = $this->fields();
        $digest = SkillVersionDigest::ofFields($fields);
        $held = new SkillPin(7, 3, $digest);
        $revoked = new SkillPin(8, 3, $digest);
        foreach ([7, 8] as $uid) {
            $approvals->add($uid, 3, $digest, $fields, 'verified', 1);
        }

        self::assertSame(1, $approvals->revoke(8, $digest, 1));
        $checker = new SkillPinCheck(
            $approvals,
            new FixedSkillSourceLookup([3 => SkillTrustLevel::VERIFIED]),
            new FixedSkillRecordLookup([7, 8]),
            new SkillComposerFactory($this->configuration()),
        );
        self::assertNull($checker->failure($held));
        $caught = null;
        try {
            $checker->assertHeld([$held, $revoked]);
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(
            SkillInstructionWithdrawnException::class,
            $caught,
        );
        self::assertSame($revoked, $caught->pin);
        self::assertSame('its approval was revoked', $caught->reason);
        self::assertSame(1791500101, $caught->getCode());
    }

    /**
     * @return iterable<string,array{bool,bool}>
     */
    public static function policyStores(): iterable
    {
        yield 'neither store' => [false, false];
        yield 'approval store only' => [true, false];
        yield 'source store only' => [false, true];
        yield 'both stores' => [true, true];
    }

    #[Test]
    #[DataProvider('policyStores')]
    public function onlyCompletePolicyWiringElevatesApprovedText(
        bool $hasApprovals,
        bool $hasSources,
    ): void {
        $skill = $this->approvedSkill();
        $approvals = new InMemorySkillApprovalRepository();
        $approvals->add(
            7,
            3,
            $skill->getVersionDigest(),
            $this->fields(),
            'verified',
            1,
        );
        $factory = new SkillComposerFactory(
            $this->configuration(),
            $hasApprovals ? $approvals : null,
            $hasSources ? new FixedSkillSourceLookup([3 => SkillTrustLevel::VERIFIED]) : null,
            new FixedSkillRecordLookup([7]),
        );
        $policy = null;
        $composer = null;
        $caught = null;
        try {
            $policy = $factory->instructionPolicy();
            $composer = $factory->create();
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertNull(
            $caught,
            'Incomplete optional stores must preserve lean construction.',
        );
        self::assertInstanceOf(SkillComposer::class, $composer);
        if ($hasApprovals && $hasSources) {
            self::assertInstanceOf(SkillInstructionPolicy::class, $policy);
        } else {
            self::assertNull($policy);
        }

        $result = $composer->composeBlock([$skill], []);
        self::assertSame(['approved'], $result->included);
        if ($hasApprovals && $hasSources) {
            self::assertSame('', $result->block);
            self::assertStringContainsString(
                'Always greet politely.',
                $result->instructions,
            );
            self::assertSame(['approved'], $result->instructionIncluded);
        } else {
            self::assertStringContainsString(
                'Always greet politely.',
                $result->block,
            );
            self::assertSame('', $result->instructions);
            self::assertSame([], $result->instructionIncluded);
        }
    }

    /**
     * @return iterable<string,array{int,string}>
     */
    public static function invalidInstructionIdentities(): iterable
    {
        yield 'zero uid' => [0, '1:' . str_repeat('a', 64)];
        yield 'negative uid' => [-1, '1:' . str_repeat('a', 64)];
        yield 'malformed digest' => [7, 'not-a-version-digest'];
    }

    #[Test]
    #[DataProvider('invalidInstructionIdentities')]
    public function invalidIdentitiesCannotInstructEvenWithPermissiveStores(
        int $uid,
        string $digest,
    ): void {
        $skill = $this->approvedSkill();
        $skill->_setProperty('uid', $uid);

        $approvals = self::createStub(SkillApprovalRepositoryInterface::class);
        $approvals->method('hasUnrevokedApproval')->willReturn(true);
        $policy = new SkillInstructionPolicy(
            $approvals,
            new FixedSkillSourceLookup([3 => SkillTrustLevel::VERIFIED]),
            SkillTrustLevel::VERIFIED,
            new FixedSkillRecordLookup([$uid]),
        );
        self::assertFalse($policy->isInstruction($skill, $digest));
    }

    /**
     * Persistent format-1 reference values: independently hashed literal
     * canonical preimages, rather than values derived through the subject.
     *
     * @return iterable<string,array{array{name:string,description:string,body:string,support_status:string,allowed_tools:list<string>|null,process:bool},string}>
     */
    public static function formatOneVectors(): iterable
    {
        yield 'ASCII without tool declaration' => [
            [
                'name' => 'Alpha',
                'description' => 'Does alpha things.',
                'body' => 'Always greet politely.',
                'support_status' => 'full',
                'allowed_tools' => null,
                'process' => false,
            ],
            '1:6a1252213eff1d76c1a619712d657af7845df391030bb3e87e3b50ad690c4562',
        ];
        yield 'ASCII explicitly empty tools' => [
            [
                'name' => 'Alpha',
                'description' => 'Does alpha things.',
                'body' => 'Always greet politely.',
                'support_status' => 'full',
                'allowed_tools' => [],
                'process' => false,
            ],
            '1:d8178cd2588440159ff5be69d8a7f20a2ddd1229380371da31f331d08aac53a7',
        ];
        yield 'binary multilingual normalized tools' => [
            [
                'name' => 'Größe',
                'description' => "nul\x00boundary\n",
                'body' => "Grüße\r\n日本語",
                'support_status' => 'full',
                'allowed_tools' => ['Zed', 'Alpha', 'Alpha'],
                'process' => true,
            ],
            '1:e83347d5d280c1dde4d88e3d8cfb27cb7235cfd56dd073f223530b2bc8dc7d8a',
        ];
    }

    /**
     * @param array{name:string,description:string,body:string,support_status:string,allowed_tools:list<string>|null,process:bool} $fields
     */
    #[Test]
    #[DataProvider('formatOneVectors')]
    public function persistedFormatOneDigestsRemainCompatible(
        array $fields,
        string $expected,
    ): void {
        self::assertSame($expected, SkillVersionDigest::ofFields($fields));
    }

    private function configuration(): ExtensionConfiguration
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['skills' => []]);
        return $configuration;
    }

    /**
     * @return array{name:string,description:string,body:string,support_status:string,allowed_tools:list<string>|null,process:bool}
     */
    private function fields(): array
    {
        return [
            'name' => 'Alpha',
            'description' => 'Does alpha things.',
            'body' => 'Always greet politely.',
            'support_status' => 'full',
            'allowed_tools' => null,
            'process' => false,
        ];
    }

    private function approvedSkill(): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', 7);
        $skill->setSource(3);
        $skill->setIdentifier('approved');
        $skill->setName('Alpha');
        $skill->setDescription('Does alpha things.');
        $skill->setBody('Always greet politely.');
        $skill->setBodyChecksum(hash('sha256', $skill->getBody()));
        $skill->setSupportStatus('full');
        $skill->setEnabled(true);
        $skill->setVersionDigest(SkillVersionDigest::ofFields($this->fields()));
        return $skill;
    }
}
