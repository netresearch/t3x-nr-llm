<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Exception\AgentStateDecryptionException;
use SensitiveParameter;

/**
 * Authenticated encryption for an agent run's state at rest (ADR-114).
 *
 * A queued run stores its serialised request, and a suspended run its
 * transcript and pending tool calls, in `tx_nrllm_agentrun` while it waits for a
 * worker or a human. Both hold prompts, tool arguments and internal TYPO3
 * content in cleartext — readable by anyone with database access. This codec
 * encrypts them so the row carries ciphertext, not the conversation.
 *
 * Format (version-tagged so a later scheme is distinguishable):
 * ``v1:`` + base64( nonce ‖ ciphertext‖tag ), using libsodium's XChaCha20-Poly1305
 * AEAD — authenticated, so a tampered or truncated row fails to decrypt rather
 * than yielding garbage. A fresh random nonce per encryption means the same
 * plaintext never encrypts to the same bytes. The key is derived from the
 * instance's ``encryptionKey`` via HKDF with a fixed context, so it is
 * instance-specific and never the raw site secret.
 *
 * Backwards compatible: {@see decode()} returns any value WITHOUT the ``v1:``
 * marker verbatim, so rows written before this landed (plaintext JSON) still
 * rehydrate. New writes are always encrypted.
 */
final readonly class AgentStateCodec
{
    private const VERSION_PREFIX = 'v1:';

    /**
     * HKDF context binding the derived key to this purpose, so the same
     * ``encryptionKey`` used elsewhere never yields the same key.
     */
    private const KDF_CONTEXT = 'nr_llm:agent-state:v1';

    /**
     * @param string|null $encryptionKey the instance master secret; null reads
     *                                   the TYPO3 site ``encryptionKey`` (the
     *                                   production path). Injected explicitly
     *                                   only by tests.
     */
    public function __construct(
        #[SensitiveParameter]
        private ?string $encryptionKey = null,
    ) {}

    /**
     * Encrypt a plaintext state payload for storage. An empty string stores as
     * empty (the "no state" sentinel the columns already use), never as a
     * ciphertext of the empty string.
     */
    public function encode(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $nonce      = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $this->key());

        return self::VERSION_PREFIX . base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt a stored state payload. A value without the version marker is
     * treated as legacy cleartext and returned verbatim (upgrade path); an empty
     * value returns empty. A version-tagged value that fails authentication —
     * tampered, truncated, or written under a different key — throws rather than
     * returning a forged plaintext.
     *
     * @throws AgentStateDecryptionException
     */
    public function decode(string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, self::VERSION_PREFIX)) {
            return $stored;
        }

        $raw = base64_decode(substr($stored, strlen(self::VERSION_PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw AgentStateDecryptionException::corrupted();
        }

        $nonce      = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, '', $nonce, $this->key());
        if ($plaintext === false) {
            throw AgentStateDecryptionException::authenticationFailed();
        }

        return $plaintext;
    }

    /**
     * The 32-byte AEAD key, derived from the instance secret. Fail-closed: with
     * no ``encryptionKey`` there is no safe key, so encryption refuses rather
     * than falling back to storing cleartext.
     */
    private function key(): string
    {
        $master = $this->encryptionKey ?? self::instanceEncryptionKey();
        if ($master === '') {
            throw AgentStateDecryptionException::noEncryptionKey();
        }

        return hash_hkdf('sha256', $master, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::KDF_CONTEXT);
    }

    /**
     * The TYPO3 site ``encryptionKey``, or '' when absent (narrowed step by step
     * because the ``$GLOBALS`` shape is untyped).
     */
    private static function instanceEncryptionKey(): string
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sys      = is_array($confVars) ? ($confVars['SYS'] ?? null) : null;
        $key      = is_array($sys) ? ($sys['encryptionKey'] ?? '') : '';

        return is_string($key) ? $key : '';
    }
}
