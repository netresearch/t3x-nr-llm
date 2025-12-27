<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Crypto;

/**
 * Interface for encrypting/decrypting provider secrets (API keys).
 *
 * Provides application-level encryption for secrets stored in the database.
 * Uses TYPO3's encryptionKey as the master key for encryption.
 */
interface ProviderEncryptionServiceInterface
{
    /**
     * Encrypt a plaintext value.
     *
     * @param string $plaintext The value to encrypt
     *
     * @return string Base64-encoded ciphertext (includes nonce)
     */
    public function encrypt(string $plaintext): string;

    /**
     * Decrypt an encrypted value.
     *
     * @param string $ciphertext Base64-encoded ciphertext
     *
     * @return string The decrypted plaintext
     *
     * @throws \RuntimeException If decryption fails (wrong key, tampered data)
     */
    public function decrypt(string $ciphertext): string;

    /**
     * Check if a value appears to be encrypted.
     *
     * Checks for the encryption prefix to distinguish encrypted from plaintext values.
     *
     * @param string $value The value to check
     *
     * @return bool True if value appears to be encrypted
     */
    public function isEncrypted(string $value): bool;
}
