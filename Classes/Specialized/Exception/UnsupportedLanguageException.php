<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

/**
 * Exception thrown when a language is not supported by a service.
 *
 * Contains additional context about which language was requested
 * and optionally which languages are supported.
 */
final class UnsupportedLanguageException extends SpecializedServiceException
{
    /**
     * Create exception for an unsupported language.
     *
     * @param string                  $language           The unsupported language code
     * @param string                  $service            The service identifier
     * @param string                  $direction          Either 'source' or 'target'
     * @param array<int, string>|null $supportedLanguages List of supported language codes
     */
    public static function forLanguage(
        string $language,
        string $service,
        string $direction = 'target',
        ?array $supportedLanguages = null,
    ): self {
        $context = [
            'language' => $language,
            'direction' => $direction,
        ];

        if ($supportedLanguages !== null) {
            $context['supported'] = $supportedLanguages;
        }

        return new self(
            sprintf('Language "%s" is not supported as %s language', $language, $direction),
            $service,
            $context,
        );
    }
}
