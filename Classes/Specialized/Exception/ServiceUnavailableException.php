<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

/**
 * Exception thrown when a service is unavailable.
 *
 * This covers situations like:
 * - Service not configured (missing API key)
 * - External service is down
 * - Network connectivity issues
 * - Service maintenance
 */
final class ServiceUnavailableException extends SpecializedServiceException
{
    /**
     * Create exception for unconfigured service.
     *
     * @param string $service  The service identifier
     * @param string $provider The provider name (e.g., 'deepl', 'whisper')
     */
    public static function notConfigured(string $service, string $provider): self
    {
        return new self(
            sprintf('%s service is not configured (missing API key or credentials)', ucfirst($provider)),
            $service,
            [
                'reason' => 'not_configured',
                'provider' => $provider,
            ],
        );
    }

    /**
     * Create exception for service being down.
     *
     * @param string   $service    The service identifier
     * @param string   $provider   The provider name
     * @param int|null $httpStatus The HTTP status code if available
     */
    public static function serviceDown(
        string $service,
        string $provider,
        ?int $httpStatus = null,
    ): self {
        $message = sprintf('%s service is currently unavailable', ucfirst($provider));

        if ($httpStatus !== null) {
            $message .= sprintf(' (HTTP %d)', $httpStatus);
        }

        return new self(
            $message,
            $service,
            [
                'reason' => 'service_down',
                'provider' => $provider,
                'http_status' => $httpStatus,
            ],
        );
    }

    /**
     * Create exception for translator not found in registry.
     *
     * @param string $identifier The translator identifier that was not found
     */
    public static function translatorNotFound(string $identifier): self
    {
        return new self(
            sprintf('Translator "%s" not found in registry', $identifier),
            'translation',
            [
                'reason' => 'not_found',
                'identifier' => $identifier,
            ],
        );
    }
}
