<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AgentStateEnvelopeRotator;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Crypto\EncryptionService;
use Netresearch\NrVault\Crypto\EncryptionServiceInterface;
use Netresearch\NrVault\Crypto\EnvelopeCodec;
use Netresearch\NrVault\Crypto\EnvelopeCodecInterface;
use Netresearch\NrVault\Crypto\EnvelopeRotationContext;
use Netresearch\NrVault\Crypto\MasterKeyProviderInterface;
use Netresearch\NrVault\Exception\EncryptionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The end-to-end proof that encrypting the agent-state columns is not a delayed
 * data-loss bug: after a master-key rotation, the rows must still open.
 *
 * Before this rotator existed, `vault:rotate-master-key` walked
 * `tx_nrvault_secret` only, so these envelopes stayed wrapped under a key the
 * operator was told to destroy — and nothing reported it.
 */
#[CoversClass(AgentStateEnvelopeRotator::class)]
final class AgentStateEnvelopeRotatorTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_agentrun';

    private AgentStateEnvelopeRotator $rotator;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $rotator = $this->get(AgentStateEnvelopeRotator::class);
        self::assertInstanceOf(AgentStateEnvelopeRotator::class, $rotator);
        $this->rotator = $rotator;

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;
    }

    #[Test]
    public function itDeclaresTheTableItWrites(): void
    {
        self::assertSame([self::TABLE], $this->rotator->getTables());
        self::assertStringContainsString('nr-llm', $this->rotator->getIdentifier());
    }

    #[Test]
    public function countsOnlySealedColumns(): void
    {
        self::assertSame(0, $this->rotator->countEnvelopes());

        $codec = $this->get(EnvelopeCodecInterface::class);
        self::assertInstanceOf(EnvelopeCodecInterface::class, $codec);

        // One row with both columns sealed => two envelopes.
        $this->insertRun(
            $codec->seal('{"request":1}', AgentStateCodec::PURPOSE_QUEUED_REQUEST),
            $codec->seal('{"state":1}', AgentStateCodec::PURPOSE_SUSPENDED_STATE),
        );
        // One row of pre-encryption cleartext => not an envelope.
        $this->insertRun('{"plain":"json"}', '');

        self::assertSame(2, $this->rotator->countEnvelopes());
    }

    /**
     * Rows written by nr-llm 0.24.0-0.25.x carry the legacy `v2:` marker and are
     * sealed under the same master key. Missing them would be exactly the silent
     * loss this class prevents, so they must be counted and re-wrapped too.
     */
    #[Test]
    public function legacyMarkedRowsAreCountedAndRewrapped(): void
    {
        $plaintext = '{"messages":[{"role":"user","content":"from 0.24.0"}]}';
        $legacy = $this->legacyEnvelope($plaintext, AgentStateCodec::PURPOSE_QUEUED_REQUEST);

        $uid = $this->insertRun($legacy, '');

        self::assertSame(1, $this->rotator->countEnvelopes());

        $newKey = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
        $rewrapped = $this->rotator->rewrapAll($this->contextTo($newKey));

        self::assertSame(1, $rewrapped);

        // The stored value changed, still opens under the NEW key, and no longer
        // opens under the old one.
        $stored = $this->storedValue($uid, 'queued_request');
        self::assertNotSame($legacy, $stored);
        self::assertSame($plaintext, $this->codecFor($newKey)->open($stored, AgentStateCodec::PURPOSE_QUEUED_REQUEST));
    }

    #[Test]
    public function rewrappingMovesBothColumnsToTheNewKey(): void
    {
        $codec = $this->get(EnvelopeCodecInterface::class);
        self::assertInstanceOf(EnvelopeCodecInterface::class, $codec);

        $request = '{"request":"payload"}';
        $state = '{"state":"payload"}';
        $uid = $this->insertRun(
            $codec->seal($request, AgentStateCodec::PURPOSE_QUEUED_REQUEST),
            $codec->seal($state, AgentStateCodec::PURPOSE_SUSPENDED_STATE),
        );

        $newKey = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);

        self::assertSame(2, $this->rotator->rewrapAll($this->contextTo($newKey)));

        $newCodec = $this->codecFor($newKey);
        self::assertSame(
            $request,
            $newCodec->open($this->storedValue($uid, 'queued_request'), AgentStateCodec::PURPOSE_QUEUED_REQUEST),
        );
        self::assertSame(
            $state,
            $newCodec->open($this->storedValue($uid, 'suspended_state'), AgentStateCodec::PURPOSE_SUSPENDED_STATE),
        );
    }

    /**
     * The regression this whole seam exists for: without re-wrapping, a rotated
     * installation cannot read its own queued runs.
     */
    #[Test]
    public function withoutRewrappingTheRowWouldNotOpenUnderTheNewKey(): void
    {
        $codec = $this->get(EnvelopeCodecInterface::class);
        self::assertInstanceOf(EnvelopeCodecInterface::class, $codec);

        $sealed = $codec->seal('{"request":"payload"}', AgentStateCodec::PURPOSE_QUEUED_REQUEST);
        $newKey = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);

        $this->expectException(EncryptionException::class);
        $this->codecFor($newKey)->open($sealed, AgentStateCodec::PURPOSE_QUEUED_REQUEST);
    }

    #[Test]
    public function cleartextRowsAreLeftUntouched(): void
    {
        $plain = '{"plain":"json"}';
        $uid = $this->insertRun($plain, '');

        self::assertSame(0, $this->rotator->rewrapAll(
            $this->contextTo(random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES)),
        ));
        self::assertSame($plain, $this->storedValue($uid, 'queued_request'));
    }

    #[Test]
    public function rewrappingIsSafeAcrossMoreRowsThanOneBatch(): void
    {
        $codec = $this->get(EnvelopeCodecInterface::class);
        self::assertInstanceOf(EnvelopeCodecInterface::class, $codec);

        // BATCH_SIZE is 200; go past it to prove the uid cursor advances rather
        // than re-reading the first page forever.
        $expected = 205;
        for ($i = 0; $i < $expected; ++$i) {
            $this->insertRun($codec->seal('{"i":' . $i . '}', AgentStateCodec::PURPOSE_QUEUED_REQUEST), '');
        }

        self::assertSame($expected, $this->rotator->countEnvelopes());
        self::assertSame($expected, $this->rotator->rewrapAll(
            $this->contextTo(random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES)),
        ));
    }

    /**
     * Build a value in the format nr-llm 0.24.0-0.25.x wrote, straight from the
     * encryption service, so the test proves format compatibility rather than
     * restating the codec's marker shim.
     */
    private function legacyEnvelope(string $plaintext, string $identifier): string
    {
        $encryptionService = $this->get(EncryptionServiceInterface::class);
        self::assertInstanceOf(EncryptionServiceInterface::class, $encryptionService);

        $encrypted = $encryptionService->encrypt($plaintext, $identifier);

        return 'v2:' . base64_encode(json_encode($encrypted->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * A rotation context moving envelopes from the instance's current master key
     * to $newKey.
     */
    private function contextTo(string $newKey): EnvelopeRotationContext
    {
        $codec = $this->get(EnvelopeCodecInterface::class);
        self::assertInstanceOf(EnvelopeCodecInterface::class, $codec);

        return new EnvelopeRotationContext($codec, $this->currentMasterKey(), $newKey);
    }

    /**
     * A codec bound to an arbitrary master key, for asserting which key an
     * envelope opens under.
     */
    private function codecFor(string $masterKey): EnvelopeCodec
    {
        // A mock rather than an anonymous class: MasterKeyProviderInterface also
        // declares key generation, storage and cache clearing, none of which this
        // test needs, and a hand-rolled stub would silently rot when the interface
        // grows another method.
        $provider = $this->createMock(MasterKeyProviderInterface::class);
        $provider->method('getMasterKey')->willReturn($masterKey);
        $provider->method('isAvailable')->willReturn(true);

        $encryptionService = GeneralUtility::makeInstance(
            EncryptionService::class,
            $provider,
            $this->get(ExtensionConfigurationInterface::class),
        );

        return new EnvelopeCodec($encryptionService);
    }

    private function currentMasterKey(): string
    {
        $provider = $this->get(MasterKeyProviderInterface::class);
        self::assertInstanceOf(MasterKeyProviderInterface::class, $provider);

        return $provider->getMasterKey();
    }

    private function insertRun(string $queuedRequest, string $suspendedState): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'pid' => 0,
            'uuid' => bin2hex(random_bytes(8)),
            'queued_request' => $queuedRequest,
            'suspended_state' => $suspendedState,
        ]);

        return (int)$connection->lastInsertId();
    }

    private function storedValue(int $uid, string $column): string
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $value = $queryBuilder
            ->select($column)
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($value);

        return $value;
    }
}
