<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

use RuntimeException;
use Throwable;

/**
 * Abstract base exception for all specialized AI services.
 *
 * Provides common properties for service identification and context
 * that help with debugging and error reporting.
 */
abstract class SpecializedServiceException extends RuntimeException
{
    /**
     * @param string                    $message  The exception message
     * @param string                    $service  The service identifier (e.g., 'translation', 'speech', 'image')
     * @param array<string, mixed>|null $context  Additional context for debugging
     * @param int                       $code     The exception code
     * @param Throwable|null            $previous The previous throwable for exception chaining
     */
    public function __construct(
        string $message,
        public readonly string $service,
        public readonly ?array $context = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get a formatted error message including service context.
     */
    public function getDetailedMessage(): string
    {
        $details = sprintf('[%s] %s', $this->service, $this->getMessage());

        if ($this->context !== null && $this->context !== []) {
            $details .= ' | Context: ' . json_encode($this->context, JSON_THROW_ON_ERROR);
        }

        return $details;
    }
}
