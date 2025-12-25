<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Security;

use Exception;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Comprehensive audit logging for security events.
 *
 * Logged Events:
 * - API key access/rotation/deletion
 * - LLM requests (metadata only, not full prompts)
 * - Configuration changes
 * - Access denied events
 * - Quota exceeded events
 * - Suspicious activity
 *
 * Privacy Considerations:
 * - Prompt content is NOT logged (GDPR compliance)
 * - Only metadata logged (length, model, user, timestamp)
 * - PII anonymization after retention period
 * - Configurable retention policies
 */
class AuditLogger implements SingletonInterface
{
    private const TABLE_NAME = 'tx_nrllm_audit';

    // Event types
    public const EVENT_KEY_ACCESS = 'key_access';
    public const EVENT_KEY_ACCESS_ATTEMPT = 'key_access_attempt';
    public const EVENT_KEY_CREATION = 'key_creation';
    public const EVENT_KEY_ROTATION = 'key_rotation';
    public const EVENT_KEY_DELETION = 'key_deletion';
    public const EVENT_LLM_REQUEST = 'llm_request';
    public const EVENT_LLM_RESPONSE = 'llm_response';
    public const EVENT_LLM_ERROR = 'llm_error';
    public const EVENT_CONFIG_CHANGE = 'config_change';
    public const EVENT_ACCESS_DENIED = 'access_denied';
    public const EVENT_QUOTA_EXCEEDED = 'quota_exceeded';
    public const EVENT_SUSPICIOUS_ACTIVITY = 'suspicious_activity';

    // Severity levels
    public const SEVERITY_INFO = 0;
    public const SEVERITY_NOTICE = 1;
    public const SEVERITY_WARNING = 2;
    public const SEVERITY_ERROR = 3;
    public const SEVERITY_CRITICAL = 4;

    private ConnectionPool $connectionPool;
    private Logger $logger;
    private int $retentionDays;
    private int $anonymizeAfterDays;

    public function __construct(
        ?ConnectionPool $connectionPool = null,
        ?LogManager $logManager = null,
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
        $this->logger = ($logManager ?? GeneralUtility::makeInstance(LogManager::class))
            ->getLogger(__CLASS__);

        // Load retention configuration
        $config = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['security']['audit'] ?? [];
        $this->retentionDays = (int)($config['retentionDays'] ?? 90);
        $this->anonymizeAfterDays = (int)($config['anonymizeAfterDays'] ?? 30);
    }

    /**
     * Log successful API key access.
     *
     * @param string $provider Provider identifier
     * @param string $scope    Scope identifier
     * @param int    $keyUid   Key record UID
     */
    public function logKeyAccess(string $provider, string $scope, int $keyUid): void
    {
        $this->log(
            self::EVENT_KEY_ACCESS,
            self::SEVERITY_INFO,
            "API key accessed for {$provider}/{$scope}",
            [
                'provider' => $provider,
                'scope' => $scope,
                'key_uid' => $keyUid,
            ],
        );
    }

    /**
     * Log failed API key access attempt.
     *
     * @param string $provider Provider identifier
     * @param string $scope    Scope identifier
     * @param bool   $success  Whether access was successful
     * @param string $reason   Failure reason
     */
    public function logKeyAccessAttempt(string $provider, string $scope, bool $success, string $reason = ''): void
    {
        $this->log(
            self::EVENT_KEY_ACCESS_ATTEMPT,
            $success ? self::SEVERITY_INFO : self::SEVERITY_WARNING,
            "API key access attempt for {$provider}/{$scope}: " . ($success ? 'success' : 'failed'),
            [
                'provider' => $provider,
                'scope' => $scope,
                'success' => $success,
                'reason' => $reason,
            ],
        );
    }

    /**
     * Log API key creation.
     *
     * @param string $provider Provider identifier
     * @param string $scope    Scope identifier
     * @param int    $keyUid   New key record UID
     */
    public function logKeyCreation(string $provider, string $scope, int $keyUid): void
    {
        $this->log(
            self::EVENT_KEY_CREATION,
            self::SEVERITY_NOTICE,
            "API key created for {$provider}/{$scope}",
            [
                'provider' => $provider,
                'scope' => $scope,
                'key_uid' => $keyUid,
            ],
        );
    }

    /**
     * Log API key rotation.
     *
     * @param string $provider Provider identifier
     * @param string $scope    Scope identifier
     * @param int    $keyUid   Key record UID
     */
    public function logKeyRotation(string $provider, string $scope, int $keyUid): void
    {
        $this->log(
            self::EVENT_KEY_ROTATION,
            self::SEVERITY_NOTICE,
            "API key rotated for {$provider}/{$scope}",
            [
                'provider' => $provider,
                'scope' => $scope,
                'key_uid' => $keyUid,
            ],
        );
    }

    /**
     * Log API key deletion.
     *
     * @param string $provider Provider identifier
     * @param string $scope    Scope identifier
     * @param int    $keyUid   Deleted key record UID
     */
    public function logKeyDeletion(string $provider, string $scope, int $keyUid): void
    {
        $this->log(
            self::EVENT_KEY_DELETION,
            self::SEVERITY_NOTICE,
            "API key deleted for {$provider}/{$scope}",
            [
                'provider' => $provider,
                'scope' => $scope,
                'key_uid' => $keyUid,
            ],
        );
    }

    /**
     * Log LLM request (metadata only, NOT full prompt).
     *
     * @param string $provider     LLM provider
     * @param string $model        Model identifier
     * @param int    $promptTokens Prompt token count
     * @param array  $metadata     Additional metadata
     */
    public function logLlmRequest(
        string $provider,
        string $model,
        int $promptTokens,
        array $metadata = [],
    ): void {
        $this->log(
            self::EVENT_LLM_REQUEST,
            self::SEVERITY_INFO,
            "LLM request to {$provider}/{$model}",
            array_merge([
                'provider' => $provider,
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'prompt_length' => $metadata['prompt_length'] ?? 0,
                // Note: Full prompt is NOT logged for privacy
            ], $metadata),
        );
    }

    /**
     * Log LLM response (metadata only).
     *
     * @param string $provider         LLM provider
     * @param string $model            Model identifier
     * @param int    $completionTokens Completion token count
     * @param int    $totalTokens      Total token count
     * @param float  $duration         Request duration in seconds
     * @param array  $metadata         Additional metadata
     */
    public function logLlmResponse(
        string $provider,
        string $model,
        int $completionTokens,
        int $totalTokens,
        float $duration,
        array $metadata = [],
    ): void {
        $this->log(
            self::EVENT_LLM_RESPONSE,
            self::SEVERITY_INFO,
            "LLM response from {$provider}/{$model}",
            array_merge([
                'provider' => $provider,
                'model' => $model,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'duration' => $duration,
                'response_length' => $metadata['response_length'] ?? 0,
                // Note: Full response is NOT logged for privacy
            ], $metadata),
        );
    }

    /**
     * Log LLM error.
     *
     * @param string $provider     LLM provider
     * @param string $model        Model identifier
     * @param string $errorMessage Error message
     * @param int    $statusCode   HTTP status code
     */
    public function logLlmError(
        string $provider,
        string $model,
        string $errorMessage,
        int $statusCode = 0,
    ): void {
        $this->log(
            self::EVENT_LLM_ERROR,
            self::SEVERITY_ERROR,
            "LLM error from {$provider}/{$model}: {$errorMessage}",
            [
                'provider' => $provider,
                'model' => $model,
                'error_message' => $errorMessage,
                'status_code' => $statusCode,
            ],
        );
    }

    /**
     * Log configuration change.
     *
     * @param string $configKey Configuration key
     * @param mixed  $oldValue  Old value (will be JSON encoded)
     * @param mixed  $newValue  New value (will be JSON encoded)
     */
    public function logConfigChange(string $configKey, $oldValue, $newValue): void
    {
        $this->log(
            self::EVENT_CONFIG_CHANGE,
            self::SEVERITY_NOTICE,
            "Configuration changed: {$configKey}",
            [
                'config_key' => $configKey,
                'old_value' => $this->sanitizeValue($oldValue),
                'new_value' => $this->sanitizeValue($newValue),
            ],
        );
    }

    /**
     * Log access denied event.
     *
     * @param string   $permission Permission that was denied
     * @param int|null $userId     User ID (if available)
     * @param string   $context    Additional context
     */
    public function logAccessDenied(string $permission, ?int $userId, string $context = ''): void
    {
        $this->log(
            self::EVENT_ACCESS_DENIED,
            self::SEVERITY_WARNING,
            "Access denied: {$permission}",
            [
                'permission' => $permission,
                'user_id' => $userId,
                'context' => $context,
            ],
        );
    }

    /**
     * Log quota exceeded event.
     *
     * @param int    $userId    User ID
     * @param string $quotaType Quota type
     * @param int    $usage     Current usage
     * @param int    $limit     Quota limit
     */
    public function logQuotaExceeded(int $userId, string $quotaType, int $usage, int $limit): void
    {
        $this->log(
            self::EVENT_QUOTA_EXCEEDED,
            self::SEVERITY_WARNING,
            "Quota exceeded: {$quotaType}",
            [
                'user_id' => $userId,
                'quota_type' => $quotaType,
                'usage' => $usage,
                'limit' => $limit,
            ],
        );
    }

    /**
     * Log suspicious activity.
     *
     * @param string $activityType Type of suspicious activity
     * @param string $description  Description
     * @param array  $metadata     Additional metadata
     */
    public function logSuspiciousActivity(
        string $activityType,
        string $description,
        array $metadata = [],
    ): void {
        $this->log(
            self::EVENT_SUSPICIOUS_ACTIVITY,
            self::SEVERITY_CRITICAL,
            "Suspicious activity: {$activityType} - {$description}",
            array_merge([
                'activity_type' => $activityType,
            ], $metadata),
        );
    }

    /**
     * Retrieve audit log entries.
     *
     * @param array $filters Filters (event_type, user_id, date_from, date_to, severity)
     * @param int   $limit   Maximum results
     * @param int   $offset  Offset for pagination
     *
     * @return array Audit log entries
     */
    public function getAuditLog(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        // Apply filters
        if (!empty($filters['event_type'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'event_type',
                    $queryBuilder->createNamedParameter($filters['event_type']),
                ),
            );
        }

        if (!empty($filters['user_id'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'user_id',
                    $queryBuilder->createNamedParameter((int)$filters['user_id']),
                ),
            );
        }

        if (!empty($filters['severity'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->gte(
                    'severity',
                    $queryBuilder->createNamedParameter((int)$filters['severity']),
                ),
            );
        }

        if (!empty($filters['date_from'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->gte(
                    'tstamp',
                    $queryBuilder->createNamedParameter(strtotime($filters['date_from'])),
                ),
            );
        }

        if (!empty($filters['date_to'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->lte(
                    'tstamp',
                    $queryBuilder->createNamedParameter(strtotime($filters['date_to'])),
                ),
            );
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * Cleanup old audit logs based on retention policy.
     *
     * @return int Number of deleted records
     */
    public function cleanupOldLogs(): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);

        $cutoffDate = time() - ($this->retentionDays * 86400);

        return $connection->delete(
            self::TABLE_NAME,
            ['tstamp' => ['<', $cutoffDate]],
        );
    }

    /**
     * Anonymize old audit logs (GDPR compliance).
     *
     * @return int Number of anonymized records
     */
    public function anonymizeOldLogs(): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);

        $cutoffDate = time() - ($this->anonymizeAfterDays * 86400);

        return $connection->update(
            self::TABLE_NAME,
            [
                'user_id' => 0,
                'ip_address' => '',
                'user_agent' => '',
                'anonymized' => 1,
            ],
            [
                'tstamp' => ['<', $cutoffDate],
                'anonymized' => 0,
            ],
        );
    }

    /**
     * Core logging method.
     *
     * @param string $eventType Event type constant
     * @param int    $severity  Severity level constant
     * @param string $message   Log message
     * @param array  $data      Additional data
     */
    private function log(string $eventType, int $severity, string $message, array $data = []): void
    {
        $user = $this->getBackendUser();

        $logData = [
            'event_type' => $eventType,
            'severity' => $severity,
            'message' => $message,
            'user_id' => $user ? (int)$user->user['uid'] : 0,
            'username' => $user ? $user->user['username'] : '',
            'ip_address' => GeneralUtility::getIndpEnv('REMOTE_ADDR'),
            'user_agent' => GeneralUtility::getIndpEnv('HTTP_USER_AGENT'),
            'data' => json_encode($data),
            'tstamp' => time(),
            'anonymized' => 0,
        ];

        // Write to database
        try {
            $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
            $connection->insert(self::TABLE_NAME, $logData);
        } catch (Exception $e) {
            // Fallback to TYPO3 logging if database insert fails
            $this->logger->error('Failed to write audit log to database', [
                'exception' => $e->getMessage(),
                'log_data' => $logData,
            ]);
        }

        // Also write to TYPO3 log system for critical events
        if ($severity >= self::SEVERITY_ERROR) {
            $this->logger->log(
                $this->mapSeverityToLogLevel($severity),
                $message,
                $data,
            );
        }
    }

    /**
     * Sanitize value for logging (remove sensitive data).
     *
     * @param mixed $value Value to sanitize
     *
     * @return string Sanitized value
     */
    private function sanitizeValue($value): string
    {
        if (is_string($value) && strlen($value) > 500) {
            return substr($value, 0, 500) . '... [truncated]';
        }

        if (is_array($value)) {
            // Remove sensitive keys
            $sensitiveKeys = ['password', 'api_key', 'secret', 'token', 'encryption_key'];
            foreach ($sensitiveKeys as $key) {
                if (isset($value[$key])) {
                    $value[$key] = '[REDACTED]';
                }
            }
            return json_encode($value);
        }

        return (string)$value;
    }

    /**
     * Map audit severity to TYPO3 log level.
     *
     * @param int $severity Audit severity
     *
     * @return int TYPO3 log level
     */
    private function mapSeverityToLogLevel(int $severity): int
    {
        return match ($severity) {
            self::SEVERITY_INFO => \TYPO3\CMS\Core\Log\LogLevel::INFO,
            self::SEVERITY_NOTICE => \TYPO3\CMS\Core\Log\LogLevel::NOTICE,
            self::SEVERITY_WARNING => \TYPO3\CMS\Core\Log\LogLevel::WARNING,
            self::SEVERITY_ERROR => \TYPO3\CMS\Core\Log\LogLevel::ERROR,
            self::SEVERITY_CRITICAL => \TYPO3\CMS\Core\Log\LogLevel::CRITICAL,
            default => \TYPO3\CMS\Core\Log\LogLevel::INFO,
        };
    }

    /**
     * Get current backend user.
     */
    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }

    /**
     * Get a fresh query builder instance.
     */
    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
    }
}
