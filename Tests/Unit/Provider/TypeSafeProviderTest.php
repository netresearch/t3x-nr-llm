<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\ValueObject\Decision\ChoiceQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Domain\ValueObject\Decision\QuestionType;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;
use Netresearch\NrLlm\Provider\AbstractDecisionProvider;
use Netresearch\NrLlm\Provider\Exception\InvalidDecisionResponseException;
use Netresearch\NrLlm\Provider\Exception\ProviderAuthenticationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderRateLimitException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\TypeSafeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Throwable;

#[CoversClass(TypeSafeProvider::class)]
#[CoversClass(AbstractDecisionProvider::class)]
#[CoversClass(InvalidDecisionResponseException::class)]
final class TypeSafeProviderTest extends AbstractDecisionProviderTestCase
{
    /**
     * @param list<ResponseInterface> $responses
     */
    private function typeSafe(array $responses, int $maxRetries = 0): TypeSafeProvider
    {
        $provider = $this->provider(TypeSafeProvider::class, $responses, ['apiKeyIdentifier' => 'vault-typesafe', 'maxRetries' => $maxRetries]);
        self::assertInstanceOf(TypeSafeProvider::class, $provider);

        return $provider;
    }

    /**
     * @return list<DecisionQuestion>
     */
    private function questions(): array
    {
        return [
            new YesNoQuestion('supported', 'Is every claim supported?', yesMeans: 'all claims have a source', noMeans: 'one claim has none'),
            new ChoiceQuestion('team', 'Which team handles it?', ['0', 'billing', 'tech'], ['billing' => 'invoices and payments']),
            new ScoreQuestion('quality', 'How good is the answer?', ['useless', 'partial', 'complete']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function answers(): array
    {
        return [
            'model'   => 'jev-1.13.0',
            'answers' => [
                'supported' => ['type' => 'noul', 'noul' => 0.91],
                'team'      => ['type' => 'choice', 'choice' => 'billing', 'probabilities' => ['0' => 0.05, 'billing' => 0.8, 'tech' => 0.15], 'confidence' => 0.64],
                'quality'   => ['type' => 'score', 'score' => 1.6, 'probabilities' => ['0' => 0.1, '1' => 0.2, '2' => 0.7], 'confidence' => 0.42],
            ],
            'usage' => ['input_tokens' => 317, 'output_tokens' => 9],
        ];
    }

    #[Test]
    public function theRequestCarriesTheStateAndOneTypedQuestionPerKey(): void
    {
        $this->typeSafe([$this->ok(self::answers())])->decide(
            new DecisionSubject(task: 'Q', candidate: 'A', evidence: ['S1', 'S2']),
            $this->questions(),
            ['model' => 'jev-1.13.0'],
        );

        self::assertSame('POST', $this->sent[0]['method']);
        self::assertSame('https://api.typesafe.ai/v1/systemone', $this->sent[0]['url']);
        self::assertSame([
            'model'     => 'jev-1.13.0',
            'state'     => ['task' => 'Q', 'candidate' => 'A', 'evidence' => ['S1', 'S2']],
            'questions' => [
                'supported' => ['type' => 'noul', 'instructions' => 'Is every claim supported?', 'criteria' => ['true' => 'all claims have a source', 'false' => 'one claim has none']],
                // A numeric option name stays a name: the criteria are an
                // object, never a list, and an undescribed option is null.
                'team'    => ['type' => 'choice', 'instructions' => 'Which team handles it?', 'criteria' => ['0' => null, 'billing' => 'invoices and payments', 'tech' => null]],
                'quality' => ['type' => 'score', 'instructions' => 'How good is the answer?', 'criteria' => ['useless', 'partial', 'complete']],
            ],
        ], $this->sentBody());
    }

    #[Test]
    public function aYesNoQuestionWithoutMeaningsSendsNoCriteriaAndAnEmptySubjectAnEmptyState(): void
    {
        $this->typeSafe([$this->ok(['answers' => ['ok' => ['type' => 'noul', 'noul' => 0.5]]])])
            ->decide(new DecisionSubject(), [new YesNoQuestion('ok', 'Ok?')]);

        $body = $this->sentBody();
        self::assertSame('', $body['state']);
        self::assertSame(['ok' => ['type' => 'noul', 'instructions' => 'Ok?']], $body['questions']);
        self::assertSame(TypeSafeProvider::DEFAULT_MODEL, $body['model'], 'the pinned version, never a moving alias');
    }

    #[Test]
    public function aYesNoQuestionWithOneMeaningSendsOnlyThatOne(): void
    {
        $this->typeSafe([$this->ok(['answers' => ['ok' => ['type' => 'noul', 'noul' => 0.5]]])])
            ->decide(new DecisionSubject(candidate: 'A'), [new YesNoQuestion('ok', 'Ok?', noMeans: 'it is not')]);

        self::assertSame(['type' => 'noul', 'instructions' => 'Ok?', 'criteria' => ['false' => 'it is not']], $this->sentBody()['questions']['ok'] ?? null);
    }

    #[Test]
    public function everyAnswerIsReadWithWhatTheProviderMeasuredAndNothingMore(): void
    {
        $response = $this->typeSafe([$this->ok(self::answers())])
            ->decide(new DecisionSubject(candidate: 'A'), $this->questions(), ['model' => 'jev-latest']);

        $supported = $response->answers['supported'];
        self::assertSame([QuestionType::YesNo, 0.91, [], null], [$supported->type, $supported->value, $supported->probabilities, $supported->confidence]);

        $team = $response->answers['team'];
        self::assertSame(['billing', ['0' => 0.05, 'billing' => 0.8, 'tech' => 0.15], 0.64], [$team->choice, $team->probabilities, $team->confidence]);

        $quality = $response->answers['quality'];
        self::assertSame([1.6, [0 => 0.1, 1 => 0.2, 2 => 0.7], 0.42], [$quality->value, $quality->probabilities, $quality->confidence]);

        // The model the provider reported, not the alias that was sent.
        self::assertSame('jev-1.13.0', $response->model);
        self::assertSame('typesafe', $response->provider);
        self::assertSame(ProbabilityKind::Calibrated, $response->probabilityKind);
        self::assertSame([317, 9, 326], [$response->usage->promptTokens, $response->usage->completionTokens, $response->usage->totalTokens]);
    }

    #[Test]
    public function withoutAReportedModelTheModelSentIsNamedAndWithoutUsageNothingIsCounted(): void
    {
        $response = $this->typeSafe([$this->ok(['answers' => ['ok' => ['type' => 'noul', 'noul' => 1]]])])
            ->decide(new DecisionSubject(candidate: 'A'), [new YesNoQuestion('ok', 'Ok?')], ['model' => 'jev-preview']);

        self::assertSame('jev-preview', $response->model);
        self::assertSame(1.0, $response->answers['ok']->value);
        self::assertSame([0, 0, 0], [$response->usage->promptTokens, $response->usage->completionTokens, $response->usage->totalTokens]);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string, string}>
     */
    public static function malformedAnswers(): iterable
    {
        $valid = self::answers()['answers'];
        self::assertIsArray($valid);

        yield 'no answer' => [array_diff_key($valid, ['team' => true]), 'team', 'no answer'];
        yield 'an answer that is no object' => [[...$valid, 'team' => 'billing'], 'team', 'no answer'];
        yield 'the wrong answer type' => [[...$valid, 'supported' => ['type' => 'score', 'score' => 1]], 'supported', 'expected a "noul" answer'];
        yield 'no value' => [[...$valid, 'supported' => ['type' => 'noul']], 'supported', 'no "noul"'];
        yield 'a value that is no number' => [[...$valid, 'supported' => ['type' => 'noul', 'noul' => '0.9']], 'supported', '"noul" is not a number'];
        yield 'a probability above one' => [[...$valid, 'supported' => ['type' => 'noul', 'noul' => 1.2]], 'supported', 'between 0 and 1'];
        yield 'an option not offered' => [[...$valid, 'team' => ['type' => 'choice', 'choice' => 'sales']], 'team', 'no chosen option the question offers'];
        yield 'a probability for an option not offered' => [[...$valid, 'team' => ['type' => 'choice', 'choice' => 'tech', 'probabilities' => ['sales' => 1.0]]], 'team', '"sales"'];
        yield 'probabilities that are no map' => [[...$valid, 'team' => ['type' => 'choice', 'choice' => 'tech', 'probabilities' => 0.9]], 'team', 'is not a map'];
        yield 'a probability that is no number' => [[...$valid, 'team' => ['type' => 'choice', 'choice' => 'tech', 'probabilities' => ['tech' => 'high']]], 'team', 'is not a number'];
        yield 'a level not offered' => [[...$valid, 'quality' => ['type' => 'score', 'score' => 1.0, 'probabilities' => ['3' => 1.0]]], 'quality', '"3"'];
        yield 'a confidence that is no number' => [[...$valid, 'quality' => ['type' => 'score', 'score' => 1.0, 'confidence' => 'sure']], 'quality', '"confidence" is not a number'];
    }

    /**
     * @param array<array-key, mixed> $answers
     */
    #[Test]
    #[DataProvider('malformedAnswers')]
    public function aMalformedAnswerIsRefusedNamingItsQuestion(array $answers, string $key, string $reason): void
    {
        try {
            $this->typeSafe([$this->ok(['answers' => $answers])])->decide(new DecisionSubject(candidate: 'A'), $this->questions());
            self::fail('expected an InvalidDecisionResponseException');
        } catch (InvalidDecisionResponseException $e) {
            self::assertStringContainsString(sprintf('"%s"', $key), $e->getMessage());
            self::assertStringContainsString($reason, $e->getMessage());
            self::assertStringContainsString('TypeSafe', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>, class-string<Throwable>, string}>
     */
    public static function errorResponses(): iterable
    {
        yield '401' => [401, ['detail' => 'Invalid API key'], ProviderAuthenticationException::class, 'Invalid API key'];
        yield '422 with a message' => [422, ['detail' => 'state too long'], ProviderResponseException::class, 'state too long'];
        yield '422 in the FastAPI shape' => [422, ['detail' => [['msg' => 'field required'], ['msg' => 'too many options']]], ProviderResponseException::class, 'field required; too many options'];
        yield '429' => [429, ['detail' => 'rate limited'], ProviderRateLimitException::class, 'rate limited'];
        yield '529' => [529, ['detail' => 'overloaded'], ProviderConnectionException::class, '529'];
    }

    /**
     * @param array<string, mixed>    $body
     * @param class-string<Throwable> $expected
     */
    #[Test]
    #[DataProvider('errorResponses')]
    public function anErrorStatusBecomesTheTypedProviderException(int $status, array $body, string $expected, string $message): void
    {
        // One retry allowed: a refused request (4xx) must not use it, an
        // overload (5xx) is a transient failure the provider retries.
        $refused = $status < 500;
        try {
            $this->typeSafe([$this->createJsonResponseMock($body, $status), $this->createJsonResponseMock($body, $status)], 1)
                ->decide(new DecisionSubject(candidate: 'A'), [new YesNoQuestion('ok', 'Ok?')]);
            self::fail('expected ' . $expected);
        } catch (Throwable $e) {
            self::assertInstanceOf($expected, $e);
            self::assertStringContainsString($message, $e->getMessage());
            if ($e instanceof ProviderResponseException) {
                self::assertSame($status, $e->httpStatus);
            }
        }

        self::assertSame($refused ? 1 : 2, $this->dispatches, $refused ? 'a refused request is not retried' : 'an overload is retried once');
    }

    #[Test]
    public function aLongErrorDetailIsBoundedBeforeItTravelsOn(): void
    {
        // A provider that echoes the request back must not carry the subject along.
        $echo = str_repeat('the subject text ', 50);

        try {
            $this->typeSafe([$this->createJsonResponseMock(['detail' => $echo], 422)])
                ->decide(new DecisionSubject(candidate: 'A'), [new YesNoQuestion('ok', 'Ok?')]);
            self::fail('expected a ProviderResponseException');
        } catch (ProviderResponseException $e) {
            self::assertLessThanOrEqual(201, mb_strlen($e->getMessage()));
            self::assertStringEndsWith('…', $e->getMessage());
        }
    }

    #[Test]
    public function theConnectionTestListsTheModelsTheAccountMaySend(): void
    {
        $result = $this->typeSafe([$this->ok(['models' => [
            ['name' => 'jev-latest', 'description' => 'latest stable', 'release_date' => '2026-06-01'],
            ['name' => 'jev-preview'],
            ['description' => 'no name'],
        ]])])->testConnection();

        self::assertSame('GET', $this->sent[0]['method']);
        self::assertSame('https://api.typesafe.ai/v1/models', $this->sent[0]['url']);
        self::assertTrue($result['success']);
        self::assertSame(['jev-latest' => 'jev-latest', 'jev-preview' => 'jev-preview'], $result['models']);
        self::assertStringContainsString('2 models', $result['message']);
    }

    #[Test]
    public function itGeneratesNoText(): void
    {
        $provider = $this->typeSafe([]);

        foreach ([
            'chat'       => static fn(): mixed => $provider->chatCompletion([]),
            'completion' => static fn(): mixed => $provider->complete('hi'),
            'embeddings' => static fn(): mixed => $provider->embeddings('hi'),
        ] as $feature => $call) {
            try {
                $call();
                self::fail($feature . ' must be refused');
            } catch (UnsupportedFeatureException $e) {
                self::assertSame(1795211060, $e->getCode());
                self::assertStringContainsString($feature, $e->getMessage());
            }
        }

        self::assertSame([], $this->sent);
    }

    #[Test]
    public function itIdentifiesItselfAndOffersThePinnedVersionAndTheAliases(): void
    {
        $provider = $this->typeSafe([]);

        self::assertSame('typesafe', $provider->getIdentifier());
        self::assertSame('TypeSafe', $provider->getName());
        self::assertSame('jev-1.13.0', $provider->getDefaultModel());
        self::assertSame(['jev-1.13.0', 'jev-latest', 'jev-preview'], array_keys($provider->getAvailableModels()));
    }
}
