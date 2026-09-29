<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\DecisionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ChoiceQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Provider\Contract\DecisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\InvalidDecisionResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;

/**
 * Base of the providers whose models make decisions and generate nothing
 * (ADR-211).
 *
 * Chat, completion and embeddings throw {@see UnsupportedFeatureException},
 * the refusal the chat providers use for a feature they lack. A subclass
 * builds the request for its API and reads the answers through the shared
 * readers here, which refuse anything but one answer of the right type per
 * question — every such refusal is an {@see InvalidDecisionResponseException}.
 */
abstract class AbstractDecisionProvider extends AbstractProvider implements DecisionCapableInterface
{
    /** Characters of a provider's error detail passed on. */
    private const MAX_DETAIL_LENGTH = 200;

    /** @var array<string> */
    protected array $supportedFeatures = ['decision'];

    /**
     * Send the request and return the decoded response body.
     *
     * @param list<DecisionQuestion> $questions
     * @param array<string, mixed>   $options
     *
     * @return array<string, mixed>
     */
    abstract protected function sendDecision(DecisionSubject $subject, array $questions, string $model, array $options): array;

    /**
     * Read one answer from the provider's answer object.
     *
     * @param array<string, mixed> $raw
     */
    abstract protected function readAnswer(DecisionQuestion $question, array $raw): DecisionAnswer;

    abstract protected function probabilityKind(): ProbabilityKind;

    public function decide(DecisionSubject $subject, array $questions, array $options = []): DecisionResponse
    {
        $model = $this->getString($options, 'model', $this->getDefaultModel());
        $body = $this->sendDecision($subject, $questions, $model, $options);

        $answers = $this->asArray($body['answers'] ?? null);
        $parsed = [];
        foreach ($questions as $question) {
            $raw = $answers[$question->key()] ?? null;
            if (!is_array($raw)) {
                throw InvalidDecisionResponseException::forQuestion($this->getName(), $question->key(), 'no answer');
            }

            try {
                /** @var array<string, mixed> $raw */
                $parsed[$question->key()] = $this->readAnswer($question, $raw);
            } catch (InvalidArgumentException $e) {
                // A value outside its range (a probability above 1) is a
                // malformed answer, not a programming error.
                throw InvalidDecisionResponseException::forQuestion($this->getName(), $question->key(), $e->getMessage());
            }
        }

        $reported = $this->getString($body, 'model');

        return new DecisionResponse(
            answers: $parsed,
            model: $reported !== '' ? $reported : $model,
            usage: $this->usageFrom($body),
            probabilityKind: $this->probabilityKind(),
            provider: $this->getIdentifier(),
        );
    }

    public function chatCompletion(array $messages, array $options = []): CompletionResponse
    {
        throw $this->unsupported('chat');
    }

    public function complete(string $prompt, array $options = []): CompletionResponse
    {
        throw $this->unsupported('completion');
    }

    public function embeddings(string|array $input, array $options = []): EmbeddingResponse
    {
        throw $this->unsupported('embeddings');
    }

    /**
     * The usage block `{input_tokens, output_tokens}` both decision APIs
     * report. Without one the counts stay zero, which the pipeline reads as
     * "not reported" (ADR-174).
     *
     * @param array<string, mixed> $body
     */
    protected function usageFrom(array $body): UsageStatistics
    {
        $usage = $this->asArray($body['usage'] ?? null);

        return $this->createUsageStatistics($this->getInt($usage, 'input_tokens'), $this->getInt($usage, 'output_tokens'));
    }

    /**
     * @param array<string, mixed> $raw
     */
    protected function requireType(DecisionQuestion $question, array $raw, string $expected): void
    {
        if (($raw['type'] ?? null) !== $expected) {
            throw InvalidDecisionResponseException::forQuestion($this->getName(), $question->key(), sprintf('expected a "%s" answer', $expected));
        }
    }

    /**
     * A number the answer must carry.
     *
     * @param array<string, mixed> $raw
     */
    protected function requireNumber(DecisionQuestion $question, array $raw, string $field): float
    {
        return $this->optionalNumber($question, $raw, $field)
            ?? throw InvalidDecisionResponseException::forQuestion($this->getName(), $question->key(), sprintf('no "%s"', $field));
    }

    /**
     * A number the answer may carry; null when absent, never 0.
     *
     * @param array<string, mixed> $raw
     */
    protected function optionalNumber(DecisionQuestion $question, array $raw, string $field): ?float
    {
        $value = $raw[$field] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_int($value) && !is_float($value)) {
            throw InvalidDecisionResponseException::forQuestion($this->getName(), $question->key(), sprintf('"%s" is not a number', $field));
        }

        return (float)$value;
    }

    /**
     * The chosen option, which must be one the question offers.
     *
     * @param array<string, mixed> $raw
     */
    protected function requireChoice(ChoiceQuestion $question, array $raw, string $field): string
    {
        $choice = $raw[$field] ?? null;
        if (!is_string($choice) || !in_array($choice, $question->options, true)) {
            throw InvalidDecisionResponseException::forQuestion($this->getName(), $question->key(), 'no chosen option the question offers');
        }

        return $choice;
    }

    /**
     * The per-option or per-level probabilities, keyed exactly by what the
     * question offers: an option name, or a level index 0..max.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<array-key, float>
     */
    protected function probabilities(DecisionQuestion $question, array $raw): array
    {
        $map = $raw['probabilities'] ?? [];
        if (!is_array($map)) {
            throw InvalidDecisionResponseException::forQuestion($this->getName(), $question->key(), '"probabilities" is not a map');
        }

        $allowed = match (true) {
            $question instanceof ChoiceQuestion => $question->options,
            $question instanceof ScoreQuestion  => array_map(strval(...), range(0, $question->maxLevel())),
            default                             => [],
        };

        $probabilities = [];
        foreach ($map as $name => $probability) {
            if (!in_array((string)$name, $allowed, true)) {
                throw InvalidDecisionResponseException::forQuestion($this->getName(), $question->key(), sprintf('a probability for "%s", which the question does not offer', $name));
            }

            if (!is_int($probability) && !is_float($probability)) {
                throw InvalidDecisionResponseException::forQuestion($this->getName(), $question->key(), sprintf('the probability of "%s" is not a number', $name));
            }

            $probabilities[$name] = (float)$probability;
        }

        return $probabilities;
    }

    /**
     * A provider's error detail, bounded: it travels on into exception
     * messages, logs, evaluation rows and the backend's error toast, and a
     * provider that echoes the request back would otherwise carry subject
     * text along with it.
     */
    protected function boundedDetail(string $detail): string
    {
        return mb_strlen($detail) > self::MAX_DETAIL_LENGTH
            ? mb_substr($detail, 0, self::MAX_DETAIL_LENGTH) . '…'
            : $detail;
    }

    private function unsupported(string $feature): UnsupportedFeatureException
    {
        return new UnsupportedFeatureException(
            sprintf('%s makes decisions only; it does not support %s.', $this->getName(), $feature),
            1795211060,
        );
    }
}
