<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Image;

use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Option\ImageGenerationOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * DALL-E image generation service.
 *
 * Provides AI image generation via OpenAI's DALL-E API.
 *
 * Features:
 * - DALL-E 2 and DALL-E 3 models
 * - Multiple sizes (256x256 to 1792x1024)
 * - HD quality option (DALL-E 3)
 * - Vivid and natural styles
 * - Image editing and variations (DALL-E 2)
 *
 * @see https://platform.openai.com/docs/guides/images
 */
final class DallEImageService
{
    private const API_URL = 'https://api.openai.com/v1/images';
    private const DEFAULT_MODEL = 'dall-e-3';
    private const DEFAULT_SIZE = '1024x1024';

    /**
     * Model capabilities.
     */
    private const MODEL_CAPABILITIES = [
        'dall-e-2' => [
            'sizes' => ['256x256', '512x512', '1024x1024'],
            'max_prompt_length' => 1000,
            'supports_quality' => false,
            'supports_style' => false,
            'supports_editing' => true,
            'supports_variations' => true,
        ],
        'dall-e-3' => [
            'sizes' => ['1024x1024', '1792x1024', '1024x1792'],
            'max_prompt_length' => 4000,
            'supports_quality' => true,
            'supports_style' => true,
            'supports_editing' => false,
            'supports_variations' => false,
        ],
    ];

    private string $apiKey = '';
    private string $baseUrl = '';
    private int $timeout = 120;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly UsageTrackerService $usageTracker,
        private readonly LoggerInterface $logger,
    ) {
        $this->loadConfiguration();
    }

    /**
     * Check if service is available.
     */
    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Generate an image from a text prompt.
     *
     * @param string $prompt Text description of desired image
     * @param ImageGenerationOptions|array<string, mixed> $options Generation options
     * @return ImageGenerationResult Generation result
     * @throws ServiceUnavailableException
     */
    public function generate(
        string $prompt,
        ImageGenerationOptions|array $options = []
    ): ImageGenerationResult {
        $this->ensureAvailable();

        $options = $options instanceof ImageGenerationOptions
            ? $options
            : ImageGenerationOptions::fromArray($options);

        $optionsArray = $options->toArray();
        $model = $optionsArray['model'] ?? self::DEFAULT_MODEL;

        // Validate prompt length
        $this->validatePrompt($prompt, $model);

        // Build request payload
        $payload = $this->buildGeneratePayload($prompt, $optionsArray);

        // Send request
        $response = $this->sendRequest('generations', $payload);

        // Parse response
        $data = $response['data'][0] ?? [];

        // Track usage
        $this->usageTracker->trackUsage('image', 'dall-e:' . $model, [
            'size' => $optionsArray['size'] ?? self::DEFAULT_SIZE,
            'quality' => $optionsArray['quality'] ?? 'standard',
        ]);

        return new ImageGenerationResult(
            url: $data['url'] ?? '',
            base64: $data['b64_json'] ?? null,
            prompt: $prompt,
            revisedPrompt: $data['revised_prompt'] ?? null,
            model: $model,
            size: $optionsArray['size'] ?? self::DEFAULT_SIZE,
            provider: 'dall-e',
            metadata: [
                'quality' => $optionsArray['quality'] ?? 'standard',
                'style' => $optionsArray['style'] ?? 'vivid',
            ]
        );
    }

    /**
     * Generate multiple images from a text prompt.
     *
     * Note: DALL-E 3 only supports n=1, multiple calls will be made.
     *
     * @param string $prompt Text description of desired image
     * @param int $count Number of images to generate (1-10 for DALL-E 2, any for DALL-E 3)
     * @param ImageGenerationOptions|array<string, mixed> $options Generation options
     * @return array<int, ImageGenerationResult> Generation results
     */
    public function generateMultiple(
        string $prompt,
        int $count = 1,
        ImageGenerationOptions|array $options = []
    ): array {
        $this->ensureAvailable();

        $options = $options instanceof ImageGenerationOptions
            ? $options
            : ImageGenerationOptions::fromArray($options);

        $optionsArray = $options->toArray();
        $model = $optionsArray['model'] ?? self::DEFAULT_MODEL;

        // DALL-E 3 only supports n=1, need multiple requests
        if ($model === 'dall-e-3') {
            $results = [];
            for ($i = 0; $i < $count; $i++) {
                $results[] = $this->generate($prompt, $options);
            }
            return $results;
        }

        // DALL-E 2 supports n up to 10
        $count = min($count, 10);

        $this->validatePrompt($prompt, $model);

        $payload = $this->buildGeneratePayload($prompt, $optionsArray);
        $payload['n'] = $count;

        $response = $this->sendRequest('generations', $payload);

        $results = [];
        foreach ($response['data'] ?? [] as $data) {
            $results[] = new ImageGenerationResult(
                url: $data['url'] ?? '',
                base64: $data['b64_json'] ?? null,
                prompt: $prompt,
                revisedPrompt: $data['revised_prompt'] ?? null,
                model: $model,
                size: $optionsArray['size'] ?? self::DEFAULT_SIZE,
                provider: 'dall-e',
                metadata: [
                    'quality' => $optionsArray['quality'] ?? 'standard',
                    'style' => $optionsArray['style'] ?? 'vivid',
                ]
            );
        }

        // Track usage
        $this->usageTracker->trackUsage('image', 'dall-e:' . $model, [
            'size' => $optionsArray['size'] ?? self::DEFAULT_SIZE,
            'count' => count($results),
        ]);

        return $results;
    }

    /**
     * Create variations of an image (DALL-E 2 only).
     *
     * @param string $imagePath Path to source image (PNG, max 4MB, square)
     * @param int $count Number of variations (1-10)
     * @param string $size Output size
     * @return array<int, ImageGenerationResult> Variation results
     * @throws ServiceUnavailableException
     */
    public function createVariations(
        string $imagePath,
        int $count = 1,
        string $size = '1024x1024'
    ): array {
        $this->ensureAvailable();

        $this->validateImageFile($imagePath);

        $count = min(max($count, 1), 10);

        $response = $this->sendMultipartRequest('variations', $imagePath, null, [
            'n' => (string) $count,
            'size' => $size,
            'response_format' => 'url',
        ]);

        $results = [];
        foreach ($response['data'] ?? [] as $data) {
            $results[] = new ImageGenerationResult(
                url: $data['url'] ?? '',
                base64: $data['b64_json'] ?? null,
                prompt: '[variation of uploaded image]',
                revisedPrompt: null,
                model: 'dall-e-2',
                size: $size,
                provider: 'dall-e',
                metadata: ['type' => 'variation']
            );
        }

        $this->usageTracker->trackUsage('image', 'dall-e:variations', [
            'size' => $size,
            'count' => count($results),
        ]);

        return $results;
    }

    /**
     * Edit an image with a prompt (DALL-E 2 only).
     *
     * @param string $imagePath Path to source image (PNG, max 4MB, square)
     * @param string $prompt Description of the edit
     * @param string|null $maskPath Path to mask image (transparent areas will be edited)
     * @param string $size Output size
     * @return ImageGenerationResult Edit result
     * @throws ServiceUnavailableException
     */
    public function edit(
        string $imagePath,
        string $prompt,
        ?string $maskPath = null,
        string $size = '1024x1024'
    ): ImageGenerationResult {
        $this->ensureAvailable();

        $this->validateImageFile($imagePath);
        if ($maskPath !== null) {
            $this->validateImageFile($maskPath);
        }

        $response = $this->sendMultipartRequest('edits', $imagePath, $maskPath, [
            'prompt' => $prompt,
            'size' => $size,
            'response_format' => 'url',
        ]);

        $data = $response['data'][0] ?? [];

        $this->usageTracker->trackUsage('image', 'dall-e:edit', [
            'size' => $size,
        ]);

        return new ImageGenerationResult(
            url: $data['url'] ?? '',
            base64: $data['b64_json'] ?? null,
            prompt: $prompt,
            revisedPrompt: null,
            model: 'dall-e-2',
            size: $size,
            provider: 'dall-e',
            metadata: ['type' => 'edit']
        );
    }

    /**
     * Get available models.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAvailableModels(): array
    {
        return self::MODEL_CAPABILITIES;
    }

    /**
     * Get supported sizes for a model.
     *
     * @return array<int, string>
     */
    public function getSupportedSizes(string $model = 'dall-e-3'): array
    {
        return self::MODEL_CAPABILITIES[$model]['sizes'] ?? ['1024x1024'];
    }

    /**
     * Load configuration from extension settings.
     */
    private function loadConfiguration(): void
    {
        try {
            $config = $this->extensionConfiguration->get('nr_llm');

            $this->apiKey = (string)($config['providers']['openai']['apiKey'] ?? '');
            $this->baseUrl = (string)($config['image']['dalle']['baseUrl'] ?? self::API_URL);
            $this->timeout = (int)($config['image']['dalle']['timeout'] ?? 120);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to load DALL-E configuration', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ensure service is available.
     *
     * @throws ServiceUnavailableException
     */
    private function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw ServiceUnavailableException::notConfigured('image', 'dall-e');
        }
    }

    /**
     * Validate prompt for model.
     *
     * @throws ServiceUnavailableException
     */
    private function validatePrompt(string $prompt, string $model): void
    {
        if (empty(trim($prompt))) {
            throw new ServiceUnavailableException(
                'Prompt cannot be empty',
                'image',
                ['provider' => 'dall-e']
            );
        }

        $maxLength = self::MODEL_CAPABILITIES[$model]['max_prompt_length'] ?? 4000;

        if (mb_strlen($prompt) > $maxLength) {
            throw new ServiceUnavailableException(
                sprintf('Prompt exceeds maximum length of %d characters for %s', $maxLength, $model),
                'image',
                ['provider' => 'dall-e', 'length' => mb_strlen($prompt)]
            );
        }
    }

    /**
     * Validate image file for editing/variations.
     *
     * @throws ServiceUnavailableException
     */
    private function validateImageFile(string $path): void
    {
        if (!file_exists($path)) {
            throw new ServiceUnavailableException(
                sprintf('Image file not found: %s', $path),
                'image',
                ['provider' => 'dall-e']
            );
        }

        $fileSize = filesize($path);
        if ($fileSize === false || $fileSize > 4 * 1024 * 1024) {
            throw new ServiceUnavailableException(
                'Image file must be less than 4MB',
                'image',
                ['provider' => 'dall-e']
            );
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension !== 'png') {
            throw new ServiceUnavailableException(
                'Image must be a PNG file',
                'image',
                ['provider' => 'dall-e']
            );
        }
    }

    /**
     * Build generation request payload.
     *
     * @return array<string, mixed>
     */
    private function buildGeneratePayload(string $prompt, array $options): array
    {
        $model = $options['model'] ?? self::DEFAULT_MODEL;

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $options['size'] ?? self::DEFAULT_SIZE,
            'response_format' => $options['response_format'] ?? 'url',
        ];

        // DALL-E 3 specific options
        if ($model === 'dall-e-3') {
            if (isset($options['quality'])) {
                $payload['quality'] = $options['quality'];
            }
            if (isset($options['style'])) {
                $payload['style'] = $options['style'];
            }
        }

        return $payload;
    }

    /**
     * Send JSON request.
     *
     * @return array<string, mixed>
     * @throws ServiceUnavailableException
     */
    private function sendRequest(string $endpoint, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json');

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        return $this->executeRequest($request);
    }

    /**
     * Send multipart request for image upload.
     *
     * @return array<string, mixed>
     * @throws ServiceUnavailableException
     */
    private function sendMultipartRequest(
        string $endpoint,
        string $imagePath,
        ?string $maskPath,
        array $fields
    ): array {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $boundary = 'dalle' . uniqid();
        $body = '';

        // Add image file
        $imageContent = file_get_contents($imagePath);
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"image\"; filename=\"image.png\"\r\n";
        $body .= "Content-Type: image/png\r\n\r\n";
        $body .= $imageContent . "\r\n";

        // Add mask file if provided
        if ($maskPath !== null) {
            $maskContent = file_get_contents($maskPath);
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"mask\"; filename=\"mask.png\"\r\n";
            $body .= "Content-Type: image/png\r\n\r\n";
            $body .= $maskContent . "\r\n";
        }

        // Add other fields
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= $value . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=' . $boundary);

        $request = $request->withBody($this->streamFactory->createStream($body));

        return $this->executeRequest($request);
    }

    /**
     * Execute HTTP request.
     *
     * @return array<string, mixed>
     * @throws ServiceUnavailableException
     */
    private function executeRequest(\Psr\Http\Message\RequestInterface $request): array
    {
        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $responseBody = (string) $response->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
            }

            $error = json_decode($responseBody, true) ?? [];
            $errorMessage = $error['error']['message'] ?? 'Unknown DALL-E API error';

            $this->logger->error('DALL-E API error', [
                'status_code' => $statusCode,
                'error' => $errorMessage,
            ]);

            throw match ($statusCode) {
                401, 403 => ServiceConfigurationException::invalidApiKey('image', 'dall-e'),
                429 => new ServiceUnavailableException('DALL-E API rate limit exceeded', 'image', ['provider' => 'dall-e']),
                400 => new ServiceUnavailableException('DALL-E API error: ' . $errorMessage, 'image', ['provider' => 'dall-e', 'type' => 'validation']),
                default => new ServiceUnavailableException('DALL-E API error: ' . $errorMessage, 'image', ['provider' => 'dall-e']),
            };
        } catch (ServiceUnavailableException|ServiceConfigurationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('DALL-E API connection error', [
                'exception' => $e->getMessage(),
            ]);

            throw new ServiceUnavailableException(
                'Failed to connect to DALL-E API: ' . $e->getMessage(),
                'image',
                ['provider' => 'dall-e'],
                0,
                $e
            );
        }
    }
}
