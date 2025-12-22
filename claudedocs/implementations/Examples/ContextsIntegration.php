<?php

declare(strict_types=1);

namespace Netresearch\Contexts\Service;

use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Exception\LlmException;
use Psr\Log\LoggerInterface;

/**
 * Contexts Extension AI Service Integration Example
 *
 * Shows how contexts extension uses LlmServiceManager for:
 * - Natural language rule generation
 * - Rule validation and conflict detection
 * - Context suggestions based on content
 */
class ContextAiService
{
    public function __construct(
        private readonly LlmServiceManager $llm,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Generate context rules from natural language
     *
     * @param string $naturalLanguageDescription User's natural language input
     * @return array Generated rule configuration
     */
    public function generateRule(string $naturalLanguageDescription): array
    {
        $prompt = $this->buildRuleGenerationPrompt($naturalLanguageDescription);

        try {
            $response = $this->llm
                ->withOptions([
                    'response_format' => 'json',
                    'temperature' => 0.3  // Lower for more deterministic output
                ])
                ->complete($prompt);

            $ruleData = json_decode($response->getContent(), true);

            return [
                'success' => true,
                'rule' => $ruleData,
                'metadata' => [
                    'confidence' => $ruleData['confidence'] ?? 0.8,
                    'tokens' => $response->getTotalTokens()
                ]
            ];
        } catch (LlmException $e) {
            $this->logger->error('Rule generation failed', [
                'input' => $naturalLanguageDescription,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'suggestion' => $e->getSuggestion()
            ];
        }
    }

    /**
     * Validate context rule and detect conflicts
     *
     * @param string $ruleExpression Rule expression to validate
     * @param array $existingRules Existing rules in system
     * @return array Validation report
     */
    public function validateRule(string $ruleExpression, array $existingRules = []): array
    {
        $prompt = <<<PROMPT
Validate this TYPO3 contexts rule expression:

Expression: {$ruleExpression}

Check for:
1. Syntax errors
2. Logical contradictions
3. Performance issues
4. Conflicts with existing rules

Existing rules:
{$this->formatExistingRules($existingRules)}

Respond in JSON format:
{
    "valid": true|false,
    "errors": ["error1", "error2"],
    "warnings": ["warning1"],
    "suggestions": ["improvement1"],
    "conflicts": [{"rule": "...", "reason": "..."}],
    "score": 0-100
}
PROMPT;

        try {
            $response = $this->llm
                ->withOptions(['response_format' => 'json', 'temperature' => 0.2])
                ->complete($prompt);

            return json_decode($response->getContent(), true);
        } catch (LlmException $e) {
            $this->logger->error('Rule validation failed', [
                'expression' => $ruleExpression,
                'error' => $e->getMessage()
            ]);

            return [
                'valid' => false,
                'errors' => ['Validation service unavailable'],
                'warnings' => [],
                'suggestions' => []
            ];
        }
    }

    /**
     * Suggest contexts based on page content
     *
     * @param int $pageUid Page UID
     * @param string $pageContent Page content for analysis
     * @return array Context suggestions
     */
    public function suggestContexts(int $pageUid, string $pageContent): array
    {
        $prompt = <<<PROMPT
Analyze this page content and suggest appropriate TYPO3 contexts:

Content: {$pageContent}

Available context types:
- domain: Match specific domains
- ip: Match IP addresses/ranges
- header: Match HTTP headers (User-Agent, Accept-Language, etc.)
- getparam: Match URL query parameters
- combination: Boolean logic combinations

Suggest relevant contexts with reasoning.

Respond in JSON format:
{
    "suggestions": [
        {
            "type": "...",
            "configuration": {...},
            "reasoning": "...",
            "confidence": 0.0-1.0
        }
    ]
}
PROMPT;

        try {
            $response = $this->llm
                ->withOptions(['response_format' => 'json', 'temperature' => 0.5])
                ->complete($prompt);

            $suggestions = json_decode($response->getContent(), true);

            return [
                'success' => true,
                'suggestions' => $suggestions['suggestions'] ?? [],
                'metadata' => [
                    'page_uid' => $pageUid,
                    'tokens' => $response->getTotalTokens()
                ]
            ];
        } catch (LlmException $e) {
            $this->logger->error('Context suggestion failed', [
                'page_uid' => $pageUid,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'suggestions' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate human-readable documentation for complex rules
     *
     * @param string $ruleExpression Rule expression
     * @param array $contextDefinitions Context definitions
     * @return string Human-readable documentation
     */
    public function generateDocumentation(
        string $ruleExpression,
        array $contextDefinitions
    ): string {
        $prompt = <<<PROMPT
Generate clear, non-technical documentation for this TYPO3 contexts rule:

Rule: {$ruleExpression}

Context definitions:
{$this->formatContextDefinitions($contextDefinitions)}

Generate documentation that explains:
1. What this rule does (in plain language)
2. When it applies
3. Typical use cases
4. Performance impact estimate

Keep it concise and user-friendly.
PROMPT;

        try {
            $response = $this->llm
                ->withCache(true, 604800)  // Cache for 7 days
                ->complete($prompt);

            return $response->getContent();
        } catch (LlmException $e) {
            $this->logger->error('Documentation generation failed', [
                'expression' => $ruleExpression,
                'error' => $e->getMessage()
            ]);

            return 'Documentation generation failed.';
        }
    }

    /**
     * Build rule generation prompt with context
     */
    private function buildRuleGenerationPrompt(string $description): string
    {
        return <<<PROMPT
You are a TYPO3 contexts rule generator. Convert natural language to context configuration.

Available context types:
1. domain: Match HTTP_HOST (supports wildcards like .example.com)
2. ip: Match client IP (CIDR notation, wildcards, IPv4/IPv6)
3. header: Match HTTP headers (name + optional values)
4. getparam: Match URL query parameters (name + optional values)
5. combination: Boolean expressions (&&, ||, !, (), XOR)

User request: "{$description}"

Respond with JSON:
{
    "contextType": "domain|ip|header|getparam|combination",
    "configuration": {
        // Type-specific configuration
    },
    "explanation": "What this rule does in plain language",
    "confidence": 0.0-1.0,
    "alternatives": [
        {"type": "...", "reasoning": "..."}
    ]
}

Examples:
- "Show to mobile users" → header type, User-Agent matching
- "Germany and France only" → combination with Accept-Language
- "Admin office only" → ip type with CIDR range
PROMPT;
    }

    /**
     * Format existing rules for prompt
     */
    private function formatExistingRules(array $rules): string
    {
        if (empty($rules)) {
            return 'None';
        }

        $formatted = [];
        foreach ($rules as $rule) {
            $formatted[] = "- {$rule['name']}: {$rule['expression']}";
        }

        return implode("\n", $formatted);
    }

    /**
     * Format context definitions for prompt
     */
    private function formatContextDefinitions(array $definitions): string
    {
        $formatted = [];
        foreach ($definitions as $key => $def) {
            $formatted[] = "- {$key}: {$def['description']}";
        }

        return implode("\n", $formatted);
    }
}
