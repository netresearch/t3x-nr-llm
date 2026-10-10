<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\EvaluationService;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use Netresearch\NrLlm\Service\Evaluation\Grader\DecisionGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\GradingService;
use Netresearch\NrLlm\Service\Evaluation\PromptEvaluation;
use Netresearch\NrLlm\Service\Evaluation\SetEvaluationResult;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Testing\FakeDecisionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EvaluationService::class)]
#[CoversClass(PromptEvaluation::class)]
#[CoversClass(SetEvaluationResult::class)]
final class GeneratorEvaluationAttributionTest extends TestCase
{
    /**
     * @param list<array{?string,string,string,string}> $identities
     */
    #[Test]
    #[DataProvider('generatorRuns')]
    public function onlyOneVerifiedServingGeneratorReceivesTheWholeRun(
        array $identities,
        bool $eligible,
    ): void {
        $responses = [];
        $prompts = [];
        foreach ($identities as $index => [$provider, $alias, $reported, $responseModel]) {
            $metadata = $provider === null ? null : [
                GeneratorProvenance::METADATA_KEY => [
                    'version' => 1,
                    'providerIdentifier' => $provider,
                    'modelId' => $alias,
                    'reportedModelId' => $reported,
                ],
            ];
            $responses[] = new CompletionResponse(
                $index === 0 ? 'BAD' : 'ACK',
                $responseModel,
                new UsageStatistics(0, 0, 0),
                provider: 'openai',
                metadata: $metadata,
            );
            $prompts[] = new GoldenPrompt(
                'p' . $index,
                'Reply ACK',
                [Assertion::contains('ACK')],
            );
        }

        $completion = $this->createMock(CompletionServiceInterface::class);
        if ($responses === []) {
            $completion->expects(self::never())->method('complete');
        } else {
            $completion
                ->expects(self::exactly(count($responses)))
                ->method('complete')
                ->willReturnOnConsecutiveCalls(...$responses);
        }

        $service = new EvaluationService(
            $completion,
            new GradingService(
                new DeterministicGrader(),
                new DecisionGrader(new FakeDecisionService()),
            ),
        );
        $result = $service->run(
            new GoldenPromptSet('test', 'Test', 'description', $prompts),
            baseOptions: (new ChatOptions())->withModel('requested-model'),
        );
        self::assertSame($eligible ? 'served-alias' : '', $result->model);
        self::assertSame(count($responses), $result->promptCount());
        self::assertTrue($result->sharesOneYardstick());
        self::assertSame('deterministic', $result->grader);
        self::assertSame($responses === [] ? 0.0 : 0.5, $result->meanScore());
        self::assertSame($responses === [] ? 0.0 : 0.5, $result->passRate());
        $provenance = $result->generatorProvenance ?? null;
        if ($eligible) {
            self::assertInstanceOf(GeneratorProvenance::class, $provenance);
            self::assertSame('instance-a', $provenance->providerIdentifier);
            self::assertSame('reported-snapshot', $provenance->reportedModelId);
            self::assertSame(
                $provenance->toArray(),
                $result->toSummary()->generatorProvenance?->toArray(),
            );
            foreach ($result->evaluations as $evaluation) {
                self::assertSame(
                    $provenance->toArray(),
                    $evaluation->generatorProvenance?->toArray(),
                );
                self::assertSame('openai', $evaluation->reportedAdapterKey);
                self::assertSame(
                    $provenance->toArray(),
                    $evaluation->toArray()['generatorProvenance'],
                );
            }
        } else {
            self::assertNull($provenance);
            self::assertNull($result->toSummary()->generatorProvenance);
        }
    }

    /**
     * @return iterable<string,array{list<array{?string,string,string,string}>,bool}>
     */
    public static function generatorRuns(): iterable
    {
        $one = ['instance-a', 'served-alias', 'reported-snapshot', 'reported-snapshot'];
        yield 'same verified generator' => [[$one, $one], true];
        yield 'different provider instances' => [
            [
                $one,
                [
                    'instance-b',
                    'served-alias',
                    'reported-snapshot',
                    'reported-snapshot',
                ],
            ],
            false,
        ];
        yield 'different outbound aliases' => [
            [
                $one,
                [
                    'instance-a',
                    'another-alias',
                    'reported-snapshot',
                    'reported-snapshot',
                ],
            ],
            false,
        ];
        yield 'different reported snapshots' => [
            [
                $one,
                ['instance-a', 'served-alias', 'new-snapshot', 'new-snapshot'],
            ],
            false,
        ];
        yield 'unknown first response' => [
            [
                [null, 'served-alias', 'reported-snapshot', 'reported-snapshot'],
                $one,
            ],
            false,
        ];
        yield 'unknown last response' => [
            [
                $one,
                [null, 'served-alias', 'reported-snapshot', 'reported-snapshot'],
            ],
            false,
        ];
        yield 'record disagrees with response' => [
            [
                $one,
                [
                    'instance-a',
                    'served-alias',
                    'forged-snapshot',
                    'reported-snapshot',
                ],
            ],
            false,
        ];
        yield 'uniform record disagrees with every response' => [
            [
                [
                    'instance-a',
                    'served-alias',
                    'forged-snapshot',
                    'reported-snapshot',
                ],
                [
                    'instance-a',
                    'served-alias',
                    'forged-snapshot',
                    'reported-snapshot',
                ],
            ],
            false,
        ];
    }
}
