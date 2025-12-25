<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

/**
 * Exception thrown when a service quota or rate limit is exceeded.
 *
 * Contains information about the quota type and when it might reset.
 */
final class ServiceQuotaExceededException extends SpecializedServiceException
{
    /**
     * Create exception for rate limit exceeded.
     *
     * @param string   $service           The service identifier
     * @param int|null $retryAfterSeconds Seconds until the limit resets
     */
    public static function rateLimitExceeded(
        string $service,
        ?int $retryAfterSeconds = null,
    ): self {
        $message = 'Rate limit exceeded';

        if ($retryAfterSeconds !== null) {
            $message .= sprintf('. Retry after %d seconds', $retryAfterSeconds);
        }

        return new self(
            $message,
            $service,
            [
                'type' => 'rate_limit',
                'retry_after' => $retryAfterSeconds,
            ],
        );
    }

    /**
     * Create exception for usage quota exceeded.
     *
     * @param string         $service   The service identifier
     * @param string         $quotaType The type of quota (e.g., 'characters', 'requests', 'tokens')
     * @param int|float|null $limit     The quota limit
     * @param int|float|null $used      The amount used
     */
    public static function quotaExceeded(
        string $service,
        string $quotaType,
        int|float|null $limit = null,
        int|float|null $used = null,
    ): self {
        $message = sprintf('%s quota exceeded', ucfirst($quotaType));

        if ($limit !== null && $used !== null) {
            $message .= sprintf(' (used: %s, limit: %s)', $used, $limit);
        }

        return new self(
            $message,
            $service,
            [
                'type' => 'quota',
                'quota_type' => $quotaType,
                'limit' => $limit,
                'used' => $used,
            ],
        );
    }
}
