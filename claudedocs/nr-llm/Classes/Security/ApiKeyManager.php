<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Security;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Secure API key storage and retrieval with AES-256-GCM encryption
 *
 * Security Features:
 * - AES-256-GCM authenticated encryption
 * - Per-site encryption contexts
 * - Key derivation from TYPO3 encryptionKey
 * - Automatic key rotation support
 * - Audit logging integration
 */
class ApiKeyManager implements SingletonInterface
{
    private const TABLE_NAME = 'tx_nrllm_apikeys';
    private const CIPHER_METHOD = 'aes-256-gcm';
    private const KEY_LENGTH = 32; // 256 bits
    private const TAG_LENGTH = 16; // 128 bits
    private const PBKDF2_ITERATIONS = 100000;

    private ConnectionPool $connectionPool;
    private AuditLogger $auditLogger;
    private ?string $encryptionKey = null;
    private string $pepper;

    public function __construct(
        ConnectionPool $connectionPool = null,
        AuditLogger $auditLogger = null
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->auditLogger = $auditLogger ?? GeneralUtility::makeInstance(AuditLogger::class);

        // Load pepper from LocalConfiguration (should be separate from encryptionKey)
        $this->pepper = $GLOBALS['TYPO3_CONF_VARS']['SYS']['nrllm_encryption_pepper'] ?? '';

        if (empty($this->pepper)) {
            throw new \RuntimeException(
                'Missing encryption pepper. Set $GLOBALS[\'TYPO3_CONF_VARS\'][\'SYS\'][\'nrllm_encryption_pepper\']',
                1703001000
            );
        }
    }

    /**
     * Store an API key securely with encryption
     *
     * @param string $provider Provider identifier (e.g., 'openai', 'anthropic')
     * @param string $key Plain text API key
     * @param string $scope Scope identifier ('global', site identifier, or user ID)
     * @param array $metadata Additional metadata (e.g., environment, description)
     * @throws Exception
     * @throws \RuntimeException
     */
    public function store(
        string $provider,
        string $key,
        string $scope = 'global',
        array $metadata = []
    ): void {
        if (empty($provider) || empty($key)) {
            throw new \InvalidArgumentException('Provider and key must not be empty', 1703001001);
        }

        // Validate key format before encryption
        if (!$this->validateKeyFormat($provider, $key)) {
            throw new \InvalidArgumentException(
                "Invalid API key format for provider: {$provider}",
                1703001002
            );
        }

        // Encrypt the API key
        $encrypted = $this->encrypt($key, $this->buildContext($provider, $scope));

        // Check if key already exists
        $existing = $this->findKey($provider, $scope);

        $data = [
            'provider' => $provider,
            'scope' => $scope,
            'encrypted_key' => $encrypted['ciphertext'],
            'encryption_iv' => $encrypted['iv'],
            'encryption_tag' => $encrypted['tag'],
            'metadata' => json_encode($metadata),
            'last_rotated' => time(),
            'tstamp' => time(),
        ];

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);

        if ($existing) {
            // Update existing key
            $connection->update(
                self::TABLE_NAME,
                $data,
                ['uid' => $existing['uid']]
            );

            $this->auditLogger->logKeyRotation($provider, $scope, $existing['uid']);
        } else {
            // Insert new key
            $data['crdate'] = time();
            $connection->insert(self::TABLE_NAME, $data);

            $this->auditLogger->logKeyCreation($provider, $scope, (int)$connection->lastInsertId());
        }
    }

    /**
     * Retrieve and decrypt an API key
     *
     * @param string $provider Provider identifier
     * @param string $scope Scope identifier
     * @return string|null Decrypted API key or null if not found
     * @throws Exception
     */
    public function retrieve(string $provider, string $scope = 'global'): ?string
    {
        $record = $this->findKey($provider, $scope);

        if (!$record) {
            $this->auditLogger->logKeyAccessAttempt($provider, $scope, false, 'Key not found');
            return null;
        }

        try {
            $decrypted = $this->decrypt(
                $record['encrypted_key'],
                $record['encryption_iv'],
                $record['encryption_tag'],
                $this->buildContext($provider, $scope)
            );

            $this->auditLogger->logKeyAccess($provider, $scope, $record['uid']);

            return $decrypted;
        } catch (\RuntimeException $e) {
            $this->auditLogger->logKeyAccessAttempt(
                $provider,
                $scope,
                false,
                'Decryption failed: ' . $e->getMessage()
            );
            throw $e;
        }
    }

    /**
     * Rotate an existing API key
     *
     * @param string $provider Provider identifier
     * @param string $newKey New API key
     * @param string $scope Scope identifier
     * @throws Exception
     */
    public function rotate(string $provider, string $newKey, string $scope = 'global'): void
    {
        $existing = $this->findKey($provider, $scope);

        if (!$existing) {
            throw new \RuntimeException("Cannot rotate: Key not found for {$provider}/{$scope}", 1703001003);
        }

        // Store with updated timestamp
        $metadata = json_decode($existing['metadata'] ?? '{}', true);
        $metadata['previous_rotation'] = $existing['last_rotated'];

        $this->store($provider, $newKey, $scope, $metadata);
    }

    /**
     * Validate API key format for a provider
     *
     * @param string $provider Provider identifier
     * @param string $key API key to validate
     * @return bool True if valid format
     */
    public function validate(string $provider, string $key): bool
    {
        return $this->validateKeyFormat($provider, $key);
    }

    /**
     * Delete an API key
     *
     * @param string $provider Provider identifier
     * @param string $scope Scope identifier
     * @throws Exception
     */
    public function delete(string $provider, string $scope = 'global'): void
    {
        $record = $this->findKey($provider, $scope);

        if ($record) {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
            $connection->delete(
                self::TABLE_NAME,
                ['uid' => $record['uid']]
            );

            $this->auditLogger->logKeyDeletion($provider, $scope, $record['uid']);
        }
    }

    /**
     * List all stored API keys (without decrypting)
     *
     * @param string|null $scope Filter by scope
     * @return array Array of key metadata
     */
    public function listKeys(?string $scope = null): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->select('uid', 'provider', 'scope', 'last_rotated', 'metadata', 'crdate')
            ->from(self::TABLE_NAME)
            ->orderBy('provider')
            ->addOrderBy('scope');

        if ($scope !== null) {
            $queryBuilder->where(
                $queryBuilder->expr()->eq('scope', $queryBuilder->createNamedParameter($scope))
            );
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Check if a key exists for provider/scope
     *
     * @param string $provider Provider identifier
     * @param string $scope Scope identifier
     * @return bool True if key exists
     */
    public function hasKey(string $provider, string $scope = 'global'): bool
    {
        return $this->findKey($provider, $scope) !== null;
    }

    /**
     * Encrypt data with AES-256-GCM
     *
     * @param string $plaintext Data to encrypt
     * @param string $context Additional authenticated data (AAD)
     * @return array ['ciphertext', 'iv', 'tag']
     * @throws \RuntimeException
     */
    private function encrypt(string $plaintext, string $context): array
    {
        $key = $this->getDerivedKey($context);
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER_METHOD));
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context, // Additional authenticated data
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string(), 1703001004);
        }

        // Securely wipe key from memory
        sodium_memzero($key);

        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
        ];
    }

    /**
     * Decrypt data with AES-256-GCM
     *
     * @param string $ciphertext Base64-encoded ciphertext
     * @param string $iv Base64-encoded initialization vector
     * @param string $tag Base64-encoded authentication tag
     * @param string $context Additional authenticated data (AAD)
     * @return string Decrypted plaintext
     * @throws \RuntimeException
     */
    private function decrypt(string $ciphertext, string $iv, string $tag, string $context): string
    {
        $key = $this->getDerivedKey($context);

        $plaintext = openssl_decrypt(
            base64_decode($ciphertext),
            self::CIPHER_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            base64_decode($iv),
            base64_decode($tag),
            $context // Additional authenticated data
        );

        // Securely wipe key from memory
        sodium_memzero($key);

        if ($plaintext === false) {
            throw new \RuntimeException(
                'Decryption failed: Invalid ciphertext or authentication tag',
                1703001005
            );
        }

        return $plaintext;
    }

    /**
     * Derive encryption key from TYPO3 encryptionKey using PBKDF2
     *
     * @param string $context Context for key derivation (provider + scope)
     * @return string Derived key
     * @throws \RuntimeException
     */
    private function getDerivedKey(string $context): string
    {
        if ($this->encryptionKey === null) {
            $this->encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';

            if (empty($this->encryptionKey)) {
                throw new \RuntimeException('TYPO3 encryptionKey is not configured', 1703001006);
            }

            if (strlen($this->encryptionKey) < 96) {
                throw new \RuntimeException('TYPO3 encryptionKey is too short (min 96 chars)', 1703001007);
            }
        }

        // Derive key using PBKDF2 with context as salt
        $salt = hash('sha256', $this->pepper . $context, true);

        return hash_pbkdf2(
            'sha256',
            $this->encryptionKey,
            $salt,
            self::PBKDF2_ITERATIONS,
            self::KEY_LENGTH,
            true
        );
    }

    /**
     * Build encryption context from provider and scope
     *
     * @param string $provider Provider identifier
     * @param string $scope Scope identifier
     * @return string Context string for authenticated encryption
     */
    private function buildContext(string $provider, string $scope): string
    {
        return "nrllm:{$provider}:{$scope}";
    }

    /**
     * Find a stored key by provider and scope
     *
     * @param string $provider Provider identifier
     * @param string $scope Scope identifier
     * @return array|null Key record or null
     */
    private function findKey(string $provider, string $scope): ?array
    {
        $queryBuilder = $this->getQueryBuilder();

        $result = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('provider', $queryBuilder->createNamedParameter($provider)),
                $queryBuilder->expr()->eq('scope', $queryBuilder->createNamedParameter($scope))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Validate API key format for specific providers
     *
     * @param string $provider Provider identifier
     * @param string $key API key to validate
     * @return bool True if format is valid
     */
    private function validateKeyFormat(string $provider, string $key): bool
    {
        // Provider-specific validation patterns
        $patterns = [
            'openai' => '/^sk-[A-Za-z0-9]{32,}$/', // OpenAI keys start with sk-
            'openai_org' => '/^org-[A-Za-z0-9]{24,}$/', // Organization ID
            'anthropic' => '/^sk-ant-[A-Za-z0-9\-_]{32,}$/', // Anthropic keys
            'azure' => '/^[A-Za-z0-9]{32}$/', // Azure OpenAI keys
            'huggingface' => '/^hf_[A-Za-z0-9]{34}$/', // HuggingFace tokens
        ];

        // If no pattern defined, accept any non-empty string
        if (!isset($patterns[$provider])) {
            return strlen($key) >= 16; // Minimum reasonable key length
        }

        return preg_match($patterns[$provider], $key) === 1;
    }

    /**
     * Get a fresh query builder instance
     *
     * @return QueryBuilder
     */
    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
    }

    /**
     * Cleanup method to ensure sensitive data is wiped from memory
     */
    public function __destruct()
    {
        if ($this->encryptionKey !== null) {
            sodium_memzero($this->encryptionKey);
        }
    }
}
