<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\Exception\AgentStateDecryptionException;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Crypto\EncryptionServiceInterface;
use Netresearch\NrVault\Crypto\EnvelopeCodecInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Exercises the codec against nr-vault's REAL {@see \Netresearch\NrVault\Crypto\EncryptionService}
 * (loaded in the functional test extension set), so the envelope round-trip,
 * the AAD binding and the failure modes are validated end to end rather than
 * against a stub.
 */
#[CoversClass(AgentStateCodec::class)]
final class AgentStateCodecTest extends AbstractFunctionalTestCase
{
    private AgentStateCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();
        $codec = $this->get(AgentStateCodec::class);
        self::assertInstanceOf(AgentStateCodec::class, $codec);
        $this->codec = $codec;
    }

    #[Test]
    public function encryptsAndDecryptsRoundTrip(): void
    {
        $plaintext = '{"messages":[{"role":"user","content":"a private prompt"}]}';

        $encoded = $this->codec->encode($plaintext, AgentStateCodec::PURPOSE_QUEUED_REQUEST);

        self::assertStringStartsWith(EnvelopeCodecInterface::MARKER, $encoded);
        self::assertStringNotContainsString('private prompt', $encoded);
        self::assertSame($plaintext, $this->codec->decode($encoded, AgentStateCodec::PURPOSE_QUEUED_REQUEST));
    }

    #[Test]
    public function theSamePlaintextEncryptsToDifferentBytesEachTime(): void
    {
        // A fresh per-value data key/nonce: identical state never produces
        // identical ciphertext (no equality oracle across rows).
        self::assertNotSame(
            $this->codec->encode('same', AgentStateCodec::PURPOSE_QUEUED_REQUEST),
            $this->codec->encode('same', AgentStateCodec::PURPOSE_QUEUED_REQUEST),
        );
    }

    #[Test]
    public function emptyStateStaysEmpty(): void
    {
        self::assertSame('', $this->codec->encode('', AgentStateCodec::PURPOSE_QUEUED_REQUEST));
        self::assertSame('', $this->codec->decode('', AgentStateCodec::PURPOSE_QUEUED_REQUEST));
    }

    #[Test]
    public function legacyCleartextIsReturnedVerbatim(): void
    {
        // A row written before encryption landed is plaintext JSON with no
        // version marker; it must still rehydrate after the upgrade.
        $legacy = '{"messages":[]}';

        self::assertSame($legacy, $this->codec->decode($legacy, AgentStateCodec::PURPOSE_QUEUED_REQUEST));
    }

    #[Test]
    public function aCiphertextMovedToTheWrongColumnFailsAuthentication(): void
    {
        // The per-column identifier is AAD: a queued-request envelope decrypted
        // as a suspended-state payload fails authentication rather than leaking.
        $encoded = $this->codec->encode('{"x":1}', AgentStateCodec::PURPOSE_QUEUED_REQUEST);

        $this->expectException(AgentStateDecryptionException::class);
        $this->codec->decode($encoded, AgentStateCodec::PURPOSE_SUSPENDED_STATE);
    }

    #[Test]
    public function aTamperedEnvelopeFailsAuthentication(): void
    {
        $encoded  = $this->codec->encode('{"x":1}', AgentStateCodec::PURPOSE_QUEUED_REQUEST);
        $tampered = substr($encoded, 0, -2) . ($encoded[-2] === 'A' ? 'B' : 'A') . $encoded[-1];

        $this->expectException(AgentStateDecryptionException::class);
        $this->codec->decode($tampered, AgentStateCodec::PURPOSE_QUEUED_REQUEST);
    }

    #[Test]
    public function aMalformedEnvelopeIsRejected(): void
    {
        $this->expectException(AgentStateDecryptionException::class);
        $this->codec->decode(
            EnvelopeCodecInterface::MARKER . '@@@not-base64@@@',
            AgentStateCodec::PURPOSE_QUEUED_REQUEST,
        );
    }

    /**
     * The load-bearing upgrade test: a row written by nr-llm 0.24.0-0.25.x carries
     * the old ``v2:`` marker. Those rows exist in production, so moving the
     * envelope to nr-vault's codec must not need a data migration.
     *
     * The legacy value is built the way the OLD codec built it — straight from
     * EncryptionService, base64 of the JSON EncryptedData array, with a ``v2:``
     * prefix — rather than by rewriting a new envelope's marker. That is what makes
     * this a proof of format compatibility instead of a restatement of the shim.
     */
    #[Test]
    public function aRowWrittenByTheLegacyCodecStillDecodes(): void
    {
        $plaintext = '{"messages":[{"role":"user","content":"written by 0.24.0"}]}';

        $encryptionService = $this->get(EncryptionServiceInterface::class);
        self::assertInstanceOf(EncryptionServiceInterface::class, $encryptionService);

        $encrypted = $encryptionService->encrypt($plaintext, AgentStateCodec::PURPOSE_QUEUED_REQUEST);
        $legacyValue = 'v2:' . base64_encode(json_encode($encrypted->toArray(), JSON_THROW_ON_ERROR));

        self::assertSame(
            $plaintext,
            $this->codec->decode($legacyValue, AgentStateCodec::PURPOSE_QUEUED_REQUEST),
        );
    }

    /**
     * The legacy marker is still bound to its column: re-marking must not weaken
     * the AAD check.
     */
    #[Test]
    public function aLegacyRowIsStillBoundToItsColumn(): void
    {
        $encryptionService = $this->get(EncryptionServiceInterface::class);
        self::assertInstanceOf(EncryptionServiceInterface::class, $encryptionService);

        $encrypted = $encryptionService->encrypt('{"x":1}', AgentStateCodec::PURPOSE_QUEUED_REQUEST);
        $legacyValue = 'v2:' . base64_encode(json_encode($encrypted->toArray(), JSON_THROW_ON_ERROR));

        $this->expectException(AgentStateDecryptionException::class);
        $this->codec->decode($legacyValue, AgentStateCodec::PURPOSE_SUSPENDED_STATE);
    }

    /**
     * A value carrying neither marker is pre-encryption cleartext even if it
     * happens to start with something marker-ish.
     */
    #[Test]
    public function anUnmarkedValueIsNeverTreatedAsAnEnvelope(): void
    {
        foreach (['{"v2":"not a marker"}', 'v3:something-else', 'nrv2:wrong-version'] as $value) {
            self::assertSame(
                $value,
                $this->codec->decode($value, AgentStateCodec::PURPOSE_QUEUED_REQUEST),
            );
        }
    }
}
