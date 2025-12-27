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
 * This allows for gradual migration and backwards compatibility.
 *
 * Security notes:
 * - Nonce is randomly generated per encryption and prepended to ciphertext
 * - Authentication tag prevents tampering (Poly1305 MAC)
 * - Key derivation uses SHA-256 to ensure 32-byte key length
 */
final class ProviderEncryptionService implements ProviderEncryptionServiceInterface, SingletonInterface
{
    /** Prefix for encrypted values to distinguish from plaintext. */
    private const ENCRYPTION_PREFIX = 'enc:';

    /** Encryption key derived from TYPO3 encryptionKey. */
    private ?string $encryptionKey = null;

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        // Already encrypted? Return as-is
        if ($this->isEncrypted($plaintext)) {
            return $plaintext;
        }

        $key = $this->getEncryptionKey();
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

        // Not encrypted? Return as-is (backwards compatibility for existing plaintext)
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

        $key = $this->getEncryptionKey();
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
    private function getEncryptionKey(): string
    {
        if ($this->encryptionKey !== null) {
            return $this->encryptionKey;
        }

        $typo3EncryptionKey = $this->getTypo3EncryptionKey();

        if ($typo3EncryptionKey === '') {
            throw new RuntimeException(
                'TYPO3 encryptionKey is not configured. Cannot encrypt provider secrets.',
                1735304802,
            );
        }

        // Derive a 32-byte key using SHA-256
        // Adding a constant domain separator for key derivation
        $this->encryptionKey = hash('sha256', $typo3EncryptionKey . ':nr_llm_provider_encryption', true);

        return $this->encryptionKey;
    }

    /**
     * Get TYPO3 encryption key from configuration.
     */
    private function getTypo3EncryptionKey(): string
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($confVars)) {
            return '';
        }

        $sysConf = $confVars['SYS'] ?? [];
        if (!is_array($sysConf)) {
            return '';
        }

        $encryptionKey = $sysConf['encryptionKey'] ?? '';

        return is_string($encryptionKey) ? $encryptionKey : '';
    }
}
