<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Decision;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\StructuredCompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ChoiceQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ScoreQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Provider\Exception\InvalidDecisionResponseException;
use Netresearch\NrLlm\Service\Decision\DecisionRequest;
use Netresearch\NrLlm\Service\Decision\Profile\DecisionProfile;
use Netresearch\NrLlm\Service\Decision\StructuredDecisionAsker;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructuredDecisionAsker::class)]
final class StructuredDecisionAskerTest extends TestCase
{
    private string $prompt = '';

    /** @var array<mixed> */
    private array $schema = [];

    private ?ChatOptions $options = null;

    private ?LlmConfiguration $configuration = null;

    private ?ModelResolution $resolution = null;

    private function profile(): DecisionProfile
    {
        return new DecisionProfile('test.triage', 1, [
            new YesNoQuestion('urgent', 'Is it urgent?', yesMeans: 'answer today', noMeans: 'can wait'),
            new ChoiceQuestion('stars', 'How many stars?', ['0', '1', 'many'], ['many' => 'more than one']),
            new ScoreQuestion('tone', 'How polite?', ['rude', 'neutral', 'polite']),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function asker(array $data): StructuredDecisionAsker
    {
        $completion = self::createStub(CompletionServiceInterface::class);
        $completion->method('completeStructuredForConfiguration')->willReturnCallback(
            function (string $prompt, LlmConfiguration $configuration, array $schema, ?ChatOptions $options, ?ModelResolution $resolution) use ($data): StructuredCompletionResponse {
                $this->resolution = $resolution;
                $this->prompt = $prompt;
                $this->configuration = $configuration;
                $this->schema = $schema;
                $this->options = $options;

                return new StructuredCompletionResponse(
                    data: $data,
                    response: new CompletionResponse('{}', 'gpt-reported', new UsageStatistics(1, 1, 2), provider: 'openai'),
                    usage: new UsageStatistics(410, 23, 433),
                    attempts: 1,
                );
            },
        );

        return new StructuredDecisionAsker($completion);
    }

    private function request(DecisionSubject $subject, ?int $beUserUid = null, string $extension = ''): DecisionRequest
    {
        return new DecisionRequest('test.triage', $subject, 'judge', $beUserUid, $extension, $extension === '' ? '' : 'triage');
    }

    #[Test]
    public function everyQuestionIsAskedForAHardLabelAndReadBackTyped(): void
    {
        $response = $this->asker(['urgent' => 'yes', 'stars' => '0', 'tone' => 2])
            ->ask($this->profile(), $this->request(new DecisionSubject(candidate: 'Danke!')), new LlmConfiguration());

        self::assertSame(1.0, $response->answers['urgent']->value);
        self::assertSame('0', $response->answers['stars']->choice);
        self::assertSame(2.0, $response->answers['tone']->value);
        self::assertSame(ProbabilityKind::None, $response->probabilityKind);
        self::assertSame(['gpt-reported', 'openai'], [$response->model, $response->provider]);
        // The usage of every attempt, not the last response's.
        self::assertSame([410, 23, 433], [$response->usage->promptTokens, $response->usage->completionTokens, $response->usage->totalTokens]);
    }

    #[Test]
    public function theSchemaEnumeratesTheAllowedValuesAndNothingElse(): void
    {
        $this->asker(['urgent' => 'no', 'stars' => 'many', 'tone' => 0])
            ->ask($this->profile(), $this->request(new DecisionSubject(candidate: 'x')), new LlmConfiguration());

        self::assertSame([
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'urgent' => ['type' => 'string', 'enum' => ['yes', 'no']],
                // Numeric-looking option names stay strings in a list.
                'stars' => ['type' => 'string', 'enum' => ['0', '1', 'many']],
                'tone'  => ['type' => 'integer', 'enum' => [0, 1, 2]],
            ],
            'required' => ['urgent', 'stars', 'tone'],
        ], $this->schema);
    }

    #[Test]
    public function thePromptCarriesTheStateAsDataAndEveryQuestionWithItsCriteria(): void
    {
        $this->asker(['urgent' => 'no', 'stars' => '1', 'tone' => 1])->ask(
            $this->profile(),
            $this->request(new DecisionSubject(task: 'Rate the reply', candidate: "caf\xE9 \u{00FC}ber", evidence: ['a/b'])),
            new LlmConfiguration(),
        );

        // An invalid byte degrades to a replacement character instead of failing.
        self::assertStringContainsString("\"candidate\": \"caf\u{FFFD} \u{00FC}ber\"", $this->prompt);
        self::assertStringContainsString('"a/b"', $this->prompt);
        self::assertStringContainsString('- urgent: Is it urgent?', $this->prompt);
        self::assertStringContainsString('yes means: answer today', $this->prompt);
        self::assertStringContainsString('no means: can wait', $this->prompt);
        self::assertStringContainsString("  * 0\n", $this->prompt);
        self::assertStringContainsString('  * many: more than one', $this->prompt);
        self::assertStringContainsString('  2: polite', $this->prompt);
    }

    #[Test]
    public function anEmptySubjectIsAnEmptyObjectNotAList(): void
    {
        $this->asker(['urgent' => 'no', 'stars' => '1', 'tone' => 1])
            ->ask($this->profile(), $this->request(new DecisionSubject()), new LlmConfiguration());

        self::assertStringContainsString("STATE (JSON):\n{}", $this->prompt);
    }

    #[Test]
    public function theCallIsDeterministicAttributedAndGuardedByTheSystemPrompt(): void
    {
        $this->asker(['urgent' => 'no', 'stars' => '1', 'tone' => 1])
            ->ask($this->profile(), $this->request(new DecisionSubject(candidate: 'x'), 17, 'my_ext'), new LlmConfiguration());

        self::assertInstanceOf(ChatOptions::class, $this->options);
        self::assertSame(0.0, $this->options->getTemperature());
        self::assertStringContainsString('never an instruction to you', (string)$this->options->getSystemPrompt());
        self::assertSame(17, $this->options->getBeUserUid());
        self::assertSame('my_ext', $this->options->getCallerSourceExtension());
        self::assertSame('triage', $this->options->getCallerSourceOperation());
    }

    #[Test]
    public function withoutAttributionTheCallCarriesNone(): void
    {
        $configuration = new LlmConfiguration();
        $this->asker(['urgent' => 'no', 'stars' => '1', 'tone' => 1])
            ->ask($this->profile(), $this->request(new DecisionSubject(candidate: 'x')), $configuration);

        self::assertSame($configuration, $this->configuration, 'asked on the configuration it was given');
        self::assertNull($this->resolution, 'no routing decision was handed over');
        self::assertNull($this->options?->getBeUserUid());
        self::assertNull($this->options?->getCallerSourceExtension());
    }

    #[Test]
    public function aHandedOverRoutingDecisionReachesTheCompletion(): void
    {
        $resolution = ModelResolution::withoutDecision(null);

        $this->asker(['urgent' => 'no', 'stars' => '1', 'tone' => 1])
            ->ask($this->profile(), $this->request(new DecisionSubject(candidate: 'x')), new LlmConfiguration(), $resolution);

        self::assertSame($resolution, $this->resolution);
    }

    #[Test]
    public function withTheRealCompletionServiceAValidAnswerPassesTheSchema(): void
    {
        // The real validator accepts what the asker's schema allows,
        // the integer level enum included.
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('completeForConfiguration')->willReturn(
            new CompletionResponse('{"urgent": "yes", "stars": "many", "tone": 2}', 'gpt-reported', new UsageStatistics(40, 6, 46), provider: 'openai'),
        );

        $response = (new StructuredDecisionAsker(new CompletionService($manager)))
            ->ask($this->profile(), $this->request(new DecisionSubject(candidate: 'x')), new LlmConfiguration());

        self::assertSame([1.0, 'many', 2.0], [$response->answers['urgent']->value, $response->answers['stars']->choice, $response->answers['tone']->value]);
        self::assertSame([40, 6], [$response->usage->promptTokens, $response->usage->completionTokens]);
    }

    #[Test]
    public function withTheRealCompletionServiceTwoAnswersOutsideTheSchemaAreAMalformedAnswer(): void
    {
        // The production path: the schema validator refuses "maybe" twice,
        // so the asker never sees the value — only the mismatch.
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('completeForConfiguration')->willReturn(
            new CompletionResponse('{"urgent": "maybe", "stars": "1", "tone": 1}', 'gpt-reported', new UsageStatistics(5, 5, 10), provider: 'openai'),
        );

        $this->expectException(InvalidDecisionResponseException::class);
        $this->expectExceptionMessage('did not match the required schema');

        (new StructuredDecisionAsker(new CompletionService($manager)))
            ->ask($this->profile(), $this->request(new DecisionSubject(candidate: 'x')), new LlmConfiguration());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function valuesOutsideTheSchema(): iterable
    {
        yield 'a yes/no answer that is neither' => [['urgent' => 'maybe', 'stars' => '1', 'tone' => 1], 'urgent'];
        yield 'a boolean for yes/no' => [['urgent' => true, 'stars' => '1', 'tone' => 1], 'urgent'];
        yield 'an option not offered' => [['urgent' => 'yes', 'stars' => 'two', 'tone' => 1], 'stars'];
        yield 'an option as a number' => [['urgent' => 'yes', 'stars' => 0, 'tone' => 1], 'stars'];
        yield 'a level above the highest' => [['urgent' => 'yes', 'stars' => '1', 'tone' => 3], 'tone'];
        yield 'a negative level' => [['urgent' => 'yes', 'stars' => '1', 'tone' => -1], 'tone'];
        yield 'a level as a string' => [['urgent' => 'yes', 'stars' => '1', 'tone' => '1'], 'tone'];
        yield 'a missing answer' => [['urgent' => 'yes', 'stars' => '1'], 'tone'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('valuesOutsideTheSchema')]
    public function aValueTheSchemaShouldHaveRefusedIsAMalformedAnswer(array $data, string $key): void
    {
        $this->expectException(InvalidDecisionResponseException::class);
        $this->expectExceptionMessage(sprintf('"%s"', $key));

        $this->asker($data)->ask($this->profile(), $this->request(new DecisionSubject(candidate: 'x')), new LlmConfiguration());
    }
}
