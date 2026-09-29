<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Domain\ValueObject\Decision\ChoiceQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;

/**
 * The local decision sidecar (`Build/decision`, ADR-211).
 *
 * A zero-shot natural-language-inference model on the host — by default the
 * multilingual `MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7` —
 * answering the same three question types as a hosted decision API. It needs
 * no key and the subject never leaves the host, which makes it the model
 * for tests, local development and a comparison with a hosted model. Its
 * probabilities are the model's own distribution, not a calibration.
 *
 * Like Ollama it authenticates with nothing, so the endpoint host goes
 * through the SSRF host filter before a request is sent.
 */
final class DecisionSidecarProvider extends AbstractDecisionProvider
{
    public const IDENTIFIER = 'decision_sidecar';

    public const DEFAULT_MODEL = 'MoritzLaurer/mDeBERTa-v3-base-xnli-multilingual-nli-2mil7';

    public function getName(): string
    {
        return 'Local decision sidecar';
    }

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'http://decision:8082';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel !== '' ? $this->defaultModel : self::DEFAULT_MODEL;
    }

    public function isAvailable(): bool
    {
        return $this->baseUrl !== '';
    }

    protected function validateConfiguration(): void
    {
        if ($this->baseUrl === '') {
            $this->baseUrl = $this->getDefaultBaseUrl();
        }
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        return [self::DEFAULT_MODEL => 'mDeBERTa v3 XNLI multilingual (zero-shot NLI)'];
    }

    /**
     * @return array{success: bool, message: string, models: array<string, string>}
     */
    public function testConnection(): array
    {
        $response = $this->sendRequest('models', [], 'GET');

        $models = [];
        foreach ($this->getList($response, 'models') as $entry) {
            $name = $this->getString($this->asArray($entry), 'name');
            if ($name !== '') {
                $models[$name] = $name;
            }
        }

        return [
            'success' => true,
            'message' => sprintf('Connection successful. The sidecar serves %d model(s).', count($models)),
            'models' => $models,
        ];
    }

    protected function probabilityKind(): ProbabilityKind
    {
        return ProbabilityKind::Distribution;
    }

    protected function sendDecision(DecisionSubject $subject, array $questions, string $model, array $options): array
    {
        $questionsPayload = [];
        foreach ($questions as $question) {
            $questionsPayload[$question->key()] = $this->questionPayload($question);
        }

        // The sidecar serves the one model it loaded; it reports which.
        return $this->sendRequest(
            'decide',
            ['state' => (object)$subject->toState(), 'questions' => $questionsPayload],
            'POST',
            $this->resolveRequestTimeout($options),
        );
    }

    protected function readAnswer(DecisionQuestion $question, array $raw): DecisionAnswer
    {
        $key = $question->key();

        if ($question instanceof ChoiceQuestion) {
            $this->requireType($question, $raw, 'choice');

            return DecisionAnswer::choice($key, $this->requireChoice($question, $raw, 'choice'), $this->probabilities($question, $raw));
        }

        if ($question instanceof ScoreQuestion) {
            $this->requireType($question, $raw, 'score');

            return DecisionAnswer::score($key, $this->requireNumber($question, $raw, 'score'), $this->probabilities($question, $raw));
        }

        $this->requireType($question, $raw, 'yes_no');

        return DecisionAnswer::yesNo($key, $this->requireNumber($question, $raw, 'probability_of_yes'));
    }

    /**
     * The sidecar reports validation failures under `detail`.
     *
     * @param array<string, mixed> $error
     */
    protected function extractErrorMessage(array $error): string
    {
        $detail = $error['detail'] ?? null;

        return is_string($detail) && $detail !== '' ? $this->boundedDetail($detail) : parent::extractErrorMessage($error);
    }

    /**
     * @return array<string, mixed>
     */
    private function questionPayload(DecisionQuestion $question): array
    {
        if ($question instanceof ChoiceQuestion) {
            $payload = ['type' => 'choice', 'instructions' => $question->instructions(), 'options' => $question->options];
            if ($question->descriptions !== []) {
                $descriptions = [];
                foreach ($question->options as $option) {
                    if ($question->describe($option) !== '') {
                        $descriptions[$option] = $question->describe($option);
                    }
                }

                $payload['descriptions'] = (object)$descriptions;
            }

            return $payload;
        }

        if ($question instanceof ScoreQuestion) {
            return ['type' => 'score', 'instructions' => $question->instructions(), 'levels' => $question->levels];
        }

        $payload = ['type' => 'yes_no', 'instructions' => $question->instructions()];
        if ($question instanceof YesNoQuestion && $question->yesMeans !== '') {
            $payload['yes'] = $question->yesMeans;
        }

        if ($question instanceof YesNoQuestion && $question->noMeans !== '') {
            $payload['no'] = $question->noMeans;
        }

        return $payload;
    }
}
