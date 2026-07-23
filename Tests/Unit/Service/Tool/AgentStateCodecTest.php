<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\Exception\AgentStateDecryptionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentStateCodec::class)]
final class AgentStateCodecTest extends TestCase
{
    private const KEY = 'a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1';

    private function codec(): AgentStateCodec
    {
        return new AgentStateCodec(self::KEY);
    }

    #[Test]
    public function encryptsAndDecryptsRoundTrip(): void
    {
        $plaintext = '{"messages":[{"role":"user","content":"secret prompt"}]}';

        $encoded = $this->codec()->encode($plaintext);

        self::assertStringStartsWith('v1:', $encoded);
        self::assertStringNotContainsString('secret prompt', $encoded);
        self::assertSame($plaintext, $this->codec()->decode($encoded));
    }

    #[Test]
    public function theSamePlaintextEncryptsToDifferentBytesEachTime(): void
    {
        // A fresh random nonce per encryption: identical state never produces
        // identical ciphertext (no equality oracle across rows).
        $codec = $this->codec();

        self::assertNotSame($codec->encode('same'), $codec->encode('same'));
    }

    #[Test]
    public function emptyStateStaysEmpty(): void
    {
        self::assertSame('', $this->codec()->encode(''));
        self::assertSame('', $this->codec()->decode(''));
    }

    #[Test]
    public function legacyCleartextIsReturnedVerbatim(): void
    {
        // A row written before encryption landed is plaintext JSON with no
        // version marker; it must still rehydrate after the upgrade.
        $legacy = '{"messages":[]}';

        self::assertSame($legacy, $this->codec()->decode($legacy));
    }

    #[Test]
    public function aTamperedCiphertextFailsAuthentication(): void
    {
        $encoded = $this->codec()->encode('{"x":1}');
        // Flip the last base64 character (part of the auth tag / ciphertext).
        $tampered = substr($encoded, 0, -1) . ($encoded[-1] === 'A' ? 'B' : 'A');

        $this->expectException(AgentStateDecryptionException::class);
        $this->codec()->decode($tampered);
    }

    #[Test]
    public function aValueWrittenUnderADifferentKeyDoesNotDecrypt(): void
    {
        $encoded = $this->codec()->encode('{"x":1}');

        $this->expectException(AgentStateDecryptionException::class);
        (new AgentStateCodec('ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff'))->decode($encoded);
    }

    #[Test]
    public function aMalformedVersionedPayloadIsRejected(): void
    {
        $this->expectException(AgentStateDecryptionException::class);
        $this->codec()->decode('v1:@@@not-base64@@@');
    }

    #[Test]
    public function encodingWithoutAKeyFailsClosed(): void
    {
        // No instance secret -> refuse to encrypt rather than store cleartext.
        $this->expectException(AgentStateDecryptionException::class);
        (new AgentStateCodec(''))->encode('{"x":1}');
    }
}
