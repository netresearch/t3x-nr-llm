<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Image;

use JsonException;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Specialized\AbstractSpecializedService;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrVault\Http\SecretPlacement;
use Throwable;

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
final class FalImageService extends AbstractSpecializedService
{
    private const API_URL = 'https://fal.run';
    private const QUEUE_API_URL = 'https://queue.fal.run';

    /** Default model endpoints. */
    private const MODELS = [
        'flux-pro' => 'fal-ai/flux-pro',
        'flux-dev' => 'fal-ai/flux/dev',
        'flux-schnell' => 'fal-ai/flux/schnell',
        'sdxl' => 'fal-ai/fast-sdxl',
        'sd3' => 'fal-ai/stable-diffusion-v3-medium',
        'playground' => 'fal-ai/playground-v25',
    ];

    /** Standard aspect ratios. */
    private const ASPECT_RATIOS = [
        'square' => '1:1',
        'landscape' => '16:9',
        'portrait' => '9:16',
        'wide' => '21:9',
        'photo' => '4:3',
        'photo-portrait' => '3:4',
    ];

    private int $pollInterval = 1000; // milliseconds

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
     *                                      - beUserUid: int Backend user the usage row
     *                                      is attributed to (ADR-052/ADR-057) —
     *                                      attribution metadata only, never part of
     *                                      the FAL payload (the payload builder is an
     *                                      explicit allowlist)
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
        $this->enforceBudget($this->extractBeUserUid($options), $this->extractPlannedCost($options), null);

        $modelEndpoint = $this->resolveModelEndpoint($model);

        $payload = $this->buildGeneratePayload($prompt, $options);

        $this->setAuditContext(sprintf('%s, generate', $model));
        $usesQueue = $this->modelUsesQueue($model);
        $response = $usesQueue
            ? $this->sendQueueRequest($modelEndpoint, $payload)
            : $this->sendJsonRequest($modelEndpoint, $payload);

        $images = $response['images'] ?? [];
        /** @var array<string, mixed> $image */
        $image = is_array($images) && isset($images[0]) && is_array($images[0]) ? $images[0] : [];

        // FAL publishes no static price list (billing varies per hosted
        // model), so no cost is recorded — never guess (see
        // SpecializedCostCalculatorInterface).
        $this->usageTracker->trackUsage('image', $this->getServiceProvider(), [
            'images' => 1,
        ], modelUid: $this->resolveModelUid($model), modelId: $model, beUserUid: $this->extractBeUserUid($options));

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

        $this->enforceBudget($this->extractBeUserUid($options), $this->extractPlannedCost($options), null);

        $modelEndpoint = $this->resolveModelEndpoint($model);
        $payload = $this->buildGeneratePayload($prompt, $options);

        $this->setAuditContext(sprintf('%s, generate', $model));
        $usesQueue = $this->modelUsesQueue($model);
        $response = $usesQueue
            ? $this->sendQueueRequest($modelEndpoint, $payload)
            : $this->sendJsonRequest($modelEndpoint, $payload);

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

        $this->usageTracker->trackUsage('image', $this->getServiceProvider(), [
            'images' => count($results),
        ], modelUid: $this->resolveModelUid($model), modelId: $model, beUserUid: $this->extractBeUserUid($options));

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
     * FAL vocabulary: only the model keys this service can map to fal-ai
     * endpoints (see self::MODELS) — an OpenAI image id from the shared
     * IMAGE-capability registry default must never reach the FAL endpoint.
     */
    protected function acceptsModelId(string $modelId): bool
    {
        return \array_key_exists($modelId, self::MODELS);
    }

    /**
     * FAL generates images, so the registry's image models steer it too —
     * the inherited resolveDefaultModel()/resolveModelForConfiguration()
     * resolve like the OpenAI image service's, restricted by acceptsModelId().
     */
    protected function getModelCapability(): ModelCapability
    {
        return ModelCapability::IMAGE;
    }

    protected function getServiceDomain(): string
    {
        return 'image';
    }

    protected function getServiceProvider(): string
    {
        return 'fal';
    }

    protected function getDefaultBaseUrl(): string
    {
        return self::API_URL;
    }

    protected function getDefaultTimeout(): int
    {
        return 120;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function loadServiceConfiguration(array $config): void
    {
        $imageConfig = $config['image'] ?? null;
        if (!is_array($imageConfig)) {
            return;
        }

        $falConfig = $imageConfig['fal'] ?? null;
        if (!is_array($falConfig)) {
            return;
        }

        $apiKeyIdentifier = $falConfig['apiKeyIdentifier'] ?? '';
        $this->apiKeyIdentifier = is_string($apiKeyIdentifier) ? $apiKeyIdentifier : '';

        // Empty ext_conf baseUrl means "use the default" (per the field label), not an empty
        // request URL — see nonEmptyStringOrDefault().
        $this->baseUrl = $this->nonEmptyStringOrDefault($falConfig['baseUrl'] ?? null, self::API_URL);

        $timeout = $falConfig['timeout'] ?? $this->getDefaultTimeout();
        if (is_int($timeout)) {
            $this->timeout = $timeout;
        } elseif (is_numeric($timeout)) {
            $this->timeout = (int)$timeout;
        } else {
            $this->timeout = $this->getDefaultTimeout();
        }

        $pollInterval = $falConfig['pollInterval'] ?? 1000;
        // Clamp to >= 1ms: a configured 0 (or negative) would make the poll-attempt
        // count a division by zero and turn usleep() into a busy-loop. max(1, …)
        // keeps the service fail-soft on a misconfigured interval.
        $resolvedInterval = is_numeric($pollInterval) ? (int)$pollInterval : 1000;
        $this->pollInterval = max(1, $resolvedInterval);
    }

    /**
     * FAL authenticates with `Authorization: Key <secret>` — not Bearer — so
     * it uses Header placement with a `Key ` prefix on the secure client.
     */
    protected function getSecretPlacement(): SecretPlacement
    {
        return SecretPlacement::Header;
    }

    /**
     * @return array<string, string>
     */
    protected function getSecretPlacementOptions(): array
    {
        return ['headerName' => 'Authorization', 'prefix' => 'Key '];
    }

    protected function getProviderLabel(): string
    {
        return 'FAL';
    }

    /**
     * FAL uses `detail` (FastAPI default) or `message` for error
     * messages — the OpenAI-style `error.message` shape doesn't apply.
     *
     * A non-JSON error body (e.g. an HTML gateway/proxy error page) is logged
     * with a short sample so it is distinguishable from an empty response, and
     * surfaced as a clearer fallback than the generic unknown-error label.
     */
    protected function decodeErrorMessage(string $responseBody): string
    {
        if ($responseBody === '') {
            return $this->unknownErrorLabel();
        }

        try {
            $error = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning('FAL error response is not JSON', [
                'provider' => $this->getServiceProvider(),
                'message'  => $e->getMessage(),
                'sample'   => substr($responseBody, 0, 200),
            ]);

            return 'FAL error response is not JSON';
        }

        if (is_array($error)) {
            $detail  = $error['detail']  ?? null;
            $message = $error['message'] ?? null;
            if (is_string($detail) && $detail !== '') {
                return $detail;
            }
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return $this->unknownErrorLabel();
    }

    /**
     * FAL surfaces a 422 validation error distinctly — keep the
     * existing wording (`'FAL API validation error: …'`) so log
     * filters that branch on it continue to work.
     */
    protected function mapErrorStatus(int $statusCode, string $errorMessage): Throwable
    {
        if ($statusCode === 422) {
            return new ServiceUnavailableException(
                'FAL API validation error: ' . $errorMessage,
                $this->getServiceDomain(),
                ['provider' => $this->getServiceProvider()],
            );
        }
        return parent::mapErrorStatus($statusCode, $errorMessage);
    }

    /**
     * Resolve model identifier to endpoint.
     */
    private function resolveModelEndpoint(string $model): string
    {
        if (isset(self::MODELS[$model])) {
            return self::MODELS[$model];
        }

        if (str_contains($model, '/')) {
            return $model;
        }

        return self::MODELS['flux-schnell'];
    }

    /**
     * Check if model uses queue-based processing.
     */
    private function modelUsesQueue(string $model): bool
    {
        $fastModels = ['flux-schnell'];

        return !in_array($model, $fastModels, true);
    }

    /**
     * Extract the usage-attribution uid from the plain options array
     * (`beUserUid` key, ADR-052/ADR-057 — the DeepL/LlmTranslator array
     * pattern; FAL has no typed options DTO). Attribution metadata only:
     * `buildGeneratePayload()` is an explicit allowlist, so the key never
     * reaches the FAL API. Negative values are treated as absent.
     *
     * @param array<string, mixed> $options
     */
    private function extractBeUserUid(array $options): ?int
    {
        $beUserUid = $options['beUserUid'] ?? null;

        return is_int($beUserUid) && $beUserUid >= 0 ? $beUserUid : null;
    }

    /**
     * Extract the planned cost from the options payload for the budget
     * pre-flight (ADR-078). Like `beUserUid`, this is consumer metadata that
     * must never reach the FAL API — the allowlist payload builder drops it.
     * Negative values are treated as absent.
     *
     * @param array<string, mixed> $options
     */
    private function extractPlannedCost(array $options): ?float
    {
        $plannedCost = $options['plannedCost'] ?? null;

        return (is_float($plannedCost) || is_int($plannedCost)) && $plannedCost >= 0 ? (float)$plannedCost : null;
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
            'image_size' => $this->resolveImageSize($options),
        ];

        $payload = $this->applyNumericOptions($payload, $options);

        if (isset($options['negative_prompt'])) {
            $payload['negative_prompt'] = $options['negative_prompt'];
        }

        if (isset($options['enable_safety_checker'])) {
            $payload['enable_safety_checker'] = (bool)$options['enable_safety_checker'];
        }

        return $this->applyImageToImageOptions($payload, $options);
    }

    /**
     * Resolve the requested image size: an explicit `image_size`, a derived
     * width/height pair, or the default.
     *
     * @param array<string, mixed> $options
     */
    private function resolveImageSize(array $options): mixed
    {
        if (isset($options['image_size'])) {
            return $options['image_size'];
        }

        if (isset($options['width']) && isset($options['height'])) {
            $width = $options['width'];
            $height = $options['height'];

            return [
                'width' => is_numeric($width) ? (int)$width : 1024,
                'height' => is_numeric($height) ? (int)$height : 1024,
            ];
        }

        return 'square_hd';
    }

    /**
     * Apply the numeric generation options to the payload.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function applyNumericOptions(array $payload, array $options): array
    {
        if (isset($options['num_images'])) {
            $numImages = $options['num_images'];
            $payload['num_images'] = is_numeric($numImages) ? min((int)$numImages, 4) : 1;
        }

        if (isset($options['guidance_scale'])) {
            $guidanceScale = $options['guidance_scale'];
            $payload['guidance_scale'] = is_numeric($guidanceScale) ? (float)$guidanceScale : 7.5;
        }

        if (isset($options['num_inference_steps'])) {
            $steps = $options['num_inference_steps'];
            $payload['num_inference_steps'] = is_numeric($steps) ? (int)$steps : 20;
        }

        if (isset($options['seed'])) {
            $seed = $options['seed'];
            $payload['seed'] = is_numeric($seed) ? (int)$seed : 0;
        }

        return $payload;
    }

    /**
     * Apply the image-to-image options (source URL and optional strength).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function applyImageToImageOptions(array $payload, array $options): array
    {
        if (!isset($options['image_url'])) {
            return $payload;
        }

        $payload['image_url'] = $options['image_url'];
        if (isset($options['strength'])) {
            $strength = $options['strength'];
            $payload['strength'] = is_numeric($strength) ? (float)$strength : 0.75;
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
     * Send queue-based request with polling. Submit, then poll
     * `/requests/{id}/status` until completion or timeout.
     *
     * Cannot route through the base's `sendJsonRequest()` directly
     * because the queue API lives on a different host
     * (`queue.fal.run`) than the synchronous endpoint (`fal.run`).
     * Builds the request manually but reuses the base's
     * `executeRequest()` for status-handling consistency.
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

        $request = $this->requestFactory->createRequest('POST', $queueUrl)
            ->withHeader('Content-Type', 'application/json');
        foreach ($this->getAdditionalHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
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
            $request = $this->requestFactory->createRequest('GET', $statusUrl);
            foreach ($this->getAdditionalHeaders() as $name => $value) {
                $request = $request->withHeader($name, $value);
            }

            $response = $this->executeRequest($request);
            $status = $response['status'] ?? '';

            if ($status === 'COMPLETED') {
                $resultUrl = rtrim(self::QUEUE_API_URL, '/') . '/' . ltrim($endpoint, '/') . '/requests/' . $requestId;
                $resultRequest = $this->requestFactory->createRequest('GET', $resultUrl);
                foreach ($this->getAdditionalHeaders() as $name => $value) {
                    $resultRequest = $resultRequest->withHeader($name, $value);
                }

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

            usleep($this->pollInterval * 1000);
        }

        throw new ServiceUnavailableException(
            'FAL generation timed out',
            'image',
            ['provider' => 'fal', 'request_id' => $requestId],
        );
    }
}
