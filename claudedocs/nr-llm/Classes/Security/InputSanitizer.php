<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Security;

use InvalidArgumentException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Input sanitization for LLM prompts.
 *
 * Security Features:
 * - Prompt injection detection and prevention
 * - Content filtering before sending to LLM
 * - PII detection and masking (optional)
 * - Maximum length enforcement
 * - Dangerous pattern detection
 *
 * Attack Vectors Prevented:
 * - System prompt override attempts
 * - Instruction injection
 * - Context manipulation
 * - Jailbreak attempts
 * - Data exfiltration via prompts
 */
class InputSanitizer implements SingletonInterface
{
    private AuditLogger $auditLogger;
    private array $config;

    // Prompt injection patterns (OWASP LLM01)
    private const INJECTION_PATTERNS = [
        // System prompt override attempts
        '/ignore\s+(previous|above|all)\s+(instructions|rules|prompts)/i',
        '/forget\s+(everything|all|previous)/i',
        '/disregard\s+(previous|above|all)/i',

        // Role manipulation
        '/you\s+are\s+now\s+(a|an)/i',
        '/act\s+as\s+(a|an)/i',
        '/pretend\s+(you|to)\s+(are|be)/i',
        '/roleplay\s+as/i',

        // Instruction injection
        '/new\s+instructions?:/i',
        '/updated\s+instructions?:/i',
        '/system\s*:\s*/i',
        '/assistant\s*:\s*/i',

        // Delimiter attempts
        '/---+\s*end\s+of/i',
        '/\[\/?(system|user|assistant)\]/i',
        '/<\|?(system|user|assistant)\|>/i',

        // Context escape attempts
        '/\n\n\n+.*?(system|instructions?):/i',
        '/```\s*(system|instructions?)/i',
    ];

    // PII patterns (simple detection)
    private const PII_PATTERNS = [
        'email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
        'phone' => '/\b(\+\d{1,3}[\s-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/',
        'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
        'credit_card' => '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/',
        'ip_address' => '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',
    ];

    public function __construct(?AuditLogger $auditLogger = null)
    {
        $this->auditLogger = $auditLogger ?? GeneralUtility::makeInstance(AuditLogger::class);

        // Load configuration
        $this->config = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['security']['sanitization'] ?? [];
        $this->config = array_merge([
            'enablePromptInjectionFilter' => true,
            'enablePiiDetection' => false,
            'maxPromptLength' => 50000,
            'blockOnInjectionDetection' => true,
            'logSuspiciousPrompts' => true,
            'piiMaskingChar' => '*',
        ], $this->config);
    }

    /**
     * Sanitize user prompt before sending to LLM.
     *
     * @param string $prompt  User prompt
     * @param array  $options Sanitization options
     *
     * @return SanitizationResult Result with sanitized prompt and warnings
     */
    public function sanitizePrompt(string $prompt, array $options = []): SanitizationResult
    {
        $result = new SanitizationResult($prompt);

        // Check maximum length
        if (strlen($prompt) > $this->config['maxPromptLength']) {
            $result->addWarning(
                'prompt_too_long',
                "Prompt exceeds maximum length of {$this->config['maxPromptLength']} characters",
            );

            if ($options['truncate'] ?? false) {
                $prompt = substr($prompt, 0, $this->config['maxPromptLength']);
                $result->setSanitizedPrompt($prompt);
            } else {
                $result->setBlocked(true);
                return $result;
            }
        }

        // Check for prompt injection attempts
        if ($this->config['enablePromptInjectionFilter']) {
            $injectionDetected = $this->detectPromptInjection($prompt);

            if (!empty($injectionDetected)) {
                $result->addWarning(
                    'prompt_injection_detected',
                    'Potential prompt injection detected',
                    $injectionDetected,
                );

                if ($this->config['blockOnInjectionDetection']) {
                    $result->setBlocked(true);

                    if ($this->config['logSuspiciousPrompts']) {
                        $this->auditLogger->logSuspiciousActivity(
                            'prompt_injection',
                            'Blocked prompt injection attempt',
                            [
                                'patterns_matched' => $injectionDetected,
                                'prompt_length' => strlen($prompt),
                            ],
                        );
                    }

                    return $result;
                }
            }
        }

        // Detect and optionally mask PII
        if ($this->config['enablePiiDetection']) {
            $piiDetected = $this->detectPii($prompt);

            if (!empty($piiDetected)) {
                $result->addWarning(
                    'pii_detected',
                    'Personally identifiable information detected',
                    $piiDetected,
                );

                if ($options['maskPii'] ?? false) {
                    $prompt = $this->maskPii($prompt, $piiDetected);
                    $result->setSanitizedPrompt($prompt);
                    $result->addWarning(
                        'pii_masked',
                        'PII has been masked in the prompt',
                    );
                }
            }
        }

        // Strip dangerous HTML/script tags
        $prompt = $this->stripDangerousContent($prompt);
        $result->setSanitizedPrompt($prompt);

        return $result;
    }

    /**
     * Sanitize system prompt (stricter validation).
     *
     * @param string $systemPrompt System prompt
     *
     * @return SanitizationResult Result with sanitized system prompt
     */
    public function sanitizeSystemPrompt(string $systemPrompt): SanitizationResult
    {
        $result = new SanitizationResult($systemPrompt);

        // System prompts should not contain user-controlled delimiters
        $dangerousPatterns = [
            '/\[\/?(user|assistant)\]/i',
            '/<\|?(user|assistant)\|>/i',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $systemPrompt)) {
                $result->addWarning(
                    'dangerous_delimiter',
                    'System prompt contains potentially dangerous delimiters',
                );
                $result->setBlocked(true);
                return $result;
            }
        }

        // Strip HTML/script
        $systemPrompt = $this->stripDangerousContent($systemPrompt);
        $result->setSanitizedPrompt($systemPrompt);

        return $result;
    }

    /**
     * Detect prompt injection attempts.
     *
     * @param string $prompt Prompt to analyze
     *
     * @return array Array of matched patterns
     */
    private function detectPromptInjection(string $prompt): array
    {
        $matches = [];

        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $prompt, $match)) {
                $matches[] = [
                    'pattern' => $pattern,
                    'matched_text' => $match[0],
                ];
            }
        }

        // Additional heuristic checks

        // Check for excessive newlines (context escape attempt)
        if (preg_match('/\n{5,}/', $prompt)) {
            $matches[] = [
                'pattern' => 'excessive_newlines',
                'matched_text' => 'Multiple consecutive newlines detected',
            ];
        }

        // Check for delimiter stacking
        if (preg_match('/(---+|===+|\*\*\*+){3,}/', $prompt)) {
            $matches[] = [
                'pattern' => 'delimiter_stacking',
                'matched_text' => 'Suspicious delimiter pattern',
            ];
        }

        // Check for base64-encoded payloads (sophisticated attacks)
        if (preg_match('/[A-Za-z0-9+\/]{50,}={0,2}/', $prompt, $match)) {
            if (base64_decode($match[0], true) !== false) {
                $decoded = base64_decode($match[0]);
                // Check if decoded content looks suspicious
                if (preg_match('/system|instructions?|ignore/i', $decoded)) {
                    $matches[] = [
                        'pattern' => 'base64_injection',
                        'matched_text' => 'Suspicious base64-encoded content',
                    ];
                }
            }
        }

        return $matches;
    }

    /**
     * Detect PII in prompt.
     *
     * @param string $prompt Prompt to analyze
     *
     * @return array Detected PII types and locations
     */
    private function detectPii(string $prompt): array
    {
        $detected = [];

        foreach (self::PII_PATTERNS as $type => $pattern) {
            if (preg_match_all($pattern, $prompt, $matches)) {
                $detected[$type] = $matches[0];
            }
        }

        return $detected;
    }

    /**
     * Mask PII in prompt.
     *
     * @param string $prompt      Prompt containing PII
     * @param array  $piiDetected Detected PII from detectPii()
     *
     * @return string Prompt with masked PII
     */
    private function maskPii(string $prompt, array $piiDetected): string
    {
        $maskChar = $this->config['piiMaskingChar'];

        foreach ($piiDetected as $type => $matches) {
            foreach ($matches as $match) {
                // Keep first and last 2 characters visible for context
                $length = strlen($match);
                if ($length > 4) {
                    $masked = substr($match, 0, 2) . str_repeat($maskChar, $length - 4) . substr($match, -2);
                } else {
                    $masked = str_repeat($maskChar, $length);
                }

                $prompt = str_replace($match, $masked, $prompt);
            }
        }

        return $prompt;
    }

    /**
     * Strip potentially dangerous content.
     *
     * @param string $content Content to sanitize
     *
     * @return string Sanitized content
     */
    private function stripDangerousContent(string $content): string
    {
        // Remove script tags
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);

        // Remove event handlers
        $content = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);

        // Remove javascript: protocol
        $content = preg_replace('/javascript:/i', '', $content);

        // Remove data: protocol (can be used for XSS)
        $content = preg_replace('/data:text\/html/i', '', $content);

        return $content;
    }

    /**
     * Validate user input length for specific field types.
     *
     * @param string $input     User input
     * @param string $fieldType Field type (e.g., 'model_name', 'temperature')
     *
     * @return bool True if valid
     */
    public function validateInputLength(string $input, string $fieldType): bool
    {
        $maxLengths = [
            'model_name' => 100,
            'provider_name' => 50,
            'temperature' => 10,
            'max_tokens' => 10,
            'prompt_name' => 255,
        ];

        $maxLength = $maxLengths[$fieldType] ?? 1000;

        return strlen($input) <= $maxLength;
    }

    /**
     * Sanitize model configuration parameters.
     *
     * @param array $config Model configuration
     *
     * @throws InvalidArgumentException
     *
     * @return array Sanitized configuration
     */
    public function sanitizeModelConfig(array $config): array
    {
        $sanitized = [];

        // Validate temperature (0.0 - 2.0)
        if (isset($config['temperature'])) {
            $temp = (float)$config['temperature'];
            if ($temp < 0 || $temp > 2.0) {
                throw new InvalidArgumentException('Temperature must be between 0 and 2.0', 1703003000);
            }
            $sanitized['temperature'] = $temp;
        }

        // Validate max_tokens (positive integer, reasonable limit)
        if (isset($config['max_tokens'])) {
            $tokens = (int)$config['max_tokens'];
            if ($tokens < 1 || $tokens > 200000) {
                throw new InvalidArgumentException('max_tokens must be between 1 and 200000', 1703003001);
            }
            $sanitized['max_tokens'] = $tokens;
        }

        // Validate top_p (0.0 - 1.0)
        if (isset($config['top_p'])) {
            $topP = (float)$config['top_p'];
            if ($topP < 0 || $topP > 1.0) {
                throw new InvalidArgumentException('top_p must be between 0 and 1.0', 1703003002);
            }
            $sanitized['top_p'] = $topP;
        }

        // Validate frequency_penalty (-2.0 - 2.0)
        if (isset($config['frequency_penalty'])) {
            $penalty = (float)$config['frequency_penalty'];
            if ($penalty < -2.0 || $penalty > 2.0) {
                throw new InvalidArgumentException('frequency_penalty must be between -2.0 and 2.0', 1703003003);
            }
            $sanitized['frequency_penalty'] = $penalty;
        }

        // Validate presence_penalty (-2.0 - 2.0)
        if (isset($config['presence_penalty'])) {
            $penalty = (float)$config['presence_penalty'];
            if ($penalty < -2.0 || $penalty > 2.0) {
                throw new InvalidArgumentException('presence_penalty must be between -2.0 and 2.0', 1703003004);
            }
            $sanitized['presence_penalty'] = $penalty;
        }

        // Sanitize string fields
        $stringFields = ['model', 'provider', 'user_identifier'];
        foreach ($stringFields as $field) {
            if (isset($config[$field])) {
                $sanitized[$field] = strip_tags((string)$config[$field]);
            }
        }

        return $sanitized;
    }
}
