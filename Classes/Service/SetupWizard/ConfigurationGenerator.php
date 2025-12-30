<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\DTO\SuggestedConfiguration;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;

/**
 * Generates configuration suggestions using the connected LLM.
 *
 * Uses the newly connected LLM to generate optimal configuration presets
 * for common use cases.
 */
final readonly class ConfigurationGenerator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are an expert at configuring LLM integrations. Generate practical configuration presets for common business use cases.

        For each configuration, provide:
        1. A unique identifier (lowercase, hyphenated, e.g., "blog-summarizer")
        2. A human-readable name
        3. A clear description of the use case
        4. An effective system prompt tailored for that use case
        5. Recommended temperature (0.0-2.0)
        6. Recommended max tokens

        Return a JSON array of configurations. Example format:
        [
          {
            "identifier": "content-summarizer",
            "name": "Content Summarizer",
            "description": "Summarizes articles, documents, and long-form content",
            "systemPrompt": "You are a professional content summarizer...",
            "temperature": 0.3,
            "maxTokens": 2048
          }
        ]

        Generate 4-5 practical configurations that would be useful for a typical business website (TYPO3 CMS).
        Focus on: content creation, translation, summarization, customer support, and code/technical assistance.
        PROMPT;

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Generate configuration suggestions using the LLM.
     *
     * @param array<DiscoveredModel> $models Available models
     *
     * @return array<SuggestedConfiguration>
     */
    public function generate(
        DetectedProvider $provider,
        string $apiKey,
        array $models,
    ): array {
        // Find the best model to use for generation
        $generationModel = $this->selectGenerationModel($models);

        if ($generationModel === null) {
            return $this->getFallbackConfigurations($models);
        }

        try {
            $response = $this->callLLM(
                provider: $provider,
                apiKey: $apiKey,
                model: $generationModel,
                prompt: $this->buildPrompt($models),
            );

            $configurations = $this->parseResponse($response, $generationModel->modelId);

            return $configurations !== [] ? $configurations : $this->getFallbackConfigurations($models);
        } catch (Throwable) {
            return $this->getFallbackConfigurations($models);
        }
    }

    /**
     * Select the best model for configuration generation.
     *
     * @param array<DiscoveredModel> $models
     */
    private function selectGenerationModel(array $models): ?DiscoveredModel
    {
        // Prefer recommended models, then by context length
        $candidates = array_filter($models, fn(DiscoveredModel $m) => $m->recommended);

        if ($candidates === []) {
            $candidates = $models;
        }

        if ($candidates === []) {
            return null;
        }

        // Sort by context length (prefer larger context)
        usort(
            $candidates,
            fn(DiscoveredModel $a, DiscoveredModel $b)
            => $b->contextLength <=> $a->contextLength,
        );

        return $candidates[0];
    }

    /**
     * Build the prompt for configuration generation.
     *
     * @param array<DiscoveredModel> $models
     */
    private function buildPrompt(array $models): string
    {
        $modelList = array_map(
            fn(DiscoveredModel $m) => sprintf(
                '- %s (%s): %s',
                $m->name,
                $m->modelId,
                $m->description,
            ),
            array_slice($models, 0, 5),
        );

        return sprintf(
            "Available models:\n%s\n\nGenerate configuration presets that work well with these models.",
            implode("\n", $modelList),
        );
    }

    /**
     * Call the LLM API to generate configurations.
     */
    private function callLLM(
        DetectedProvider $provider,
        string $apiKey,
        DiscoveredModel $model,
        string $prompt,
    ): string {
        $body = match ($provider->adapterType) {
            'anthropic' => $this->buildAnthropicRequest($model->modelId, $prompt),
            'gemini' => $this->buildGeminiRequest($prompt),
            default => $this->buildOpenAIRequest($model->modelId, $prompt),
        };

        $endpoint = $this->getCompletionEndpoint($provider);

        $request = $this->requestFactory->createRequest('POST', $endpoint)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));

        // Add auth header based on provider
        $request = match ($provider->adapterType) {
            'anthropic' => $request
                ->withHeader('x-api-key', $apiKey)
                ->withHeader('anthropic-version', '2023-06-01'),
            'gemini' => $request, // Key is in URL
            default => $request->withHeader('Authorization', 'Bearer ' . $apiKey),
        };

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('LLM API error: ' . $response->getStatusCode(), 6587111580);
        }

        $data = json_decode($response->getBody()->getContents(), true);

        if (!is_array($data)) {
            return '';
        }

        /** @var array<string, mixed> $data */
        return $this->extractContentFromResponse($data, $provider->adapterType);
    }

    /**
     * Extract content from LLM API response based on provider type.
     *
     * @param array<string, mixed> $data
     */
    private function extractContentFromResponse(array $data, string $adapterType): string
    {
        return match ($adapterType) {
            'anthropic' => $this->extractAnthropicContent($data),
            'gemini' => $this->extractGeminiContent($data),
            default => $this->extractOpenAIContent($data),
        };
    }

    /**
     * Extract content from Anthropic API response.
     *
     * @param array<string, mixed> $data
     */
    private function extractAnthropicContent(array $data): string
    {
        $content = $data['content'] ?? [];
        if (!is_array($content) || $content === []) {
            return '';
        }
        $firstBlock = $content[0] ?? [];
        if (!is_array($firstBlock)) {
            return '';
        }
        $text = $firstBlock['text'] ?? '';
        return is_string($text) ? $text : '';
    }

    /**
     * Extract content from Gemini API response.
     *
     * @param array<string, mixed> $data
     */
    private function extractGeminiContent(array $data): string
    {
        $candidates = $data['candidates'] ?? [];
        if (!is_array($candidates) || $candidates === []) {
            return '';
        }
        $firstCandidate = $candidates[0] ?? [];
        if (!is_array($firstCandidate)) {
            return '';
        }
        $content = $firstCandidate['content'] ?? [];
        if (!is_array($content)) {
            return '';
        }
        $parts = $content['parts'] ?? [];
        if (!is_array($parts) || $parts === []) {
            return '';
        }
        $firstPart = $parts[0] ?? [];
        if (!is_array($firstPart)) {
            return '';
        }
        $text = $firstPart['text'] ?? '';
        return is_string($text) ? $text : '';
    }

    /**
     * Extract content from OpenAI-compatible API response.
     *
     * @param array<string, mixed> $data
     */
    private function extractOpenAIContent(array $data): string
    {
        $choices = $data['choices'] ?? [];
        if (!is_array($choices) || $choices === []) {
            return '';
        }
        $firstChoice = $choices[0] ?? [];
        if (!is_array($firstChoice)) {
            return '';
        }
        $message = $firstChoice['message'] ?? [];
        if (!is_array($message)) {
            return '';
        }
        $content = $message['content'] ?? '';
        return is_string($content) ? $content : '';
    }

    /**
     * Get completion endpoint for provider.
     */
    private function getCompletionEndpoint(DetectedProvider $provider): string
    {
        return match ($provider->adapterType) {
            'anthropic' => $provider->endpoint . '/v1/messages',
            'gemini' => $provider->endpoint . '/v1/models/gemini-3-flash:generateContent',
            default => $provider->endpoint . '/v1/chat/completions',
        };
    }

    /**
     * Build OpenAI-format request body.
     *
     * @return array<string, mixed>
     */
    private function buildOpenAIRequest(string $model, string $prompt): array
    {
        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'response_format' => ['type' => 'json_object'],
        ];
    }

    /**
     * Build Anthropic request body.
     *
     * @return array<string, mixed>
     */
    private function buildAnthropicRequest(string $model, string $prompt): array
    {
        return [
            'model' => $model,
            'system' => self::SYSTEM_PROMPT,
            'messages' => [
                ['role' => 'user', 'content' => $prompt . "\n\nRespond with valid JSON only."],
            ],
            'temperature' => 0.7,
            'max_tokens' => 4096,
        ];
    }

    /**
     * Build Gemini request body.
     *
     * @return array<string, mixed>
     */
    private function buildGeminiRequest(string $prompt): array
    {
        return [
            'contents' => [
                [
                    'parts' => [
                        ['text' => self::SYSTEM_PROMPT . "\n\n" . $prompt . "\n\nRespond with valid JSON only."],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 4096,
                'responseMimeType' => 'application/json',
            ],
        ];
    }

    /**
     * Parse LLM response into configuration DTOs.
     *
     * @return array<SuggestedConfiguration>
     */
    private function parseResponse(string $response, string $modelId): array
    {
        // Try to extract JSON from response
        $json = $this->extractJson($response);

        if ($json === null) {
            return [];
        }

        $configs = [];

        // Handle both array format (list) and object with configs key (associative)
        $items = array_is_list($json) ? $json : ($json['configurations'] ?? $json['configs'] ?? []);

        if (!is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $identifier = $item['identifier'] ?? null;
            $name = $item['name'] ?? null;

            if (!is_string($identifier) || !is_string($name)) {
                continue;
            }

            $description = $item['description'] ?? '';
            $systemPrompt = $item['systemPrompt'] ?? $item['system_prompt'] ?? '';
            $temperature = $item['temperature'] ?? 0.7;
            $maxTokens = $item['maxTokens'] ?? $item['max_tokens'] ?? 4096;

            $configs[] = new SuggestedConfiguration(
                identifier: $this->sanitizeIdentifier($identifier),
                name: $name,
                description: is_string($description) ? $description : '',
                systemPrompt: is_string($systemPrompt) ? $systemPrompt : '',
                recommendedModelId: $modelId,
                temperature: is_numeric($temperature) ? (float)$temperature : 0.7,
                maxTokens: is_numeric($maxTokens) ? (int)$maxTokens : 4096,
            );
        }

        return $configs;
    }

    /**
     * Extract JSON from response (handles markdown code blocks).
     *
     * @return array<int|string, mixed>|null
     */
    private function extractJson(string $response): ?array
    {
        // Try direct parse
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try to extract from markdown code block
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Try to find JSON array/object in text
        if (preg_match('/(\[[\s\S]*\]|\{[\s\S]*\})/', $response, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Sanitize identifier to valid format.
     */
    private function sanitizeIdentifier(string $identifier): string
    {
        // Convert to lowercase, replace spaces/underscores with hyphens
        $identifier = strtolower(trim($identifier));
        $identifier = (string)preg_replace('/[\s_]+/', '-', $identifier);
        $identifier = (string)preg_replace('/[^a-z0-9-]/', '', $identifier);
        $identifier = (string)preg_replace('/-+/', '-', $identifier);

        return trim($identifier, '-');
    }

    /**
     * Get fallback configurations when LLM generation fails.
     *
     * @param array<DiscoveredModel> $models
     *
     * @return array<SuggestedConfiguration>
     */
    private function getFallbackConfigurations(array $models): array
    {
        $recommendedModel = '';
        foreach ($models as $model) {
            if ($model->recommended) {
                $recommendedModel = $model->modelId;
                break;
            }
        }

        if ($recommendedModel === '' && $models !== []) {
            $recommendedModel = $models[0]->modelId;
        }

        return [
            new SuggestedConfiguration(
                identifier: 'content-assistant',
                name: 'Content Assistant',
                description: 'General-purpose content creation and editing',
                systemPrompt: 'You are a professional content writer and editor. Help create, improve, and edit content for websites. Be clear, engaging, and match the requested tone and style.',
                recommendedModelId: $recommendedModel,
                temperature: 0.7,
                maxTokens: 4096,
            ),
            new SuggestedConfiguration(
                identifier: 'content-summarizer',
                name: 'Content Summarizer',
                description: 'Summarizes articles, documents, and long-form content',
                systemPrompt: 'You are a professional summarizer. Create clear, concise summaries that capture the key points and essential information. Maintain accuracy while reducing length.',
                recommendedModelId: $recommendedModel,
                temperature: 0.3,
                maxTokens: 2048,
            ),
            new SuggestedConfiguration(
                identifier: 'translator',
                name: 'Translator',
                description: 'Translates content between languages',
                systemPrompt: 'You are a professional translator. Translate content accurately while preserving meaning, tone, and cultural context. Maintain formatting and structure.',
                recommendedModelId: $recommendedModel,
                temperature: 0.2,
                maxTokens: 8192,
            ),
            new SuggestedConfiguration(
                identifier: 'seo-optimizer',
                name: 'SEO Optimizer',
                description: 'Optimizes content for search engines',
                systemPrompt: 'You are an SEO expert. Analyze and improve content for search engine optimization. Suggest keywords, meta descriptions, headings, and content improvements while maintaining readability.',
                recommendedModelId: $recommendedModel,
                temperature: 0.5,
                maxTokens: 4096,
            ),
            new SuggestedConfiguration(
                identifier: 'code-assistant',
                name: 'Code Assistant',
                description: 'Helps with programming and technical tasks',
                systemPrompt: 'You are an expert programmer. Help with code review, debugging, and implementation. Provide clear explanations and follow best practices. Focus on clean, maintainable code.',
                recommendedModelId: $recommendedModel,
                temperature: 0.2,
                maxTokens: 8192,
            ),
        ];
    }
}
