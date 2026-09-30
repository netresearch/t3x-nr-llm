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
use stdClass;

/**
 * TypeSafe's System One API (`POST /v1/systemone`, ADR-211).
 *
 * Its models — Jev — answer typed questions with a calibrated distribution
 * and generate no text. The subject becomes the `state`, each question a
 * `noul` (yes/no), `choice` or `score` question; the criteria come from the
 * profile only. Billed per input token, priced through the model record like
 * every other model.
 *
 * The documentation's limits are enforced before the request by the question
 * value objects (255 options, 2 to 10 levels); a request TypeSafe still
 * refuses answers 422, which reaches the caller as a rejected request.
 */
final class TypeSafeProvider extends AbstractDecisionProvider
{
    public const IDENTIFIER = 'typesafe';

    /**
     * Pinned, not the moving `jev-latest` alias: thresholds tuned against one
     * version must not change under a caller without a configuration change.
     */
    public const DEFAULT_MODEL = 'jev-1.13.0';

    /** @var array<string, string> the versioned model and the two aliases the API documents */
    private const MODELS = [
        'jev-1.13.0'  => 'Jev 1.13',
        'jev-latest'  => 'Jev (latest stable, moves without notice)',
        'jev-preview' => 'Jev (preview, moves without notice)',
    ];

    public function getName(): string
    {
        return 'TypeSafe';
    }

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.typesafe.ai/v1';
    }

    public function getDefaultModel(): string
    {
        return $this->defaultModel !== '' ? $this->defaultModel : self::DEFAULT_MODEL;
    }

    /**
     * @return array<string, string>
     */
    public function getAvailableModels(): array
    {
        return self::MODELS;
    }

    /**
     * `GET /v1/models` lists the aliases the account may send; the versioned
     * id is accepted whether or not it is listed.
     *
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
            'message' => sprintf('Connection successful. Found %d models.', count($models)),
            'models' => $models,
        ];
    }

    protected function probabilityKind(): ProbabilityKind
    {
        return ProbabilityKind::Calibrated;
    }

    protected function sendDecision(DecisionSubject $subject, array $questions, string $model, array $options): array
    {
        $state = $subject->toState();
        $payload = [
            'model'     => $model,
            // TypeSafe requires a state; a profile that requires no subject
            // field asks its questions about nothing but their instructions.
            'state'     => $state === [] ? '' : $state,
            'questions' => $this->questionsPayload($questions),
        ];

        return $this->sendRequest('systemone', $payload, 'POST', $this->resolveRequestTimeout($options));
    }

    protected function readAnswer(DecisionQuestion $question, array $raw): DecisionAnswer
    {
        $key = $question->key();

        if ($question instanceof ChoiceQuestion) {
            $this->requireType($question, $raw, 'choice');

            return DecisionAnswer::choice(
                $key,
                $this->requireChoice($question, $raw, 'choice'),
                $this->probabilities($question, $raw),
                $this->optionalNumber($question, $raw, 'confidence'),
            );
        }

        if ($question instanceof ScoreQuestion) {
            $this->requireType($question, $raw, 'score');

            return DecisionAnswer::score(
                $key,
                $this->requireNumber($question, $raw, 'score'),
                $this->probabilities($question, $raw),
                $this->optionalNumber($question, $raw, 'confidence'),
            );
        }

        // TypeSafe reports no confidence for a yes/no answer; none is derived.
        $this->requireType($question, $raw, 'noul');

        return DecisionAnswer::yesNo($key, $this->requireNumber($question, $raw, 'noul'));
    }

    /**
     * TypeSafe reports validation failures under `detail` — a string, or a
     * list of `{msg}` entries in the FastAPI shape.
     *
     * @param array<string, mixed> $error
     */
    protected function extractErrorMessage(array $error): string
    {
        $detail = $error['detail'] ?? null;
        if (is_string($detail) && $detail !== '') {
            return $this->boundedDetail($detail);
        }

        if (is_array($detail)) {
            $messages = array_filter(array_map(
                static fn(mixed $entry): string => is_array($entry) && is_string($entry['msg'] ?? null) ? $entry['msg'] : '',
                $detail,
            ));
            if ($messages !== []) {
                return $this->boundedDetail(implode('; ', $messages));
            }
        }

        return parent::extractErrorMessage($error);
    }

    /**
     * @param list<DecisionQuestion> $questions
     *
     * @return array<string, array<string, mixed>>
     */
    private function questionsPayload(array $questions): array
    {
        $payload = [];
        foreach ($questions as $question) {
            $payload[$question->key()] = $this->questionPayload($question);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function questionPayload(DecisionQuestion $question): array
    {
        if ($question instanceof ChoiceQuestion) {
            // An object, never an array: options named "0", "1" would be
            // integer keys in a PHP array and encode as a JSON list.
            $criteria = new stdClass();
            foreach ($question->options as $option) {
                $description = $question->describe($option);
                // An option without a description is null, as TypeSafe documents.
                $criteria->{$option} = $description === '' ? null : $description;
            }

            return ['type' => 'choice', 'instructions' => $question->instructions(), 'criteria' => $criteria];
        }

        if ($question instanceof ScoreQuestion) {
            return ['type' => 'score', 'instructions' => $question->instructions(), 'criteria' => $question->levels];
        }

        $payload = ['type' => 'noul', 'instructions' => $question->instructions()];
        if ($question instanceof YesNoQuestion && ($question->yesMeans !== '' || $question->noMeans !== '')) {
            $payload['criteria'] = array_filter(
                ['true' => $question->yesMeans, 'false' => $question->noMeans],
                static fn(string $meaning): bool => $meaning !== '',
            );
        }

        return $payload;
    }
}
