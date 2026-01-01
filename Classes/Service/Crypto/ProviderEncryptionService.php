<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Crypto;

use RuntimeException;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Encryption service for provider secrets (API keys).
 *
 * Uses sodium_crypto_secretbox (XSalsa20-Poly1305) for authenticated encryption.
 * The encryption key is derived from TYPO3's encryptionKey using SHA-256.
 *
 * Encrypted values are prefixed with 'enc:' to distinguish from plaintext.
 * This allows storing both encrypted and unencrypted values transparently.
 *
 * Security notes:
 * - Nonce is randomly generated per encryption and prepended to ciphertext
 * - Authentication tag prevents tampering (Poly1305 MAC)
 * - Key derivation uses SHA-256 to ensure 32-byte key length
 */
final class ProviderEncryptionService implements ProviderEncryptionServiceInterface, SingletonInterface
{
    /** Prefix for encrypted values to distinguish from plaintext. */
    private const string ENCRYPTION_PREFIX = 'enc:';

    /** Derived encryption key (cached). */
    private ?string $derivedKey = null;

    /**
     * @param string $typo3EncryptionKey TYPO3 system encryption key (injected via Services.yaml)
     */
    public function __construct(
        private readonly string $typo3EncryptionKey,
    ) {}

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        // Already encrypted? Return as-is
        if ($this->isEncrypted($plaintext)) {
            return $plaintext;
        }

        $key = $this->getDerivedKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        // Clear plaintext from memory
        sodium_memzero($plaintext);

        return self::ENCRYPTION_PREFIX . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $ciphertext): string
    {
        if ($ciphertext === '') {
            return '';
        }

        // Not encrypted? Return as-is (supports both encrypted and plaintext values)
        if (!$this->isEncrypted($ciphertext)) {
            return $ciphertext;
        }

        // Remove prefix and decode
        $decoded = base64_decode(substr($ciphertext, strlen(self::ENCRYPTION_PREFIX)), true);

        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid encrypted value format', 1735304800);
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $key = $this->getDerivedKey();
        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: invalid key or tampered data', 1735304801);
        }

        return $plaintext;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::ENCRYPTION_PREFIX);
    }

    /**
     * Get or derive the encryption key.
     *
     * The key is derived from TYPO3's encryptionKey using SHA-256 to ensure
     * we always have exactly 32 bytes (256 bits) for XSalsa20.
     */
    private function getDerivedKey(): string
    {
        if ($this->derivedKey !== null) {
            return $this->derivedKey;
        }

        if ($this->typo3EncryptionKey === '') {
            throw new RuntimeException(
                'TYPO3 encryptionKey is not configured. Cannot encrypt provider secrets.',
                1735304802,
            );
        }

        // Derive a 32-byte key using SHA-256
        // Adding a constant domain separator for key derivation
        $this->derivedKey = hash('sha256', $this->typo3EncryptionKey . ':nr_llm_provider_encryption', true);

        return $this->derivedKey;
    }
}
