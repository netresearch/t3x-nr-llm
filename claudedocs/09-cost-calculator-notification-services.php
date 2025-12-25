<?php

declare(strict_types=1);

/**
 * Cost Calculator & Notification Services.
 *
 * Part 2: Pricing management, cost calculation, and notification system
 *
 * @package Netresearch\NrLlm
 */

namespace Netresearch\NrLlm\Service;

use Exception;
use PDO;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// ============================================================================
// COST CALCULATOR SERVICE
// ============================================================================

/**
 * AI Cost Calculator with Provider Pricing Management.
 *
 * Features:
 * - Multi-provider pricing tables
 * - Version-controlled pricing history
 * - Automatic cost calculation
 * - Currency conversion support
 * - Pricing update notifications
 */
class CostCalculator
{
    private const TABLE_PRICING = 'tx_nrllm_pricing';

    public function __construct(
        private readonly FrontendInterface $cache,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
        private readonly array $configuration,
    ) {}

    /**
     * Calculate cost for AI request.
     *
     * @param string $provider         Provider name
     * @param string $model            Model name
     * @param int    $promptTokens     Input tokens
     * @param int    $completionTokens Output tokens
     * @param array  $additionalCosts  Additional costs (e.g., images)
     *
     * @return array Cost breakdown
     */
    public function calculateCost(
        string $provider,
        string $model,
        int $promptTokens,
        int $completionTokens,
        array $additionalCosts = [],
    ): array {
        $pricing = $this->getCurrentPricing($provider, $model);

        if (!$pricing) {
            $this->logger->warning("No pricing data found for $provider/$model");
            return [
                'input_cost' => 0.0,
                'output_cost' => 0.0,
                'additional_costs' => 0.0,
                'total' => 0.0,
                'currency' => 'USD',
                'pricing_version' => 0,
                'estimated' => true,
            ];
        }

        // Calculate token costs (per 1M tokens)
        $inputCost = ($promptTokens / 1_000_000) * $pricing['input_cost_per_1m'];
        $outputCost = ($completionTokens / 1_000_000) * $pricing['output_cost_per_1m'];

        // Calculate additional costs (images, audio, etc.)
        $additionalCostTotal = 0.0;
        foreach ($additionalCosts as $type => $count) {
            $additionalCostTotal += $this->calculateAdditionalCost($provider, $model, $type, $count);
        }

        $total = $inputCost + $outputCost + $additionalCostTotal;

        return [
            'input_cost' => round($inputCost, 6),
            'output_cost' => round($outputCost, 6),
            'additional_costs' => round($additionalCostTotal, 6),
            'total' => round($total, 6),
            'currency' => $pricing['currency'],
            'pricing_version' => $pricing['version'],
            'estimated' => false,
            'breakdown' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'input_rate_per_1m' => $pricing['input_cost_per_1m'],
                'output_rate_per_1m' => $pricing['output_cost_per_1m'],
            ],
        ];
    }

    /**
     * Get current pricing for provider/model.
     */
    private function getCurrentPricing(string $provider, string $model): ?array
    {
        $cacheKey = "pricing:current:$provider:$model";
        $cached = $this->cache->get($cacheKey);

        if ($cached !== false) {
            return $cached;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_PRICING);

        $pricing = $queryBuilder
            ->select('*')
            ->from(self::TABLE_PRICING)
            ->where(
                $queryBuilder->expr()->eq('provider', $queryBuilder->createNamedParameter($provider)),
                $queryBuilder->expr()->eq('model', $queryBuilder->createNamedParameter($model)),
                $queryBuilder->expr()->eq('effective_until', $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!$pricing) {
            // Try to find latest pricing
            $pricing = $queryBuilder
                ->select('*')
                ->from(self::TABLE_PRICING)
                ->where(
                    $queryBuilder->expr()->eq('provider', $queryBuilder->createNamedParameter($provider)),
                    $queryBuilder->expr()->eq('model', $queryBuilder->createNamedParameter($model)),
                )
                ->orderBy('effective_from', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        }

        if ($pricing) {
            $this->cache->set($cacheKey, $pricing, 86400); // Cache for 24 hours
        }

        return $pricing ?: null;
    }

    /**
     * Calculate additional costs (images, audio, etc.).
     */
    private function calculateAdditionalCost(string $provider, string $model, string $type, int $count): float
    {
        // Provider-specific additional costs
        $additionalRates = [
            'openai' => [
                'gpt-4-vision-preview' => ['images_per_1000' => 0.25],
                'dall-e-3' => ['images' => 0.040], // per image
                'whisper-1' => ['audio_minutes' => 0.006], // per minute
            ],
            'anthropic' => [
                'claude-3-opus-20240229' => ['images_per_1000' => 0.30],
                'claude-3-sonnet-20240229' => ['images_per_1000' => 0.15],
            ],
            'google' => [
                'gemini-pro-vision' => ['images_per_1000' => 0.25],
            ],
        ];

        if (!isset($additionalRates[$provider][$model][$type])) {
            return 0.0;
        }

        $rate = $additionalRates[$provider][$model][$type];

        // Handle different rate types
        if (str_ends_with($type, '_per_1000')) {
            return ($count / 1000) * $rate;
        }

        return $count * $rate;
    }

    /**
     * Update pricing for provider/model.
     *
     * @param string $provider        Provider name
     * @param string $model           Model name
     * @param float  $inputCostPer1M  Input cost per 1M tokens
     * @param float  $outputCostPer1M Output cost per 1M tokens
     * @param string $currency        Currency code
     * @param string $source          Source URL for pricing
     *
     * @return int New pricing version
     */
    public function updatePricing(
        string $provider,
        string $model,
        float $inputCostPer1M,
        float $outputCostPer1M,
        string $currency = 'USD',
        string $source = '',
    ): int {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_PRICING);

        // Get current version
        $queryBuilder = $connection->createQueryBuilder();
        $currentVersion = (int)$queryBuilder
            ->select('MAX(version) as max_version')
            ->from(self::TABLE_PRICING)
            ->where(
                $queryBuilder->expr()->eq('provider', $queryBuilder->createNamedParameter($provider)),
                $queryBuilder->expr()->eq('model', $queryBuilder->createNamedParameter($model)),
            )
            ->executeQuery()
            ->fetchOne();

        $newVersion = $currentVersion + 1;

        // Mark current pricing as historical
        $connection->update(
            self::TABLE_PRICING,
            [
                'effective_until' => time(),
                'tstamp' => time(),
            ],
            [
                'provider' => $provider,
                'model' => $model,
                'effective_until' => 0,
            ],
        );

        // Insert new pricing
        $connection->insert(
            self::TABLE_PRICING,
            [
                'provider' => $provider,
                'model' => $model,
                'input_cost_per_1m' => $inputCostPer1M,
                'output_cost_per_1m' => $outputCostPer1M,
                'currency' => $currency,
                'version' => $newVersion,
                'effective_from' => time(),
                'effective_until' => 0,
                'source' => $source,
                'tstamp' => time(),
                'crdate' => time(),
            ],
        );

        // Clear cache
        $this->cache->remove("pricing:current:$provider:$model");

        $this->logger->info("Updated pricing for $provider/$model to version $newVersion");

        return $newVersion;
    }

    /**
     * Import initial pricing data.
     */
    public function importInitialPricing(): void
    {
        $initialPricing = $this->getInitialPricingData();

        foreach ($initialPricing as $provider => $models) {
            foreach ($models as $model => $pricing) {
                // Check if already exists
                $existing = $this->getCurrentPricing($provider, $model);

                if (!$existing) {
                    $this->updatePricing(
                        provider: $provider,
                        model: $model,
                        inputCostPer1M: $pricing['input_per_1m'],
                        outputCostPer1M: $pricing['output_per_1m'],
                        currency: 'USD',
                        source: $pricing['source'] ?? '',
                    );
                }
            }
        }
    }

    /**
     * Get initial pricing data (as of 2024).
     */
    private function getInitialPricingData(): array
    {
        return [
            'openai' => [
                'gpt-4-turbo' => [
                    'input_per_1m' => 10.00,
                    'output_per_1m' => 30.00,
                    'source' => 'https://openai.com/pricing',
                ],
                'gpt-4' => [
                    'input_per_1m' => 30.00,
                    'output_per_1m' => 60.00,
                    'source' => 'https://openai.com/pricing',
                ],
                'gpt-3.5-turbo' => [
                    'input_per_1m' => 0.50,
                    'output_per_1m' => 1.50,
                    'source' => 'https://openai.com/pricing',
                ],
                'gpt-4-vision-preview' => [
                    'input_per_1m' => 10.00,
                    'output_per_1m' => 30.00,
                    'source' => 'https://openai.com/pricing',
                ],
            ],
            'anthropic' => [
                'claude-3-opus-20240229' => [
                    'input_per_1m' => 15.00,
                    'output_per_1m' => 75.00,
                    'source' => 'https://www.anthropic.com/pricing',
                ],
                'claude-3-sonnet-20240229' => [
                    'input_per_1m' => 3.00,
                    'output_per_1m' => 15.00,
                    'source' => 'https://www.anthropic.com/pricing',
                ],
                'claude-3-haiku-20240307' => [
                    'input_per_1m' => 0.25,
                    'output_per_1m' => 1.25,
                    'source' => 'https://www.anthropic.com/pricing',
                ],
            ],
            'google' => [
                'gemini-pro' => [
                    'input_per_1m' => 0.50,
                    'output_per_1m' => 1.50,
                    'source' => 'https://ai.google.dev/pricing',
                ],
                'gemini-pro-vision' => [
                    'input_per_1m' => 0.50,
                    'output_per_1m' => 1.50,
                    'source' => 'https://ai.google.dev/pricing',
                ],
                'gemini-1.5-pro' => [
                    'input_per_1m' => 3.50,
                    'output_per_1m' => 10.50,
                    'source' => 'https://ai.google.dev/pricing',
                ],
            ],
            'deepl' => [
                'deepl-api' => [
                    'input_per_1m' => 20.00, // DeepL charges per character, converted estimate
                    'output_per_1m' => 0.00,
                    'source' => 'https://www.deepl.com/pro-api',
                ],
            ],
        ];
    }

    /**
     * Convert cost to different currency.
     */
    public function convertCurrency(float $amount, string $fromCurrency, string $toCurrency): float
    {
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        // Get exchange rate (cached)
        $cacheKey = "exchange_rate:$fromCurrency:$toCurrency";
        $rate = $this->cache->get($cacheKey);

        if ($rate === false) {
            $rate = $this->fetchExchangeRate($fromCurrency, $toCurrency);
            $this->cache->set($cacheKey, $rate, 86400); // Cache for 24 hours
        }

        return $amount * $rate;
    }

    /**
     * Fetch current exchange rate from API.
     */
    private function fetchExchangeRate(string $fromCurrency, string $toCurrency): float
    {
        $apiKey = $this->configuration['pricing']['exchangeRateApiKey'] ?? '';

        if (!$apiKey) {
            $this->logger->warning('No exchange rate API key configured');
            return 1.0; // Fallback to 1:1
        }

        try {
            $url = "https://api.exchangerate-api.com/v4/latest/$fromCurrency";
            $response = file_get_contents($url);
            $data = json_decode($response, true);

            return $data['rates'][$toCurrency] ?? 1.0;
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch exchange rate: ' . $e->getMessage());
            return 1.0;
        }
    }

    /**
     * Estimate cost for prompt before execution.
     */
    public function estimateCost(
        string $provider,
        string $model,
        string $prompt,
        int $maxOutputTokens = 1000,
    ): array {
        // Estimate token count (rough approximation: 1 token ≈ 4 characters)
        $estimatedPromptTokens = (int)(strlen($prompt) / 4);

        return $this->calculateCost(
            provider: $provider,
            model: $model,
            promptTokens: $estimatedPromptTokens,
            completionTokens: $maxOutputTokens,
        );
    }

    /**
     * Get pricing history for provider/model.
     */
    public function getPricingHistory(string $provider, string $model): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_PRICING);

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE_PRICING)
            ->where(
                $queryBuilder->expr()->eq('provider', $queryBuilder->createNamedParameter($provider)),
                $queryBuilder->expr()->eq('model', $queryBuilder->createNamedParameter($model)),
            )
            ->orderBy('version', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Get all current pricing (for admin UI).
     */
    public function getAllCurrentPricing(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_PRICING);

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE_PRICING)
            ->where(
                $queryBuilder->expr()->eq('effective_until', $queryBuilder->createNamedParameter(0, PDO::PARAM_INT)),
            )
            ->orderBy('provider')
            ->addOrderBy('model')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

// ============================================================================
// NOTIFICATION SERVICE
// ============================================================================

/**
 * User and Admin Notification System.
 *
 * Features:
 * - Quota warning/alert/exceeded notifications
 * - Rate limit notifications
 * - Email delivery
 * - Backend notification integration
 * - Notification history tracking
 * - Configurable thresholds
 */
class NotificationService
{
    private const TABLE_NOTIFICATIONS = 'tx_nrllm_notifications';

    public const TYPE_QUOTA_WARNING = 'quota_warning';
    public const TYPE_QUOTA_EXCEEDED = 'quota_exceeded';
    public const TYPE_RATE_LIMITED = 'rate_limited';
    public const TYPE_PRICING_UPDATE = 'pricing_update';
    public const TYPE_ADMIN_ALERT = 'admin_alert';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
        private readonly array $configuration,
    ) {}

    /**
     * Send quota warning notification.
     *
     * @param array  $quota Quota record
     * @param string $level 'warning' or 'alert'
     */
    public function sendQuotaWarning(array $quota, string $level = 'warning'): void
    {
        $percentUsed = ($quota['quota_used'] / $quota['quota_limit']) * 100;
        $severity = $level === 'alert' ? self::SEVERITY_WARNING : self::SEVERITY_INFO;

        $subject = sprintf(
            'AI Quota %s: %d%% Used',
            ucfirst($level),
            (int)$percentUsed,
        );

        $message = $this->buildQuotaWarningMessage($quota, $percentUsed);

        // Determine recipient
        $recipientId = match ($quota['scope']) {
            'user' => $quota['scope_id'],
            'group' => $this->getGroupAdminId($quota['scope_id']),
            default => $this->getSiteAdminId(),
        };

        $this->sendNotification(
            type: self::TYPE_QUOTA_WARNING,
            severity: $severity,
            recipientType: 'user',
            recipientId: $recipientId,
            subject: $subject,
            message: $message,
            data: [
                'quota_uid' => $quota['uid'],
                'percent_used' => $percentUsed,
                'level' => $level,
            ],
        );
    }

    /**
     * Send quota exceeded notification.
     */
    public function sendQuotaExceeded(array $quota): void
    {
        $subject = sprintf(
            'AI Quota Exceeded: %s',
            ucfirst($quota['quota_type']),
        );

        $message = $this->buildQuotaExceededMessage($quota);

        // Notify user
        $this->sendNotification(
            type: self::TYPE_QUOTA_EXCEEDED,
            severity: self::SEVERITY_CRITICAL,
            recipientType: 'user',
            recipientId: $quota['scope_id'],
            subject: $subject,
            message: $message,
            data: ['quota_uid' => $quota['uid']],
        );

        // Also notify admin
        $this->sendNotification(
            type: self::TYPE_ADMIN_ALERT,
            severity: self::SEVERITY_WARNING,
            recipientType: 'admin',
            recipientId: 0,
            subject: "User Quota Exceeded: {$quota['scope']} {$quota['scope_id']}",
            message: $message,
            data: ['quota_uid' => $quota['uid']],
        );
    }

    /**
     * Send rate limit notification.
     */
    public function sendRateLimitNotification(
        int $userId,
        string $scope,
        int $currentUsage,
        int $limit,
        int $retryAfter,
    ): void {
        $subject = 'AI Request Rate Limit Reached';

        $message = sprintf(
            "You have reached your AI request rate limit.\n\n"
            . "Usage: %d / %d requests\n"
            . "Scope: %s\n"
            . "Please wait %d seconds before trying again.\n\n"
            . 'Your quota will reset at: %s',
            $currentUsage,
            $limit,
            $scope,
            $retryAfter,
            date('Y-m-d H:i:s', time() + $retryAfter),
        );

        $this->sendNotification(
            type: self::TYPE_RATE_LIMITED,
            severity: self::SEVERITY_INFO,
            recipientType: 'user',
            recipientId: $userId,
            subject: $subject,
            message: $message,
            data: [
                'scope' => $scope,
                'current_usage' => $currentUsage,
                'limit' => $limit,
                'retry_after' => $retryAfter,
            ],
        );
    }

    /**
     * Send admin alert.
     */
    public function sendAdminAlert(string $subject, string $message, array $data = []): void
    {
        $this->sendNotification(
            type: self::TYPE_ADMIN_ALERT,
            severity: self::SEVERITY_WARNING,
            recipientType: 'admin',
            recipientId: 0,
            subject: $subject,
            message: $message,
            data: $data,
        );
    }

    /**
     * Core notification sending method.
     */
    private function sendNotification(
        string $type,
        string $severity,
        string $recipientType,
        int $recipientId,
        string $subject,
        string $message,
        array $data = [],
    ): void {
        // Store notification in database
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NOTIFICATIONS);

        $connection->insert(
            self::TABLE_NOTIFICATIONS,
            [
                'notification_type' => $type,
                'severity' => $severity,
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
                'subject' => $subject,
                'message' => $message,
                'notification_data' => json_encode($data),
                'sent' => 0,
                'quota_uid' => $data['quota_uid'] ?? 0,
                'tstamp' => time(),
                'crdate' => time(),
            ],
        );

        $notificationUid = (int)$connection->lastInsertId();

        // Send email if configured
        if ($this->shouldSendEmail($type, $severity)) {
            $this->sendEmail($recipientType, $recipientId, $subject, $message);

            // Mark as sent
            $connection->update(
                self::TABLE_NOTIFICATIONS,
                [
                    'sent' => 1,
                    'sent_at' => time(),
                ],
                ['uid' => $notificationUid],
            );
        }

        // Log notification
        $this->logger->info('Notification sent', [
            'type' => $type,
            'severity' => $severity,
            'recipient' => "$recipientType:$recipientId",
        ]);
    }

    /**
     * Check if email should be sent for notification type.
     */
    private function shouldSendEmail(string $type, string $severity): bool
    {
        // Always send critical notifications
        if ($severity === self::SEVERITY_CRITICAL) {
            return true;
        }

        // Check configuration
        $emailConfig = $this->configuration['notifications']['email'] ?? [];

        return $emailConfig[$type] ?? false;
    }

    /**
     * Send email notification.
     */
    private function sendEmail(string $recipientType, int $recipientId, string $subject, string $message): void
    {
        try {
            $email = $this->getRecipientEmail($recipientType, $recipientId);

            if (!$email) {
                $this->logger->warning("No email address found for $recipientType:$recipientId");
                return;
            }

            $mail = GeneralUtility::makeInstance(MailMessage::class);
            $mail
                ->to($email)
                ->subject('[AI Usage] ' . $subject)
                ->text($message)
                ->send();

        } catch (Exception $e) {
            $this->logger->error('Failed to send email: ' . $e->getMessage());
        }
    }

    /**
     * Get recipient email address.
     */
    private function getRecipientEmail(string $recipientType, int $recipientId): ?string
    {
        if ($recipientType === 'admin') {
            return $this->configuration['notifications']['adminEmail'] ?? null;
        }

        // Get user email from TYPO3 backend user table
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');

        return $queryBuilder
            ->select('email')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($recipientId, PDO::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne() ?: null;
    }

    /**
     * Build quota warning message.
     */
    private function buildQuotaWarningMessage(array $quota, float $percentUsed): string
    {
        $resetTime = date('Y-m-d H:i:s', $quota['period_end']);

        return sprintf(
            "Your AI usage quota is at %d%%.\n\n"
            . "Details:\n"
            . "- Type: %s\n"
            . "- Used: %s / %s\n"
            . "- Period: %s\n"
            . "- Resets: %s\n\n"
            . 'Consider reducing usage or contact your administrator to increase your quota.',
            (int)$percentUsed,
            ucfirst($quota['quota_type']),
            $this->formatQuotaValue($quota['quota_used'], $quota['quota_type']),
            $this->formatQuotaValue($quota['quota_limit'], $quota['quota_type']),
            ucfirst($quota['quota_period']),
            $resetTime,
        );
    }

    /**
     * Build quota exceeded message.
     */
    private function buildQuotaExceededMessage(array $quota): string
    {
        $resetTime = date('Y-m-d H:i:s', $quota['period_end']);

        return sprintf(
            "Your AI usage quota has been exceeded.\n\n"
            . "Details:\n"
            . "- Type: %s\n"
            . "- Used: %s / %s\n"
            . "- Period: %s\n"
            . "- Resets: %s\n\n"
            . "Your AI features are temporarily disabled until the quota resets.\n"
            . 'Contact your administrator if you need to increase your quota.',
            ucfirst($quota['quota_type']),
            $this->formatQuotaValue($quota['quota_used'], $quota['quota_type']),
            $this->formatQuotaValue($quota['quota_limit'], $quota['quota_type']),
            ucfirst($quota['quota_period']),
            $resetTime,
        );
    }

    /**
     * Format quota value for display.
     */
    private function formatQuotaValue(float $value, string $quotaType): string
    {
        return match ($quotaType) {
            'requests' => number_format($value, 0) . ' requests',
            'tokens' => number_format($value, 0) . ' tokens',
            'cost' => '$' . number_format($value, 2),
            default => (string)$value,
        };
    }

    /**
     * Get group admin ID.
     */
    private function getGroupAdminId(int $groupId): int
    {
        // Implementation depends on TYPO3 group structure
        // This is a placeholder
        return 0;
    }

    /**
     * Get site admin ID.
     */
    private function getSiteAdminId(): int
    {
        // Get first admin user
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');

        return (int)$queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('admin', $queryBuilder->createNamedParameter(1, PDO::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Get notification history.
     */
    public function getNotificationHistory(array $filters = []): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NOTIFICATIONS);

        $query = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NOTIFICATIONS);

        if (isset($filters['recipient_type'])) {
            $query->andWhere(
                $queryBuilder->expr()->eq('recipient_type', $queryBuilder->createNamedParameter($filters['recipient_type'])),
            );
        }

        if (isset($filters['recipient_id'])) {
            $query->andWhere(
                $queryBuilder->expr()->eq('recipient_id', $queryBuilder->createNamedParameter($filters['recipient_id'], PDO::PARAM_INT)),
            );
        }

        if (isset($filters['type'])) {
            $query->andWhere(
                $queryBuilder->expr()->eq('notification_type', $queryBuilder->createNamedParameter($filters['type'])),
            );
        }

        $query->orderBy('crdate', 'DESC');

        return $query->executeQuery()->fetchAllAssociative();
    }
}

// ============================================================================
// EXCEPTION CLASSES
// ============================================================================

class RateLimitExceededException extends Exception
{
    public function __construct(
        public readonly int $currentUsage,
        public readonly int $limit,
        public readonly int $retryAfter,
        public readonly string $scope,
    ) {
        parent::__construct(
            "Rate limit exceeded. Used $currentUsage/$limit. Retry after $retryAfter seconds.",
            429,
        );
    }

    public function toArray(): array
    {
        return [
            'error' => 'rate_limit_exceeded',
            'message' => $this->getMessage(),
            'current_usage' => $this->currentUsage,
            'limit' => $this->limit,
            'retry_after' => $this->retryAfter,
            'reset_at' => time() + $this->retryAfter,
            'scope' => $this->scope,
        ];
    }
}

class QuotaExceededException extends Exception
{
    public function __construct(
        public readonly string $quotaType,
        public readonly float $used,
        public readonly float $limit,
        public readonly int $resetAt,
    ) {
        parent::__construct(
            "Quota exceeded for $quotaType. Used $used/$limit. Resets at " . date('Y-m-d H:i:s', $resetAt),
            402,
        );
    }

    public function toArray(): array
    {
        return [
            'error' => 'quota_exceeded',
            'message' => $this->getMessage(),
            'quota_type' => $this->quotaType,
            'used' => $this->used,
            'limit' => $this->limit,
            'reset_at' => $this->resetAt,
            'reset_time' => date('Y-m-d H:i:s', $this->resetAt),
        ];
    }
}
