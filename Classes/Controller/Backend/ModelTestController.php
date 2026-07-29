<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\Response\ErrorResponse;
use Netresearch\NrLlm\Controller\Backend\Response\TestConnectionResponse;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\TestPromptResolverInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Verifies a model record against the provider it declares.
 *
 * Split out of ModelController following ADR-027's per-pathway shape: probe
 * selection, the provider call and the phrasing of the result are one subject,
 * and share nothing with the list view but the record lookup.
 *
 * The probe follows the record's declared capabilities — sending a chat prompt
 * to an image or speech model produced a provider rejection that said nothing
 * about the model.
 *
 * Reached only through an AJAX route, which dispatches outside the module route
 * and so bypasses its `access => 'admin'`; the action calls denyNonAdmin()
 * first (ADR-037). That matters more here than elsewhere — the probe decrypts
 * the provider's vault API key.
 */
#[AsController]
final class ModelTestController extends ActionController
{
    use RequiresBackendAdminTrait;
    use DefensiveLocalizationTrait;

    private const ERROR_NO_MODEL_UID = 'No model UID specified';

    private const ERROR_MODEL_NOT_FOUND = 'Model not found';

    /** Send a chat prompt through the record's own provider. */
    private const PROBE_CHAT = 'chat';

    /** Embed a string through the record's own provider. */
    private const PROBE_EMBEDDINGS = 'embeddings';

    /** Nothing here can verify this model — say so rather than guess. */
    private const PROBE_UNSUPPORTED = 'unsupported';

    /**
     * Capabilities a chat completion exercises. Any of them means the model
     * answers a prompt, which is what the chat probe sends.
     */
    private const CHAT_SHAPED_CAPABILITIES = [
        ModelCapability::CHAT,
        ModelCapability::COMPLETION,
        ModelCapability::VISION,
        ModelCapability::TOOLS,
        ModelCapability::STREAMING,
        ModelCapability::JSON_MODE,
        // Audio is a chat-completions modality in every provider this
        // extension speaks to — there is no audio service and no separate
        // credential, so a record declaring only it still answers a prompt.
        ModelCapability::AUDIO,
    ];

    public function __construct(
        private readonly ModelRepository $modelRepository,
        private readonly ProviderAdapterRegistryInterface $providerAdapterRegistry,
        private readonly TestPromptResolverInterface $testPromptResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * AJAX: Test model by making a simple API call.
     */
    public function testModelAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        $uid = $this->extractIntFromBody($body, 'uid');

        $model = $this->resolveModelOrError($uid);
        if (!$model instanceof Model) {
            return $model;
        }

        return $this->performModelTest($model, $uid);
    }

    /**
     * Perform the actual provider test call for a resolved model.
     */
    private function performModelTest(Model $model, int $uid): ResponseInterface
    {
        // Provider is lazy-loaded by Extbase
        if ($model->getProvider() === null) {
            return new JsonResponse((new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.model.noProviderConfigured', 'Model has no provider configured')))->jsonSerialize(), 400);
        }

        // Resolve localized format strings before try/catch to avoid LocalizationUtility
        // exceptions being caught by the adapter error handler below
        $respondedFormat = $this->translate('model.test.responded')
            ?? 'Model "%s" responded: "%s" (tokens: %d in, %d out)';
        $emptyFormat = $this->translate('model.test.emptyResponse')
            ?? 'Model "%s" connected successfully (tokens: %d in, %d out) - response content empty, model may use internal reasoning';

        // A model that cannot answer a chat prompt must not be sent one: the
        // provider rejects it and the admin sees "provider rejected the test
        // request", which says nothing about the model. Decide from the
        // record's declared capabilities what a meaningful test even is.
        $probe = $this->testProbeFor($model);
        if ($probe === self::PROBE_UNSUPPORTED) {
            return new JsonResponse((new TestConnectionResponse(
                success: false,
                message: sprintf(
                    $this->translate('model.test.notTestableHere')
                        ?? 'Model "%s" provides %s. Those services take their credential from the Extension Configuration rather than from this provider record, so this record cannot verify them.',
                    $model->getName(),
                    implode(', ', $model->getCapabilitySet()->toStringList()),
                ),
            ))->jsonSerialize());
        }

        try {
            // Create adapter from model's provider
            $adapter = $this->providerAdapterRegistry->createAdapterFromModel($model);

            if ($probe === self::PROBE_EMBEDDINGS) {
                return $this->respondToEmbeddingProbe($model, $adapter);
            }

            // Make a simple test call - use enough tokens for models with thinking
            $testPrompt = $this->testPromptResolver->resolve();
            $response = $adapter->complete($testPrompt, [
                'model' => $model->getModelId(),
                'max_tokens' => 100,
            ]);

            $responseText = trim($response->content);

            // Build success message
            if ($responseText !== '') {
                $message = sprintf(
                    $respondedFormat,
                    $model->getName(),
                    mb_substr($responseText, 0, 100) . (mb_strlen($responseText) > 100 ? '...' : ''),
                    $response->usage->promptTokens,
                    $response->usage->completionTokens,
                );
            } else {
                // Model connected but returned empty content (might be using thinking mode)
                $message = sprintf(
                    $emptyFormat,
                    $model->getName(),
                    $response->usage->promptTokens,
                    $response->usage->completionTokens,
                );
            }

            return new JsonResponse((new TestConnectionResponse(
                success: true,
                message: $message,
            ))->jsonSerialize());
        } catch (ProviderException $e) {
            // REC #8b: provider error text often references upstream
            // bodies / endpoints / auth artefacts — log full detail,
            // surface a short upstream-error message stripped of
            // internal context. The frontend renders this verbatim
            // in the test-connection toast.
            $this->logger->warning('Model test: provider rejected request', [
                'exception' => $e,
                'model_uid' => $uid,
            ]);
            $message = 'LLM provider rejected the test request. See system log for details.';
        } catch (Throwable $e) {
            $this->logger->error('Model test: unexpected error', [
                'exception' => $e,
                'model_uid' => $uid,
            ]);
            $message = 'Test failed. See system log for details.';
        }

        return new JsonResponse((new TestConnectionResponse(
            success: false,
            message: $message,
        ))->jsonSerialize());
    }

    /**
     * Which probe verifies this model from a provider record.
     *
     * Only capabilities served by the record's own provider connection can be
     * verified here. Image, speech and transcription go through the specialized
     * services, whose credential comes from the Extension Configuration and not
     * from this provider — testing them from here would authenticate with a
     * different secret than the record declares, which is worse than not
     * testing at all.
     *
     * An empty capability list keeps the chat probe: the TCA field has no
     * minimum, so it is routinely left as shipped, and refusing to test the
     * common case would be a regression.
     */
    private function testProbeFor(Model $model): string
    {
        $capabilities = $model->getCapabilitySet();
        if ($capabilities->isEmpty()) {
            return self::PROBE_CHAT;
        }

        foreach (self::CHAT_SHAPED_CAPABILITIES as $capability) {
            if ($capabilities->has($capability)) {
                return self::PROBE_CHAT;
            }
        }

        if ($capabilities->has(ModelCapability::EMBEDDINGS)) {
            return self::PROBE_EMBEDDINGS;
        }

        return self::PROBE_UNSUPPORTED;
    }

    /**
     * Embed a short string and report the vector's dimensions.
     *
     * The one non-chat capability that runs on the record's own provider
     * connection, so it verifies exactly what the record claims.
     */
    private function respondToEmbeddingProbe(Model $model, ProviderInterface $adapter): ResponseInterface
    {
        $response = $adapter->embeddings($this->testPromptResolver->resolve(), [
            'model' => $model->getModelId(),
        ]);

        $dimensions = $response->getDimensions();
        if ($dimensions === 0) {
            // A 2xx whose body carries no vector has verified nothing; saying
            // "returned a 0-dimension embedding" in the green panel would be a
            // false positive.
            return new JsonResponse((new TestConnectionResponse(
                success: false,
                message: sprintf(
                    $this->translate('model.test.emptyEmbedding')
                        ?? 'Model "%s" answered but returned no embedding vector.',
                    $model->getName(),
                ),
            ))->jsonSerialize());
        }

        return new JsonResponse((new TestConnectionResponse(
            success: true,
            message: sprintf(
                $this->translate('model.test.embedded')
                    ?? 'Model "%s" returned a %d-dimension embedding (tokens: %d in).',
                $model->getName(),
                $dimensions,
                $response->usage->promptTokens,
            ),
        ))->jsonSerialize());
    }

    /**
     * Resolve a model by UID, or return a JSON error response.
     */
    private function resolveModelOrError(int $uid): Model|ResponseInterface
    {
        if ($uid === 0) {
            return new JsonResponse((new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.model.noModelUid', self::ERROR_NO_MODEL_UID)))->jsonSerialize(), 400);
        }

        $model = $this->modelRepository->findByUid($uid);
        if ($model === null) {
            return new JsonResponse((new ErrorResponse($this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.model.notFound', self::ERROR_MODEL_NOT_FOUND)))->jsonSerialize(), 404);
        }

        return $model;
    }

    /**
     * Extract integer value from request body.
     */
    private function extractIntFromBody(mixed $body, string $key): int
    {
        if (!is_array($body)) {
            return 0;
        }

        $value = $body[$key] ?? 0;

        return is_numeric($value) ? (int)$value : 0;
    }

    private function translate(string $key): ?string
    {
        try {
            return LocalizationUtility::translate(
                'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:' . $key,
                'NrLlm',
            );
        } catch (Throwable) {
            return null;
        }
    }

}
