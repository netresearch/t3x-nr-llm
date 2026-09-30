<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Decision;

use Exception;
use Netresearch\NrLlm\Domain\Model\DecisionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ChoiceQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Exception\GuardrailPolicyException;
use Netresearch\NrLlm\Exception\InputContextTrustZoneException;
use Netresearch\NrLlm\Provider\Exception\InvalidDecisionResponseException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfile;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfileRegistry;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Option\DecisionOptions;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Typed decisions on a configuration's model (ADR-211).
 *
 * Resolves the profile and the configuration, checks the subject against the
 * profile and the trust zone of every provider the configuration can reach,
 * then asks: a model that declares `decision` natively through the manager,
 * any other chat-capable model through structured output. The answers are
 * verified against the questions before a caller sees them, whichever model
 * produced them.
 *
 * Every failure throws. Budget, guardrail and input-context trust-zone
 * denials keep their types, because a caller may handle them as policy; everything else becomes a
 * {@see DecisionException}, so "the model could not be asked" can never be
 * read as an answer.
 */
final readonly class DecisionService implements DecisionServiceInterface
{
    public function __construct(
        private DecisionProfileRegistry $profiles,
        private LlmConfigurationRepository $configurations,
        private ModelSelectionServiceInterface $modelSelection,
        private TrustZoneResolver $trustZones,
        private LlmServiceManagerInterface $llmManager,
        private StructuredDecisionAsker $structured,
        private ExtensionConfiguration $extensionConfiguration,
        private ?BackendUserContextResolverInterface $beUserContextResolver = null,
    ) {}

    public function evaluate(DecisionRequest $request): DecisionResult
    {
        $profile = $this->profile($request->profile);

        foreach ($profile->requires as $field) {
            if (!$request->subject->has($field)) {
                throw DecisionException::missingSubjectField($profile->identifier, $field);
            }
        }

        [$configuration, $resolution, $native] = $this->route($profile, $request->configuration);

        $request = $this->withBudgetSubject($request);

        try {
            $response = $native
                ? $this->llmManager->decideForConfiguration($request->subject, $profile->questions, $configuration, $this->options($request), $resolution)
                : $this->structured->ask($profile, $request, $configuration, $resolution);
        } catch (DecisionException|BudgetExceededException|GuardrailPolicyException|InputContextTrustZoneException $e) {
            // Policy refusals keep their types: a caller may handle them as policy.
            throw $e;
        } catch (UnsupportedFeatureException $e) {
            // A model that declares `decision` on a provider that cannot make any.
            throw DecisionException::modelCannotDecide($configuration->getIdentifier(), $e->getMessage(), $e);
        } catch (InvalidDecisionResponseException $e) {
            throw DecisionException::invalidAnswer($configuration->getIdentifier(), '*', $e->getMessage(), $e);
        } catch (ProviderResponseException $e) {
            // A refused request — a bad key, a subject over the limit, a
            // question the provider cannot take — fails the same way again;
            // only a rate limit is worth retrying.
            throw $e->httpStatus >= 400 && $e->httpStatus < 500 && $e->httpStatus !== 429
                ? DecisionException::rejected($configuration->getIdentifier(), $e)
                : DecisionException::failed($configuration->getIdentifier(), $e);
        } catch (Exception $e) {
            // Outages, timeouts, an exhausted fallback chain. An \Error is a
            // defect in the code, not a failed decision, and propagates as such.
            throw DecisionException::failed($configuration->getIdentifier(), $e);
        }

        $this->assertAnswersMatch($profile, $configuration->getIdentifier(), $response);

        return $this->result($profile, $configuration, $response);
    }

    public function assertAvailable(string $profile, ?string $configuration = null): void
    {
        $this->route($this->profile($profile), $configuration);
    }

    private function profile(string $identifier): DecisionProfile
    {
        return $this->profiles->find($identifier)
            ?? throw DecisionException::unknownProfile($identifier);
    }

    /**
     * The configuration, the one routing decision for it, and whether its
     * model answers natively — refused where the profile's data may not go.
     *
     * @return array{LlmConfiguration, ModelResolution, bool}
     */
    private function route(DecisionProfile $profile, ?string $identifier): array
    {
        $configuration = $this->resolveConfiguration($identifier);
        [$resolution, $native] = $this->resolveModel($configuration);

        // The zone of every provider the call can reach, fallbacks included:
        // a fallback sends the subject wherever it points. The resolution is
        // handed to the call, so the model checked here is the model that
        // serves — a second routing pass could pick another (#922).
        $zone = $this->trustZones->zoneFor($configuration, $resolution->model);
        if (!$zone->permits($profile->dataClass)) {
            throw DecisionException::dataClassNotPermitted($profile->identifier, $profile->dataClass, $configuration->getIdentifier(), $zone);
        }

        return [$configuration, $resolution, $native];
    }

    private function resolveConfiguration(?string $identifier): LlmConfiguration
    {
        $identifier = $identifier !== null && trim($identifier) !== '' ? trim($identifier) : $this->defaultConfiguration();
        if ($identifier === '') {
            throw DecisionException::noConfiguration();
        }

        $configuration = $this->configurations->findOneByIdentifier($identifier);
        if (!$configuration instanceof LlmConfiguration || !$configuration->isActive()) {
            throw DecisionException::unknownConfiguration($identifier);
        }

        return $configuration;
    }

    private function defaultConfiguration(): string
    {
        try {
            $value = $this->extensionConfiguration->get('nr_llm', 'decision/configuration');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            // Not set: the request has to name a configuration. Any other
            // failure to read the setting is not "unset" and propagates.
            return '';
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The routing decision for the model that will answer, and whether it
     * answers natively.
     *
     * Resolved for the Decision operation first: a fixed model comes back as
     * it is, criteria pick among decision models. Where that yields no
     * decision model, the configuration may still hold a chat model, which is
     * resolved for the Chat operation it will run as and asked through
     * structured output.
     *
     * @return array{ModelResolution, bool}
     */
    private function resolveModel(LlmConfiguration $configuration): array
    {
        try {
            $resolution = $this->modelSelection->resolveModelForCall($configuration, ProviderOperation::Decision);
        } catch (UnsupportedFeatureException) {
            $resolution = null;
        }

        if ($resolution?->model instanceof Model && $resolution->model->supportsDecision()) {
            return [$resolution, true];
        }

        try {
            $resolution = $this->modelSelection->resolveModelForCall($configuration, ProviderOperation::Chat);
        } catch (UnsupportedFeatureException $e) {
            throw DecisionException::modelCannotDecide($configuration->getIdentifier(), $e->getMessage(), $e);
        }

        $model = $resolution->model;
        if (!$model instanceof Model) {
            throw DecisionException::modelCannotDecide($configuration->getIdentifier(), 'it resolves no model');
        }

        // A model that declares nothing is treated as a chat model, as model
        // selection treats it; one that declares capabilities without chat
        // (an embedding model) cannot answer.
        if (!$model->supportsChat() && $model->getCapabilitiesArray() !== []) {
            throw DecisionException::modelCannotDecide(
                $configuration->getIdentifier(),
                sprintf('model "%s" declares neither "decision" nor "chat"', $model->getModelId()),
            );
        }

        return [$resolution, false];
    }

    private function withBudgetSubject(DecisionRequest $request): DecisionRequest
    {
        if ($request->beUserUid !== null || !$this->beUserContextResolver instanceof BackendUserContextResolverInterface) {
            return $request;
        }

        $resolved = $this->beUserContextResolver->resolveBeUserUid();

        return $resolved === null ? $request : $request->withBeUserUid($resolved);
    }

    private function options(DecisionRequest $request): DecisionOptions
    {
        $options = new DecisionOptions($request->beUserUid);

        return $request->callerSourceExtension !== ''
            ? $options->withCallerSource($request->callerSourceExtension, $request->callerSourceOperation)
            : $options;
    }

    private function result(DecisionProfile $profile, LlmConfiguration $configuration, DecisionResponse $response): DecisionResult
    {
        $usage = $response->usage;
        $reported = !($usage->promptTokens === 0 && $usage->completionTokens === 0 && $usage->totalTokens === 0);

        return new DecisionResult(
            profile: $profile->identifier,
            profileVersion: $profile->version,
            configuration: $configuration->getIdentifier(),
            provider: $response->provider,
            model: $response->model,
            probabilityKind: $response->probabilityKind,
            answers: $response->answers,
            inputTokens: $reported ? $usage->promptTokens : null,
            outputTokens: $reported ? $usage->completionTokens : null,
            cost: $usage->estimatedCost,
        );
    }

    /**
     * One answer per question, of the question's type, within its range and
     * with probabilities over what the question offers. The providers already
     * parse strictly; this holds every provider to the same contract and keeps
     * a caller from ever reading an answer to a question it did not ask.
     */
    private function assertAnswersMatch(DecisionProfile $profile, string $configuration, DecisionResponse $response): void
    {
        $expected = [];
        foreach ($profile->questions as $question) {
            $expected[$question->key()] = true;
            $answer = $response->answers[$question->key()] ?? null;
            if (!$answer instanceof DecisionAnswer) {
                throw DecisionException::invalidAnswer($configuration, $question->key(), 'no answer');
            }

            $this->assertAnswerFits($question, $answer, $configuration);
        }

        foreach (array_keys($response->answers) as $key) {
            if (!isset($expected[$key])) {
                throw DecisionException::invalidAnswer($configuration, (string)$key, 'the profile asks no such question');
            }
        }
    }

    private function assertAnswerFits(DecisionQuestion $question, DecisionAnswer $answer, string $configuration): void
    {
        $key = $question->key();
        if ($answer->key !== $key || $answer->type !== $question->type()) {
            throw DecisionException::invalidAnswer($configuration, $key, 'the answer does not belong to this question');
        }

        $offered = match (true) {
            $question instanceof ChoiceQuestion => $question->options,
            $question instanceof ScoreQuestion  => array_map(strval(...), range(0, $question->maxLevel())),
            default                             => [],
        };

        if ($question instanceof ChoiceQuestion && !in_array($answer->choice, $question->options, true)) {
            throw DecisionException::invalidAnswer($configuration, $key, sprintf('"%s" is not one of its options', (string)$answer->choice));
        }

        if ($question instanceof ScoreQuestion && ($answer->value ?? -1.0) > $question->maxLevel()) {
            throw DecisionException::invalidAnswer($configuration, $key, 'the score lies above the highest level');
        }

        foreach (array_keys($answer->probabilities) as $name) {
            if (!in_array((string)$name, $offered, true)) {
                throw DecisionException::invalidAnswer($configuration, $key, sprintf('a probability for "%s", which the question does not offer', $name));
            }
        }
    }
}
