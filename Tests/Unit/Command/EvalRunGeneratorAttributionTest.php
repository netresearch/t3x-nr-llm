<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\EvalRunCommand;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultSummary;
use Netresearch\NrLlm\Service\Evaluation\EvaluationService;
use Netresearch\NrLlm\Service\Evaluation\GeneratorEvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetProviderInterface;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetRegistry;
use Netresearch\NrLlm\Service\Evaluation\Grader\DecisionGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\GradingService;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Service\Evaluation\SetEvaluationResult;
use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Testing\FakeDecisionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(EvalRunCommand::class)]
final class EvalRunGeneratorAttributionTest extends TestCase
{
    private function record(
        string $provider = 'custom-instance',
    ): GeneratorProvenance {
        $record = GeneratorProvenance::fromArray(
            [
                'version' => 1,
                'providerIdentifier' => $provider,
                'modelId' => 'served-alias',
                'reportedModelId' => 'reported-snapshot',
            ],
        );
        self::assertInstanceOf(GeneratorProvenance::class, $record);
        return $record;
    }

    /**
     * @param list<?GeneratorProvenance> $records
     */
    private function tester(
        array $records,
        EvaluationResultRepositoryInterface $repository,
    ): CommandTester {
        $completion = $this->createMock(CompletionServiceInterface::class);
        $responses = array_map(
            static fn(
                ?GeneratorProvenance $record,
            ): CompletionResponse => new CompletionResponse(
                'ACK',
                'reported-snapshot',
                new UsageStatistics(0, 0, 0),
                provider: 'openai',
                metadata: $record instanceof GeneratorProvenance ? [GeneratorProvenance::METADATA_KEY => $record->toArray()] : null,
            ),
            $records,
        );
        $completion
            ->expects(self::exactly(count($records)))
            ->method('complete')
            ->willReturnOnConsecutiveCalls(...$responses);
        $prompts = array_map(
            static fn(int $i): GoldenPrompt => new GoldenPrompt(
                'p' . $i,
                'Reply ACK',
                [Assertion::contains('ACK')],
            ),
            array_keys($records),
        );
        $setProvider = self::createStub(GoldenPromptSetProviderInterface::class);
        $setProvider
            ->method('getGoldenPromptSets')
            ->willReturn([new GoldenPromptSet('fixture', 'Fixture', 'description', $prompts)]);
        return new CommandTester(
            new EvalRunCommand(
                new GoldenPromptSetRegistry([$setProvider]),
                new EvaluationService(
                    $completion,
                    new GradingService(
                        new DeterministicGrader(),
                        new DecisionGrader(new FakeDecisionService()),
                    ),
                ),
                $repository,
                new RegressionDetector(),
            ),
        );
    }

    /**
     * @return iterable<string,array{string,bool,int}>
     */
    public static function nonComparableRuns(): iterable
    {
        foreach (['unknown-first', 'unknown-last', 'different-instance'] as $identity) {
            yield $identity . ' optional' => [$identity, false, Command::SUCCESS];
            yield $identity . ' strict' => [$identity, true, Command::FAILURE];
        }
    }

    #[Test]
    #[DataProvider('nonComparableRuns')]
    public function unverifiedGeneratorRunsAreStoredWithoutAnyBaselineRead(
        string $identity,
        bool $strict,
        int $expectedExit,
    ): void {
        $one = $this->record();
        $records = match ($identity) {
            'unknown-first' => [null, $one],
            'unknown-last' => [$one, null],
            default => [$one, $this->record('other-instance')],
        };
        $repository = $this->createMock(GeneratorEvaluationResultRepositoryInterface::class);
        $repository->expects(self::never())->method('findLatest');
        $repository->expects(self::never())->method('findLatestForGenerator');
        $saved = null;
        $repository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(
                static function (
                    SetEvaluationResult $result,
                ) use (&$saved): void {
                    $saved = $result;
                },
            );
        $tester = $this->tester($records, $repository);
        self::assertSame(
            $expectedExit,
            $tester->execute(
                [
                    'set' => 'fixture',
                    '--model' => 'requested-model',
                    '--fail-on-regression' => $strict,
                ],
            ),
        );
        self::assertStringContainsString(
            'no single verified serving generator',
            $tester->getDisplay(),
        );
        self::assertInstanceOf(SetEvaluationResult::class, $saved);
        self::assertSame('', $saved->model);
        self::assertNull($saved->generatorProvenance);
        self::assertTrue($saved->sharesOneYardstick());
        self::assertSame(1.0, $saved->meanScore());
    }

    #[Test]
    public function verifiedRunUsesAllFiveBaselineDimensionsAndStoresAfterReading(): void
    {
        $record = $this->record();
        $repository = $this->createMock(GeneratorEvaluationResultRepositoryInterface::class);
        $repository->expects(self::never())->method('findLatest');
        $order = [];
        $repository
            ->expects(self::once())
            ->method('findLatestForGenerator')
            ->with(
                'fixture',
                'custom-instance',
                'served-alias',
                'reported-snapshot',
                'deterministic',
            )
            ->willReturnCallback(
                static function () use (&$order, $record): EvaluationResultSummary {
                    $order[] = 'read';
                    return new EvaluationResultSummary(
                        'fixture',
                        'served-alias',
                        'deterministic',
                        1,
                        1,
                        1.0,
                        1.0,
                        100,
                        generatorProvenance: $record,
                    );
                },
            );
        $repository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(
                static function (
                    SetEvaluationResult $run,
                ) use (&$order, $record): void {
                    $order[] = 'save';
                    self::assertSame(
                        $record->toArray(),
                        $run->verifiedGeneratorProvenance()?->toArray(),
                    );
                },
            );
        $tester = $this->tester([$record], $repository);
        self::assertSame(
            Command::SUCCESS,
            $tester->execute(
                ['set' => 'fixture', '--fail-on-regression' => true],
            ),
        );
        self::assertSame(['read', 'save'], $order);
        self::assertStringContainsString('No regression', $tester->getDisplay());
    }

    #[Test]
    public function firstVerifiedRunCreatesItsOwnBaseline(): void
    {
        $record = $this->record();
        $repository = $this->createMock(GeneratorEvaluationResultRepositoryInterface::class);
        $repository
            ->expects(self::once())
            ->method('findLatestForGenerator')
            ->with(
                'fixture',
                'custom-instance',
                'served-alias',
                'reported-snapshot',
                'deterministic',
            )
            ->willReturn(null);
        $repository->expects(self::once())->method('save');
        $tester = $this->tester([$record], $repository);
        self::assertSame(
            Command::SUCCESS,
            $tester->execute(
                ['set' => 'fixture', '--fail-on-regression' => true],
            ),
        );
        self::assertStringContainsString(
            'recorded as the baseline',
            $tester->getDisplay(),
        );
    }

    #[Test]
    public function legacyCustomRepositoryDoesNotBecomeAnUnverifiedBaseline(): void
    {
        $repository = $this->createMock(EvaluationResultRepositoryInterface::class);
        $repository->expects(self::never())->method('findLatest');
        $repository->expects(self::once())->method('save');
        $tester = $this->tester([$this->record()], $repository);
        self::assertSame(
            Command::FAILURE,
            $tester->execute(
                ['set' => 'fixture', '--fail-on-regression' => true],
            ),
        );
        self::assertStringContainsString(
            'no verified-generator capability',
            preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '',
        );
    }
}
