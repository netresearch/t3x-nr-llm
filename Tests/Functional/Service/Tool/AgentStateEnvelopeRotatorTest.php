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
    public function itDeclaresTheTablesItWrites(): void
    {
        // The A2A task view and event journal (ADR-282), the push configs
        // and the push inbox (ADR-285), and the A2A file store (ADR-284) are
        // sealed with the same codec, so a rotation must reach them too.
        self::assertSame([self::TABLE, 'tx_nrllm_a2a_task', 'tx_nrllm_a2a_event', 'tx_nrllm_a2a_push_config', 'tx_nrllm_a2a_push_inbox', 'tx_nrllm_a2a_file'], $this->rotator->getTables());
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
     * The answer of a background run is sealed like the other two columns
     * (ADR-234), so a key rotation has to reach it, or it can never be read
     * again once the old key is gone.
     */
    #[Test]
    public function aKeptAnswerIsCounted(): void
    {
        $codec = $this->get(EnvelopeCodecInterface::class);
        self::assertInstanceOf(EnvelopeCodecInterface::class, $codec);

        $uid = $this->insertRun('', '');
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['answer' => $codec->seal('the answer', AgentStateCodec::PURPOSE_RUN_ANSWER)],
            ['uid' => $uid],
        );

        self::assertSame(1, $this->rotator->countEnvelopes());
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
        $legacy    = $this->legacyEnvelope($plaintext, AgentStateCodec::PURPOSE_QUEUED_REQUEST);

        $uid = $this->insertRun($legacy, '');

        self::assertSame(1, $this->rotator->countEnvelopes());

        $newKey    = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
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
        $state   = '{"state":"payload"}';
        $uid     = $this->insertRun(
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
     * The token and the Bearer credential of a push config are sealed
     * (spec 051 A2A-PUSH2); after a rotation the worker must still open them,
     * or every delivery of the config stops as CREDENTIAL_UNREADABLE.
     */
    #[Test]
    public function rewrappingMovesThePushConfigColumnsToTheNewKey(): void
    {
        $codec = $this->get(EnvelopeCodecInterface::class);
        self::assertInstanceOf(EnvelopeCodecInterface::class, $codec);

        $connection = $this->connectionPool->getConnectionForTable('tx_nrllm_a2a_push_config');
        $connection->insert('tx_nrllm_a2a_push_config', [
            'config_id'   => 'c-1',
            'task_uid'    => 1,
            'url'         => 'https://93.184.215.14/hook',
            'token'       => $codec->seal('the token', AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL),
            'auth_scheme' => 'Bearer',
            'credentials' => $codec->seal('the credential', AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL),
        ]);
        $uid = (int) $connection->lastInsertId();

        self::assertSame(2, $this->rotator->countEnvelopes());

        $newKey = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
        self::assertSame(2, $this->rotator->rewrapAll($this->contextTo($newKey)));

        $row = $connection->select(['token', 'credentials'], 'tx_nrllm_a2a_push_config', ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);
        self::assertIsString($row['token']);
        self::assertIsString($row['credentials']);
        $newCodec = $this->codecFor($newKey);
        self::assertSame('the token', $newCodec->open($row['token'], AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL));
        self::assertSame('the credential', $newCodec->open($row['credentials'], AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL));
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
        $uid   = $this->insertRun($plain, '');

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
     * The sealed URL and bytes of every A2A file move to the new key, also
     * when the file table is read in pages smaller than its row count.
     */
    #[Test]
    public function rewrappingMovesEveryFileRowToTheNewKey(): void
    {
        $codec = $this->get(EnvelopeCodecInterface::class);
        self::assertInstanceOf(EnvelopeCodecInterface::class, $codec);

        $uids = [];
        for ($i = 0; $i < 3; ++$i) {
            $uids[$i] = $this->insertFile(
                $codec->seal('https://93.184.216.34/files/' . $i . '.txt', AgentStateCodec::PURPOSE_A2A_FILE),
                $codec->seal('Notes ' . $i, AgentStateCodec::PURPOSE_A2A_FILE),
            );
        }

        $newKey = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
        self::assertSame(6, $this->rotator->rewrapAll($this->contextTo($newKey)));

        $newCodec = $this->codecFor($newKey);
        foreach ($uids as $i => $uid) {
            self::assertSame('https://93.184.216.34/files/' . $i . '.txt', $newCodec->open($this->storedValue($uid, 'source_url', 'tx_nrllm_a2a_file'), AgentStateCodec::PURPOSE_A2A_FILE));
            self::assertSame('Notes ' . $i, $newCodec->open($this->storedValue($uid, 'content', 'tx_nrllm_a2a_file'), AgentStateCodec::PURPOSE_A2A_FILE));
        }
    }

    /**
     * A file of the largest size (8 MiB, about 15 MiB sealed) is rewrapped
     * by a statement that carries the new sealed value only: one that also
     * named the old value in its WHERE would exceed the 16 MiB a MariaDB
     * packet holds by default (`max_allowed_packet`).
     */
    #[Test]
    public function aFileOfTheLargestSizeIsRewrapped(): void
    {
        $codec = $this->get(EnvelopeCodecInterface::class);
        self::assertInstanceOf(EnvelopeCodecInterface::class, $codec);

        $bytes  = str_repeat("Line of a long text file.\n", intdiv(8388608, 26));
        $sealed = $codec->seal($bytes, AgentStateCodec::PURPOSE_A2A_FILE);
        self::assertGreaterThan(8388608, strlen($sealed));
        $uid = $this->insertFile('', $sealed);
        unset($sealed);

        $newKey = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
        self::assertSame(1, $this->rotator->rewrapAll($this->contextTo($newKey)));
        self::assertSame($bytes, $this->codecFor($newKey)->open($this->storedValue($uid, 'content', 'tx_nrllm_a2a_file'), AgentStateCodec::PURPOSE_A2A_FILE));
    }

    /**
     * A sealed A2A file holds up to about 15 MiB, so the file table is read
     * one row at a time: rotating ten files of 2 MiB peaks at a few times one
     * sealed file (about 4 times on SQLite, 6 on MariaDB, measured), while a
     * page holding all ten peaked at 13 and 20 times.
     */
    #[Test]
    public function theFileTableIsRewrappedOneRowAtATime(): void
    {
        $codec = $this->get(EnvelopeCodecInterface::class);
        self::assertInstanceOf(EnvelopeCodecInterface::class, $codec);

        $sealedLength = 0;
        for ($i = 0; $i < 10; ++$i) {
            $sealed       = $codec->seal(random_bytes(2097152), AgentStateCodec::PURPOSE_A2A_FILE);
            $sealedLength = strlen($sealed);
            $this->insertFile('', $sealed);
        }

        unset($sealed);
        $context = $this->contextTo(random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES));
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();

        self::assertSame(10, $this->rotator->rewrapAll($context));
        self::assertLessThan(9 * $sealedLength, memory_get_peak_usage() - $before, 'the rotation holds no page of several sealed files');
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
            'pid'             => 0,
            'uuid'            => bin2hex(random_bytes(8)),
            'queued_request'  => $queuedRequest,
            'suspended_state' => $suspendedState,
        ]);

        return (int) $connection->lastInsertId();
    }

    private function insertFile(string $sourceUrl, string $content): int
    {
        $connection = $this->connectionPool->getConnectionForTable('tx_nrllm_a2a_file');
        $connection->insert('tx_nrllm_a2a_file', [
            'file_id'    => bin2hex(random_bytes(16)),
            'source_url' => $sourceUrl,
            'content'    => $content,
        ]);

        return (int) $connection->lastInsertId();
    }

    private function storedValue(int $uid, string $column, string $table = self::TABLE): string
    {
        $connection   = $this->connectionPool->getConnectionForTable($table);
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();

        $value = $queryBuilder
            ->select($column)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($value);

        return $value;
    }
}
