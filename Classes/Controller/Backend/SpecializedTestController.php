<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use JsonException;
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Image\ImageGeneratorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Lets an administrator exercise the specialized services from the backend.
 *
 * Translation and image generation are configured through the Extension
 * Configuration — a vault identifier per credential — and until now nothing in
 * the backend could reach them: the Playground drives the agent runtime, and
 * every "Test" button on a provider, model or configuration record issues a
 * plain chat completion. An operator who pasted a DeepL or OpenAI identifier
 * had no way to find out whether it works short of writing consumer code.
 *
 * These endpoints verify a credential; they are not a feature. Nothing is
 * persisted. A translation comes back as text, a generated image as whatever
 * the provider returned — a URL from FAL, a data URI from the OpenAI family.
 * Storing the image, moving it into FAL or attaching it to a record is the
 * consuming extension's job and deliberately out of scope.
 *
 * Registered in AjaxRoutes.php, so the module's `access => admin` does not
 * apply — every action calls denyNonAdmin() first (ADR-037).
 */
#[AsController]
final readonly class SpecializedTestController
{
    use RequiresBackendAdminTrait;

    private const MAX_INPUT_LENGTH = 5000;

    public function __construct(
        private TranslationServiceInterface $translationService,
        private ImageGeneratorInterface $openAiImage,
        private ImageGeneratorInterface $falImage,
        private LoggerInterface $logger,
    ) {}

    /**
     * Translate a snippet and report which translator answered.
     *
     * With no translator identifier the LLM path runs, which needs a usable
     * provider but no specialized credential. Naming one (`deepl`) routes to
     * that translator, which is the case worth testing after configuring its
     * vault identifier.
     */
    public function translateAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $body = $this->bodyOf($request);
        $text = $this->stringFromBody($body, 'text');
        $targetLanguage = $this->stringFromBody($body, 'targetLanguage');
        $sourceLanguage = $this->stringFromBody($body, 'sourceLanguage');
        $translator = $this->stringFromBody($body, 'translator');

        if ($text === '' || $targetLanguage === '') {
            return $this->badRequest('Text and target language are required.');
        }

        if (mb_strlen($text) > self::MAX_INPUT_LENGTH) {
            return $this->badRequest(sprintf('Text must not exceed %d characters for a test.', self::MAX_INPUT_LENGTH));
        }

        $source = $sourceLanguage !== '' ? $sourceLanguage : null;

        try {
            if ($translator !== '') {
                $result = $this->translationService
                    ->getTranslator($translator)
                    ->translate($text, $targetLanguage, $source);

                return new JsonResponse([
                    'success'         => true,
                    'translation'     => $result->translatedText,
                    'sourceLanguage'  => $result->sourceLanguage,
                    'targetLanguage'  => $result->targetLanguage,
                    'translator'      => $result->translator,
                    'confidence'      => $result->confidence,
                    'charactersUsed'  => $result->charactersUsed,
                ]);
            }

            $result = $this->translationService->translate($text, $targetLanguage, $source);

            return new JsonResponse([
                'success'        => true,
                'translation'    => $result->translation,
                'sourceLanguage' => $result->sourceLanguage,
                'targetLanguage' => $result->targetLanguage,
                'translator'     => 'llm',
                'confidence'     => $result->confidence,
                'usage'          => [
                    'promptTokens'     => $result->usage->promptTokens,
                    'completionTokens' => $result->usage->completionTokens,
                    'totalTokens'      => $result->usage->totalTokens,
                ],
            ]);
        } catch (ServiceConfigurationException $e) {
            return $this->rejected('translator', $e);
        } catch (ServiceUnavailableException $e) {
            return $this->unconfigured('translator', $e);
        } catch (Throwable $e) {
            return $this->failed('translation', $e);
        }
    }

    /**
     * List the registered translators and whether each one is configured.
     */
    public function translatorsAction(): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        try {
            return new JsonResponse([
                'success'     => true,
                'translators' => array_values($this->translationService->getAvailableTranslators()),
            ]);
        } catch (Throwable $e) {
            return $this->failed('listing translators', $e);
        }
    }

    /**
     * Generate one image and hand it back without storing it.
     */
    public function generateImageAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $body = $this->bodyOf($request);
        $prompt = $this->stringFromBody($body, 'prompt');
        $service = $this->stringFromBody($body, 'service');

        if ($prompt === '') {
            return $this->badRequest('A prompt is required.');
        }

        if (mb_strlen($prompt) > self::MAX_INPUT_LENGTH) {
            return $this->badRequest(sprintf('Prompt must not exceed %d characters for a test.', self::MAX_INPUT_LENGTH));
        }

        $generator = $service === 'fal' ? $this->falImage : $this->openAiImage;

        try {
            $result = $generator->generate($prompt);

            // FAL answers with a URL, the OpenAI family may answer with base64.
            // Return whichever exists; the caller renders it and forgets it.
            return new JsonResponse([
                'success'       => true,
                'url'           => $result->url !== '' ? $result->url : null,
                'dataUrl'       => $result->hasBase64() ? $result->toDataUrl() : null,
                'model'         => $result->model,
                'size'          => $result->size,
                'provider'      => $result->provider,
                'revisedPrompt' => $result->revisedPrompt,
            ]);
        } catch (ServiceConfigurationException $e) {
            return $this->rejected('image service', $e);
        } catch (ServiceUnavailableException $e) {
            return $this->unconfigured('image service', $e);
        } catch (Throwable $e) {
            return $this->failed('image generation', $e);
        }
    }

    private function badRequest(string $message): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'error' => $message], 400);
    }

    /**
     * The failure this endpoint exists to reveal: no credential, or a vault
     * secret that no longer resolves. Reported as 503 with a message that
     * names where to fix it, rather than folded into the generic 500.
     */
    private function unconfigured(string $subject, Throwable $e): ResponseInterface
    {
        $this->logger->info(sprintf('Specialized test: %s unavailable', $subject), ['exception' => $e]);

        return new JsonResponse([
            'success' => false,
            'error'   => sprintf(
                'This %s is not available — either no credential is configured for it, or the provider could not be reached. Check its vault identifier in the Extension Configuration.',
                $subject,
            ),
        ], 503);
    }

    /**
     * A credential exists and the provider refused it — the likeliest real
     * failure, and a different action from "nothing is configured": the
     * identifier resolves, the secret behind it is wrong or revoked.
     */
    private function rejected(string $subject, Throwable $e): ResponseInterface
    {
        $this->logger->info(sprintf('Specialized test: %s rejected the credential', $subject), ['exception' => $e]);

        return new JsonResponse([
            'success' => false,
            'error'   => sprintf(
                'The provider rejected the credential for this %s. The vault identifier resolves — check the secret behind it.',
                $subject,
            ),
        ], 502);
    }

    private function failed(string $subject, Throwable $e): ResponseInterface
    {
        $this->logger->error(sprintf('Specialized test: %s failed', $subject), ['exception' => $e]);

        return new JsonResponse([
            'success' => false,
            'error'   => sprintf('The %s test failed. See system log for details.', $subject),
        ], 500);
    }

    /**
     * TYPO3 fills `getParsedBody()` from `$_POST`, and no core middleware
     * decodes a JSON body into it — an `application/json` request would arrive
     * with every field empty. Mirrors SetupWizardController::parseRequestBody().
     *
     * @return array<string, mixed>
     */
    private function bodyOf(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body) && $body !== []) {
            /** @var array<string, mixed> $body */
            return $body;
        }

        if (!str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
            return [];
        }

        $contents = $request->getBody()->getContents();
        if ($contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringFromBody(array $body, string $key): string
    {
        if (!isset($body[$key]) || !is_string($body[$key])) {
            return '';
        }

        return trim($body[$key]);
    }
}
