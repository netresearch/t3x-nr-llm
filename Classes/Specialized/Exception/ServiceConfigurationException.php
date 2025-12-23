<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

/**
 * Exception thrown when a service configuration is invalid.
 *
 * This covers situations like:
 * - Invalid API key format
 * - Authentication failures
 * - Missing required configuration options
 * - Invalid configuration values
 */
final class ServiceConfigurationException extends SpecializedServiceException
{
    /**
     * Create exception for invalid API key.
     *
     * @param string $service The service identifier
     * @param string $provider The provider name
     */
    public static function invalidApiKey(string $service, string $provider): self
    {
        return new self(
            sprintf('%s API authentication failed - please verify your API key', ucfirst($provider)),
            $service,
            [
                'reason' => 'invalid_api_key',
                'provider' => $provider,
            ]
        );
    }

    /**
     * Create exception for missing required configuration.
     *
     * @param string $service The service identifier
     * @param string $provider The provider name
     * @param string $option The missing configuration option
     */
    public static function missingOption(string $service, string $provider, string $option): self
    {
        return new self(
            sprintf('%s requires configuration option "%s"', ucfirst($provider), $option),
            $service,
            [
                'reason' => 'missing_option',
                'provider' => $provider,
                'option' => $option,
            ]
        );
    }

    /**
     * Create exception for invalid configuration value.
     *
     * @param string $service The service identifier
     * @param string $provider The provider name
     * @param string $option The configuration option
     * @param string $reason The reason why the value is invalid
     */
    public static function invalidValue(
        string $service,
        string $provider,
        string $option,
        string $reason
    ): self {
        return new self(
            sprintf('%s configuration error for "%s": %s', ucfirst($provider), $option, $reason),
            $service,
            [
                'reason' => 'invalid_value',
                'provider' => $provider,
                'option' => $option,
            ]
        );
    }
}
