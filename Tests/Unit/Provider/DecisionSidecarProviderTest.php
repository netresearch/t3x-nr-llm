<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\ValueObject\Decision\ChoiceQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;
use Netresearch\NrLlm\Provider\DecisionSidecarProvider;
use Netresearch\NrLlm\Provider\Exception\InvalidDecisionResponseException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(DecisionSidecarProvider::class)]
final class DecisionSidecarProviderTest extends AbstractDecisionProviderTestCase
{
    private const ENDPOINT = 'https://decision.example.test:8082';

    /**
     * @param list<ResponseInterface> $responses
     * @param array<string, mixed>    $config
     */
    private function sidecar(array $responses, array $config = ['baseUrl' => self::ENDPOINT]): DecisionSidecarProvider
    {
        $provider = $this->provider(DecisionSidecarProvider::class, $responses, $config);
        self::assertInstanceOf(DecisionSidecarProvider::class, $provider);

        return $provider;
    }

    #[Test]
    public function theRequestCarriesTheStateAndOneQuestionPerKeyInTheSidecarsProtocol(): void
    {
        $this->sidecar([$this->ok([
            'model'   => DecisionSidecarProvider::DEFAULT_MODEL,
            'answers' => [
                'urgent' => ['type' => 'yes_no', 'probability_of_yes' => 0.73],
                'team'   => ['type' => 'choice', 'choice' => '1', 'probabilities' => ['1' => 0.6, 'tech' => 0.4]],
                'tone'   => ['type' => 'score', 'score' => 0.8, 'probabilities' => ['0' => 0.2, '1' => 0.8]],
            ],
            'usage' => ['input_tokens' => 58, 'output_tokens' => 0],
        ])])->decide(
            new DecisionSubject(candidate: 'Die Rechnung ist falsch.'),
            [
                new YesNoQuestion('urgent', 'Is it urgent?', yesMeans: 'needs an answer today'),
                new ChoiceQuestion('team', 'Which team?', ['1', 'tech'], ['tech' => 'software faults']),
                new ScoreQuestion('tone', 'How polite?', ['rude', 'polite']),
            ],
        );

        self::assertSame('POST', $this->sent[0]['method']);
        self::assertSame(self::ENDPOINT . '/decide', $this->sent[0]['url']);
        self::assertSame([
            'state'     => ['candidate' => 'Die Rechnung ist falsch.'],
            'questions' => [
                'urgent' => ['type' => 'yes_no', 'instructions' => 'Is it urgent?', 'yes' => 'needs an answer today'],
                // Only described options carry a description; the option
                // list stays a list of names, numeric or not.
                'team' => ['type' => 'choice', 'instructions' => 'Which team?', 'options' => ['1', 'tech'], 'descriptions' => ['tech' => 'software faults']],
                'tone' => ['type' => 'score', 'instructions' => 'How polite?', 'levels' => ['rude', 'polite']],
            ],
        ], $this->sentBody());
    }

    #[Test]
    public function itsProbabilitiesAreAModelDistributionNotACalibration(): void
    {
        $response = $this->sidecar([$this->ok([
            'model'   => 'local-nli',
            'answers' => ['ok' => ['type' => 'yes_no', 'probability_of_yes' => 0.25]],
            'usage'   => ['input_tokens' => 12, 'output_tokens' => 0],
        ])])->decide(new DecisionSubject(candidate: 'x'), [new YesNoQuestion('ok', 'Ok?', noMeans: 'not ok')]);

        self::assertSame(ProbabilityKind::Distribution, $response->probabilityKind);
        self::assertSame('decision_sidecar', $response->provider);
        self::assertSame('local-nli', $response->model);
        self::assertSame(0.25, $response->answers['ok']->value);
        self::assertNull($response->answers['ok']->confidence, 'the sidecar reports no confidence');
        self::assertSame(['type' => 'yes_no', 'instructions' => 'Ok?', 'no' => 'not ok'], $this->sentBody()['questions']['ok'] ?? null);
    }

    #[Test]
    public function anEmptySubjectGoesOutAsAnEmptyObjectAndTheSidecarRefusesIt(): void
    {
        // What the real sidecar answers: an NLI premise needs a field to read.
        try {
            $this->sidecar([$this->createJsonResponseMock(['detail' => 'state must contain at least one non-empty field'], 422)])
                ->decide(new DecisionSubject(), [new YesNoQuestion('ok', 'Ok?')]);
            self::fail('expected the refusal');
        } catch (ProviderResponseException $e) {
            self::assertSame(422, $e->httpStatus);
        }

        // An object, never a list: `[]` would not be a state at all.
        self::assertStringContainsString('"state":{}', $this->sent[0]['raw']);
        self::assertSame(['ok' => ['type' => 'yes_no', 'instructions' => 'Ok?']], $this->sentBody()['questions']);
    }

    #[Test]
    public function anAnswerInAnotherProtocolIsRefused(): void
    {
        $this->expectException(InvalidDecisionResponseException::class);
        $this->expectExceptionMessage('expected a "yes_no" answer');

        $this->sidecar([$this->ok(['answers' => ['ok' => ['type' => 'noul', 'noul' => 0.5]]])])
            ->decide(new DecisionSubject(candidate: 'x'), [new YesNoQuestion('ok', 'Ok?')]);
    }

    #[Test]
    public function aValidationFailureCarriesTheSidecarsDetail(): void
    {
        try {
            $this->sidecar([$this->createJsonResponseMock(['detail' => 'the state carries no non-empty field'], 422)])
                ->decide(new DecisionSubject(candidate: ''), [new YesNoQuestion('ok', 'Ok?')]);
            self::fail('expected a ProviderResponseException');
        } catch (ProviderResponseException $e) {
            self::assertSame(422, $e->httpStatus);
            self::assertStringContainsString('the state carries no non-empty field', $e->getMessage());
        }
    }

    #[Test]
    public function withoutAConfiguredEndpointTheComposeServiceIsAsked(): void
    {
        $provider = $this->sidecar([$this->ok(['models' => [['name' => 'local-nli'], ['name' => '']]])], []);

        self::assertTrue($provider->isAvailable());
        $result = $provider->testConnection();

        self::assertSame('GET', $this->sent[0]['method']);
        self::assertSame('http://decision:8082/models', $this->sent[0]['url']);
        self::assertSame(['local-nli' => 'local-nli'], $result['models']);
        self::assertStringContainsString('1 model', $result['message']);
    }

    #[Test]
    public function itIdentifiesItselfAndOffersItsDefaultModel(): void
    {
        $provider = $this->sidecar([]);

        self::assertSame('decision_sidecar', $provider->getIdentifier());
        self::assertSame(DecisionSidecarProvider::DEFAULT_MODEL, $provider->getDefaultModel());
        self::assertSame([DecisionSidecarProvider::DEFAULT_MODEL], array_keys($provider->getAvailableModels()));
    }
}
