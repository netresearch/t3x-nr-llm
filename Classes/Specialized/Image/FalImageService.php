<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Image;

use Exception;
use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * FAL.ai image generation service.
 *
 * Provides access to various AI image generation models hosted on FAL.ai,
 * including Flux, Stable Diffusion, and other open-source models.
 *
 * Features:
 * - Multiple model support (Flux, Stable Diffusion XL, etc.)
 * - Various sizes and aspect ratios
 * - Image-to-image generation
 * - ControlNet support
 * - Fast inference with queue-based processing
 *
 * @see https://fal.ai/docs
 */
final class FalImageService
{
    private const string API_URL = 'https://fal.run';
    private const string QUEUE_API_URL = 'https://queue.fal.run';

    /** Default model endpoints. */
    private const array MODELS = [
        'flux-pro' => 'fal-ai/flux-pro',
        'flux-dev' => 'fal-ai/flux/dev',
        'flux-schnell' => 'fal-ai/flux/schnell',
        'sdxl' => 'fal-ai/fast-sdxl',
        'sd3' => 'fal-ai/stable-diffusion-v3-medium',
        'playground' => 'fal-ai/playground-v25',
    ];

    /** Standard aspect ratios. */
    private const array ASPECT_RATIOS = [
        'square' => '1:1',
        'landscape' => '16:9',
        'portrait' => '9:16',
        'wide' => '21:9',
        'photo' => '4:3',
        'photo-portrait' => '3:4',
    ];

    private string $apiKey = '';
    private string $baseUrl = '';
    private int $timeout = 120;
    private int $pollInterval = 1000; // milliseconds

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
     * Generate an image using specified model.
     *
     * @param string               $prompt  Text description of desired image
     * @param string               $model   Model identifier (flux-pro, flux-dev, flux-schnell, sdxl, sd3, playground)
     * @param array<string, mixed> $options Generation options:
     *                                      - image_size: string Size specification (e.g., "1024x1024", "landscape_16_9")
     *                                      - num_images: int Number of images (1-4)
     *                                      - guidance_scale: float CFG scale (1-20)
     *                                      - num_inference_steps: int Steps (1-50)
     *                                      - seed: int Random seed for reproducibility
     *                                      - negative_prompt: string Things to avoid
     *                                      - enable_safety_checker: bool Safety filter
     *
     * @throws ServiceUnavailableException
     *
     * @return ImageGenerationResult Generation result
     */
    public function generate(
        string $prompt,
        string $model = 'flux-schnell',
        array $options = [],
    ): ImageGenerationResult {
        $this->ensureAvailable();

        $modelEndpoint = $this->resolveModelEndpoint($model);

        // Build request payload
        $payload = $this->buildGeneratePayload($prompt, $options);

        // Send request (synchronous for fast models, queue for slow ones)
        $usesQueue = $this->modelUsesQueue($model);
        $response = $usesQueue
            ? $this->sendQueueRequest($modelEndpoint, $payload)
            : $this->sendRequest($modelEndpoint, $payload);

        // Parse response
        $images = $response['images'] ?? [];
        /** @var array<string, mixed> $image */
        $image = is_array($images) && isset($images[0]) && is_array($images[0]) ? $images[0] : [];

        // Track usage
        $this->usageTracker->trackUsage('image', 'fal:' . $model, [
            'size' => $options['image_size'] ?? 'square_hd',
        ]);

        $imageUrl = isset($image['url']) && is_string($image['url']) ? $image['url'] : '';

        return new ImageGenerationResult(
            url: $imageUrl,
            base64: null, // FAL returns URLs, not base64
            prompt: $prompt,
            revisedPrompt: null,
            model: $model,
            size: $this->extractSize($image),
            provider: 'fal',
            metadata: [
                'seed' => $response['seed'] ?? $image['seed'] ?? null,
                'has_nsfw_concepts' => $response['has_nsfw_concepts'] ?? null,
                'timings' => $response['timings'] ?? null,
            ],
        );
    }

    /**
     * Generate multiple images.
     *
     * @param string               $prompt  Text description
     * @param int                  $count   Number of images (1-4)
     * @param string               $model   Model identifier
     * @param array<string, mixed> $options Generation options
     *
     * @return array<int, ImageGenerationResult> Results
     */
    public function generateMultiple(
        string $prompt,
        int $count = 1,
        string $model = 'flux-schnell',
        array $options = [],
    ): array {
        $this->ensureAvailable();

        $count = min(max($count, 1), 4);
        $options['num_images'] = $count;

        $modelEndpoint = $this->resolveModelEndpoint($model);
        $payload = $this->buildGeneratePayload($prompt, $options);

        $usesQueue = $this->modelUsesQueue($model);
        $response = $usesQueue
            ? $this->sendQueueRequest($modelEndpoint, $payload)
            : $this->sendRequest($modelEndpoint, $payload);

        $results = [];
        $responseImages = $response['images'] ?? [];
        if (is_array($responseImages)) {
            foreach ($responseImages as $imageData) {
                if (!is_array($imageData)) {
                    continue;
                }
                /** @var array<string, mixed> $imageData */
                $imageUrl = isset($imageData['url']) && is_string($imageData['url']) ? $imageData['url'] : '';
                $results[] = new ImageGenerationResult(
                    url: $imageUrl,
                    base64: null,
                    prompt: $prompt,
                    revisedPrompt: null,
                    model: $model,
                    size: $this->extractSize($imageData),
                    provider: 'fal',
                    metadata: [
                        'seed' => $imageData['seed'] ?? null,
                    ],
                );
            }
        }

        $this->usageTracker->trackUsage('image', 'fal:' . $model, [
            'count' => count($results),
        ]);

        return $results;
    }

    /**
     * Generate image-to-image transformation.
     *
     * @param string               $imageUrl URL of source image
     * @param string               $prompt   Transformation prompt
     * @param string               $model    Model identifier
     * @param array<string, mixed> $options  Options including strength (0.0-1.0)
     *
     * @return ImageGenerationResult Result
     */
    public function imageToImage(
        string $imageUrl,
        string $prompt,
        string $model = 'flux-dev',
        array $options = [],
    ): ImageGenerationResult {
        $this->ensureAvailable();

        $options['image_url'] = $imageUrl;
        $options['strength'] ??= 0.75;

        return $this->generate($prompt, $model, $options);
    }

    /**
     * Get available models.
     *
     * @return array<string, string> Model ID => endpoint
     */
    public function getAvailableModels(): array
    {
        return self::MODELS;
    }

    /**
     * Get standard aspect ratios.
     *
     * @return array<string, string> Name => ratio
     */
    public function getAspectRatios(): array
    {
        return self::ASPECT_RATIOS;
    }

    /**
     * Load configuration from extension settings.
     */
    private function loadConfiguration(): void
    {
        try {
            $config = $this->extensionConfiguration->get('nr_llm');

            if (!is_array($config)) {
                return;
            }

            $imageConfig = $config['image'] ?? null;
            if (!is_array($imageConfig)) {
                return;
            }

            $falConfig = $imageConfig['fal'] ?? null;
            if (!is_array($falConfig)) {
                return;
            }

            $apiKey = $falConfig['apiKey'] ?? '';
            $this->apiKey = is_string($apiKey) ? $apiKey : '';

            $baseUrl = $falConfig['baseUrl'] ?? self::API_URL;
            $this->baseUrl = is_string($baseUrl) ? $baseUrl : self::API_URL;

            $timeout = $falConfig['timeout'] ?? 120;
            $this->timeout = is_int($timeout) ? $timeout : (is_numeric($timeout) ? (int)$timeout : 120);

            $pollInterval = $falConfig['pollInterval'] ?? 1000;
            $this->pollInterval = is_int($pollInterval) ? $pollInterval : (is_numeric($pollInterval) ? (int)$pollInterval : 1000);
        } catch (Exception $e) {
            $this->logger->warning('Failed to load FAL configuration', [
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
            throw ServiceUnavailableException::notConfigured('image', 'fal');
        }
    }

    /**
     * Resolve model identifier to endpoint.
     */
    private function resolveModelEndpoint(string $model): string
    {
        // Check if it's a known model alias
        if (isset(self::MODELS[$model])) {
            return self::MODELS[$model];
        }

        // Assume it's a full endpoint path
        if (str_contains($model, '/')) {
            return $model;
        }

        // Default to flux-schnell
        return self::MODELS['flux-schnell'];
    }

    /**
     * Check if model uses queue-based processing.
     */
    private function modelUsesQueue(string $model): bool
    {
        // Fast models can use synchronous API
        $fastModels = ['flux-schnell'];

        return !in_array($model, $fastModels, true);
    }

    /**
     * Build generation payload.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildGeneratePayload(string $prompt, array $options): array
    {
        $payload = [
            'prompt' => $prompt,
        ];

        // Image size
        if (isset($options['image_size'])) {
            $payload['image_size'] = $options['image_size'];
        } elseif (isset($options['width']) && isset($options['height'])) {
            $width = $options['width'];
            $height = $options['height'];
            $payload['image_size'] = [
                'width' => is_numeric($width) ? (int)$width : 1024,
                'height' => is_numeric($height) ? (int)$height : 1024,
            ];
        } else {
            $payload['image_size'] = 'square_hd';
        }

        // Number of images
        if (isset($options['num_images'])) {
            $numImages = $options['num_images'];
            $payload['num_images'] = is_numeric($numImages) ? min((int)$numImages, 4) : 1;
        }

        // Guidance scale
        if (isset($options['guidance_scale'])) {
            $guidanceScale = $options['guidance_scale'];
            $payload['guidance_scale'] = is_numeric($guidanceScale) ? (float)$guidanceScale : 7.5;
        }

        // Inference steps
        if (isset($options['num_inference_steps'])) {
            $steps = $options['num_inference_steps'];
            $payload['num_inference_steps'] = is_numeric($steps) ? (int)$steps : 20;
        }

        // Seed
        if (isset($options['seed'])) {
            $seed = $options['seed'];
            $payload['seed'] = is_numeric($seed) ? (int)$seed : 0;
        }

        // Negative prompt
        if (isset($options['negative_prompt'])) {
            $payload['negative_prompt'] = $options['negative_prompt'];
        }

        // Safety checker
        if (isset($options['enable_safety_checker'])) {
            $payload['enable_safety_checker'] = (bool)$options['enable_safety_checker'];
        }

        // Image-to-image
        if (isset($options['image_url'])) {
            $payload['image_url'] = $options['image_url'];
            if (isset($options['strength'])) {
                $strength = $options['strength'];
                $payload['strength'] = is_numeric($strength) ? (float)$strength : 0.75;
            }
        }

        return $payload;
    }

    /**
     * Extract size from image response.
     *
     * @param array<string, mixed> $image
     */
    private function extractSize(array $image): string
    {
        if (isset($image['width']) && isset($image['height'])) {
            $width = $image['width'];
            $height = $image['height'];
            if (is_scalar($width) && is_scalar($height)) {
                return (string)$width . 'x' . (string)$height;
            }
        }

        return '1024x1024';
    }

    /**
     * Send synchronous request.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ServiceUnavailableException
     *
     * @return array<string, mixed>
     */
    private function sendRequest(string $endpoint, array $payload): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Authorization', 'Key ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json');

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        return $this->executeRequest($request);
    }

    /**
     * Send queue-based request with polling.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ServiceUnavailableException
     *
     * @return array<string, mixed>
     */
    private function sendQueueRequest(string $endpoint, array $payload): array
    {
        $queueUrl = rtrim(self::QUEUE_API_URL, '/') . '/' . ltrim($endpoint, '/');

        // Submit to queue
        $request = $this->requestFactory->createRequest('POST', $queueUrl)
            ->withHeader('Authorization', 'Key ' . $this->apiKey)
            ->withHeader('Content-Type', 'application/json');

        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR));
        $request = $request->withBody($body);

        $submitResponse = $this->executeRequest($request);

        $requestId = $submitResponse['request_id'] ?? null;
        if (!is_string($requestId) || $requestId === '') {
            throw new ServiceUnavailableException(
                'FAL queue submission failed: no request_id',
                'image',
                ['provider' => 'fal'],
            );
        }

        // Poll for result
        return $this->pollForResult($endpoint, $requestId);
    }

    /**
     * Poll for queue result.
     *
     * @throws ServiceUnavailableException
     *
     * @return array<string, mixed>
     */
    private function pollForResult(string $endpoint, string $requestId): array
    {
        $statusUrl = rtrim(self::QUEUE_API_URL, '/') . '/' . ltrim($endpoint, '/') . '/requests/' . $requestId . '/status';
        $maxAttempts = (int)ceil(($this->timeout * 1000) / $this->pollInterval);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $request = $this->requestFactory->createRequest('GET', $statusUrl)
                ->withHeader('Authorization', 'Key ' . $this->apiKey);

            $response = $this->executeRequest($request);
            $status = $response['status'] ?? '';

            if ($status === 'COMPLETED') {
                // Fetch the actual result
                $resultUrl = rtrim(self::QUEUE_API_URL, '/') . '/' . ltrim($endpoint, '/') . '/requests/' . $requestId;
                $resultRequest = $this->requestFactory->createRequest('GET', $resultUrl)
                    ->withHeader('Authorization', 'Key ' . $this->apiKey);

                return $this->executeRequest($resultRequest);
            }

            if ($status === 'FAILED') {
                $errorMessage = $response['error'] ?? 'Unknown error';
                $errorMessageStr = is_string($errorMessage) ? $errorMessage : 'Unknown error';
                throw new ServiceUnavailableException(
                    'FAL generation failed: ' . $errorMessageStr,
                    'image',
                    ['provider' => 'fal', 'request_id' => $requestId],
                );
            }

            // Wait before next poll
            usleep($this->pollInterval * 1000);
        }

        throw new ServiceUnavailableException(
            'FAL generation timed out',
            'image',
            ['provider' => 'fal', 'request_id' => $requestId],
        );
    }

    /**
     * Execute HTTP request.
     *
     * @throws ServiceUnavailableException
     *
     * @return array<string, mixed>
     */
    private function executeRequest(RequestInterface $request): array
    {
        try {
            $response = $this->httpClient->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $responseBody = (string)$response->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                /** @var array<string, mixed> $result */
                $result = is_array($decoded) ? $decoded : [];

                return $result;
            }

            $error = json_decode($responseBody, true);
            $errorData = is_array($error) ? $error : [];
            $detail = $errorData['detail'] ?? null;
            $message = $errorData['message'] ?? null;
            $errorMessage = (is_string($detail) ? $detail : null)
                ?? (is_string($message) ? $message : null)
                ?? 'Unknown FAL API error';

            $this->logger->error('FAL API error', [
                'status_code' => $statusCode,
                'error' => $errorMessage,
            ]);

            throw match ($statusCode) {
                401, 403 => ServiceConfigurationException::invalidApiKey('image', 'fal'),
                429 => new ServiceUnavailableException('FAL API rate limit exceeded', 'image', ['provider' => 'fal']),
                422 => new ServiceUnavailableException('FAL API validation error: ' . $errorMessage, 'image', ['provider' => 'fal']),
                default => new ServiceUnavailableException('FAL API error: ' . $errorMessage, 'image', ['provider' => 'fal']),
            };
        } catch (ServiceUnavailableException|ServiceConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error('FAL API connection error', [
                'exception' => $e->getMessage(),
            ]);

            throw new ServiceUnavailableException(
                'Failed to connect to FAL API: ' . $e->getMessage(),
                'image',
                ['provider' => 'fal'],
                0,
                $e,
            );
        }
    }
}
