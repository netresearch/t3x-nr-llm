<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Exception\AgentStateDecryptionException;
use Netresearch\NrVault\Crypto\EncryptionServiceInterface;
use Netresearch\NrVault\Exception\EncryptionException;
use SensitiveParameter;
use Throwable;

/**
 * Authenticated encryption for an agent run's state at rest (ADR-114).
 *
 * A queued run stores its serialised request, and a suspended run its
 * transcript and pending tool calls, in `tx_nrllm_agentrun` while it waits for a
 * worker or a human. Both hold prompts, tool arguments and internal TYPO3
 * content in cleartext — readable by anyone with database access. This codec
 * encrypts them so the row carries ciphertext, not the conversation.
 *
 * It delegates to nr-vault's {@see EncryptionServiceInterface} — the same
 * managed-key envelope AEAD the vault uses — rather than hand-rolling crypto:
 * a per-value data key wrapped by a rotatable master key (so the master can be
 * rotated without re-encrypting every row), authenticated so a tampered row
 * fails to decrypt, and bound to a per-column ``identifier`` used as additional
 * authenticated data so a ciphertext cannot be moved between the two columns.
 *
 * Stored form: ``v2:`` + base64( json({@see EncryptedData::toArray()}) ). The
 * version prefix distinguishes the envelope from the legacy cleartext it
 * replaces.
 *
 * Backwards compatible: {@see decode()} returns any value WITHOUT the ``v2:``
 * marker verbatim, so rows written before encryption landed (plaintext JSON)
 * still rehydrate. New writes are always encrypted.
 */
final readonly class AgentStateCodec
{
    private const VERSION_PREFIX = 'v2:';

    /** AAD identifier for the queued request payload (ADR-114). */
    public const PURPOSE_QUEUED_REQUEST = 'nrllm:agent-state:queued-request';

    /** AAD identifier for the suspended run state payload (ADR-114). */
    public const PURPOSE_SUSPENDED_STATE = 'nrllm:agent-state:suspended-state';

    public function __construct(
        private EncryptionServiceInterface $encryption,
    ) {}

    /**
     * Encrypt a plaintext state payload for storage under the given per-column
     * identifier (used as AAD). An empty string stores as empty (the "no state"
     * sentinel the columns already use), never as a ciphertext of the empty
     * string.
     */
    public function encode(#[SensitiveParameter] string $plaintext, string $identifier): string
    {
        if ($plaintext === '') {
            return '';
        }

        $encrypted = $this->encryption->encrypt($plaintext, $identifier);

        return self::VERSION_PREFIX . base64_encode(
            json_encode($encrypted->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Decrypt a stored state payload. A value without the version marker is
     * treated as legacy cleartext and returned verbatim (upgrade path); an empty
     * value returns empty. A version-tagged value that fails authentication —
     * tampered, truncated, moved to the wrong column, or written under a
     * different key — throws rather than returning a forged plaintext.
     *
     * @throws AgentStateDecryptionException
     */
    public function decode(string $stored, string $identifier): string
    {
        if ($stored === '' || !str_starts_with($stored, self::VERSION_PREFIX)) {
            return $stored;
        }

        $envelope = $this->parseEnvelope(substr($stored, strlen(self::VERSION_PREFIX)));

        try {
            return $this->encryption->decrypt(
                $envelope['encrypted_value'],
                $envelope['encrypted_dek'],
                $envelope['dek_nonce'],
                $envelope['value_nonce'],
                $identifier,
                $envelope['encryption_version'],
                $envelope['encryption_algorithm'],
            );
        } catch (EncryptionException $exception) {
            throw AgentStateDecryptionException::authenticationFailed($exception);
        }
    }

    /**
     * Decode and validate the stored envelope fields.
     *
     *
     * @throws AgentStateDecryptionException
     *
     * @return array{encrypted_value: string, encrypted_dek: string, dek_nonce: string, value_nonce: string, encryption_version: int, encryption_algorithm: string}
     */
    private function parseEnvelope(string $base64): array
    {
        $json = base64_decode($base64, true);
        if ($json === false) {
            throw AgentStateDecryptionException::corrupted();
        }

        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw AgentStateDecryptionException::corrupted();
        }

        if (!is_array($decoded)) {
            throw AgentStateDecryptionException::corrupted();
        }

        $value     = $decoded['encrypted_value'] ?? null;
        $dek       = $decoded['encrypted_dek'] ?? null;
        $dekNonce  = $decoded['dek_nonce'] ?? null;
        $valNonce  = $decoded['value_nonce'] ?? null;
        $version   = $decoded['encryption_version'] ?? null;
        $algorithm = $decoded['encryption_algorithm'] ?? null;

        if (!is_string($value) || !is_string($dek) || !is_string($dekNonce)
            || !is_string($valNonce) || !is_int($version) || !is_string($algorithm)
        ) {
            throw AgentStateDecryptionException::corrupted();
        }

        return [
            'encrypted_value'      => $value,
            'encrypted_dek'        => $dek,
            'dek_nonce'            => $dekNonce,
            'value_nonce'          => $valNonce,
            'encryption_version'   => $version,
            'encryption_algorithm' => $algorithm,
        ];
    }
}
