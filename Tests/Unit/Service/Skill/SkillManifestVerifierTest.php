<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Service\Skill\SkillManifestVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillManifestVerifier::class)]
final class SkillManifestVerifierTest extends TestCase
{
    private SkillManifestVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new SkillManifestVerifier();
    }

    #[Test]
    public function isDeclaredOnlyForNonEmptyFingerprint(): void
    {
        self::assertFalse($this->verifier->isDeclared(''));
        self::assertFalse($this->verifier->isDeclared('   '));
        self::assertTrue($this->verifier->isDeclared('abc123'));
    }

    #[Test]
    public function fingerprintIsOrderIndependent(): void
    {
        $a = $this->verifier->computeFingerprint(['1:a' => 'x', '1:b' => 'y']);
        $b = $this->verifier->computeFingerprint(['1:b' => 'y', '1:a' => 'x']);

        self::assertSame($a, $b);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
    }

    #[Test]
    public function fingerprintChangesWhenAnyChecksumChanges(): void
    {
        $base    = $this->verifier->computeFingerprint(['1:a' => 'x', '1:b' => 'y']);
        $swapped = $this->verifier->computeFingerprint(['1:a' => 'x', '1:b' => 'z']);

        self::assertNotSame($base, $swapped);
    }

    #[Test]
    public function verifyPassesForTheMatchingFingerprint(): void
    {
        $manifest    = ['1:skill-a' => 'checksum-a', '1:skill-b' => 'checksum-b'];
        $fingerprint = $this->verifier->computeFingerprint($manifest);

        self::assertTrue($this->verifier->verify($fingerprint, $manifest));
        // Case-insensitive on the hex (declarations are hand-pasted).
        self::assertTrue($this->verifier->verify(strtoupper($fingerprint), $manifest));
        self::assertTrue($this->verifier->verify(' ' . $fingerprint . ' ', $manifest));
    }

    #[Test]
    public function verifyFailsClosedOnMismatchEmptyDeclarationAndEmptySet(): void
    {
        $manifest    = ['1:skill-a' => 'checksum-a'];
        $fingerprint = $this->verifier->computeFingerprint($manifest);

        // Tampered content => different digest => reject.
        self::assertFalse($this->verifier->verify($fingerprint, ['1:skill-a' => 'tampered']));
        // A wrong declared value.
        self::assertFalse($this->verifier->verify('deadbeef', $manifest));
        // No declaration is never a pass.
        self::assertFalse($this->verifier->verify('', $manifest));
        // An empty discovered set never verifies, even against a declaration.
        self::assertFalse($this->verifier->verify($fingerprint, []));
    }

    #[Test]
    public function singleFileManifestDegeneratesToOneEntry(): void
    {
        $manifest = ['7:SKILL.md' => 'abc'];

        self::assertTrue($this->verifier->verify($this->verifier->computeFingerprint($manifest), $manifest));
    }
}
