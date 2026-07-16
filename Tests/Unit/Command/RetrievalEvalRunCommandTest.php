<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Command;

use Netresearch\NrLlm\Command\RetrievalEvalRunCommand;
use Netresearch\NrLlm\Domain\Enum\QuestionForm;
use Netresearch\NrLlm\Service\Evaluation\EvaluatableRetrieverRegistry;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultSummary;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestion;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSet;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSetProviderInterface;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSetRegistry;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Service\Evaluation\RetrievalEvaluationService;
use Netresearch\NrLlm\Service\Evaluation\RetrievalSetEvaluationResult;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryEvaluationResultRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture\StaticRetriever;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(RetrievalEvalRunCommand::class)]
final class RetrievalEvalRunCommandTest extends TestCase
{
    private const SET_IDENTIFIER = 'nr_llm.test';

    private const RETRIEVER_IDENTIFIER = 'test.retriever';

    private function registry(): GoldenQuestionSetRegistry
    {
        $set = new GoldenQuestionSet(self::SET_IDENTIFIER, 'Test set', 'desc', [
            new GoldenQuestion('office', 'Where is the office?', QuestionForm::MATCH, ['doc-a'], 'normal'),
            new GoldenQuestion('hours', 'When are you open?', QuestionForm::GAP, ['doc-b']),
        ]);
        $provider = new class ([$set]) implements GoldenQuestionSetProviderInterface {
            /**
             * @param list<GoldenQuestionSet> $sets
             */
            public function __construct(private readonly array $sets) {}

            public function getGoldenQuestionSets(): array
            {
                return $this->sets;
            }
        };

        return new GoldenQuestionSetRegistry([$provider]);
    }

    private function command(StaticRetriever $retriever, InMemoryEvaluationResultRepository $repository): RetrievalEvalRunCommand
    {
        return new RetrievalEvalRunCommand(
            $this->registry(),
            new EvaluatableRetrieverRegistry([$retriever]),
            new RetrievalEvaluationService(),
            $repository,
            new RegressionDetector(),
        );
    }

    private function perfectRetriever(): StaticRetriever
    {
        return new StaticRetriever([
            'Where is the office?' => ['doc-a'],
            'When are you open?' => ['doc-b'],
        ]);
    }

    private function perfectBaseline(): EvaluationResultSummary
    {
        return new EvaluationResultSummary(
            self::SET_IDENTIFIER,
            self::RETRIEVER_IDENTIFIER,
            RetrievalSetEvaluationResult::GRADER_IDENTIFIER,
            2,
            2,
            1.0,
            1.0,
            1_700_000_000,
        );
    }

    #[Test]
    public function unknownSetFailsWithHint(): void
    {
        $tester = new CommandTester($this->command($this->perfectRetriever(), new InMemoryEvaluationResultRepository()));

        $exitCode = $tester->execute(['set' => 'does.not.exist', 'retriever' => self::RETRIEVER_IDENTIFIER]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown golden question set', $tester->getDisplay());
        self::assertStringContainsString(self::SET_IDENTIFIER, $tester->getDisplay());
    }

    #[Test]
    public function unknownRetrieverFailsWithHint(): void
    {
        $tester = new CommandTester($this->command($this->perfectRetriever(), new InMemoryEvaluationResultRepository()));

        $exitCode = $tester->execute(['set' => self::SET_IDENTIFIER, 'retriever' => 'does.not.exist']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown retriever', $tester->getDisplay());
        self::assertStringContainsString(self::RETRIEVER_IDENTIFIER, $tester->getDisplay());
    }

    #[Test]
    public function perfectRunReportsHitRatesAndPersists(): void
    {
        $repository = new InMemoryEvaluationResultRepository();
        $tester = new CommandTester($this->command($this->perfectRetriever(), $repository));

        $exitCode = $tester->execute(['set' => self::SET_IDENTIFIER, 'retriever' => self::RETRIEVER_IDENTIFIER]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Top-1 hit rate: 100.0% (2/2)', $tester->getDisplay());
        self::assertStringContainsString('Top-3 hit rate: 100.0% (2/2)', $tester->getDisplay());
        self::assertStringContainsString('By form', $tester->getDisplay());
        self::assertStringContainsString('By hard class', $tester->getDisplay());
        self::assertCount(1, $repository->saved);
        self::assertSame(RetrievalSetEvaluationResult::GRADER_IDENTIFIER, $repository->saved[0]->grader);
        self::assertSame(self::RETRIEVER_IDENTIFIER, $repository->saved[0]->model);
    }

    #[Test]
    public function firstRunHasNoBaselineAndSucceeds(): void
    {
        $tester = new CommandTester($this->command(new StaticRetriever(), new InMemoryEvaluationResultRepository()));

        $exitCode = $tester->execute(['set' => self::SET_IDENTIFIER, 'retriever' => self::RETRIEVER_IDENTIFIER]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('baseline', $tester->getDisplay());
    }

    #[Test]
    public function regressionWarnsButSucceedsWithoutFailFlag(): void
    {
        $repository = new InMemoryEvaluationResultRepository();
        $repository->seed($this->perfectBaseline());
        $tester = new CommandTester($this->command(new StaticRetriever(), $repository));

        $exitCode = $tester->execute(['set' => self::SET_IDENTIFIER, 'retriever' => self::RETRIEVER_IDENTIFIER]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Retrieval regression detected', $tester->getDisplay());
    }

    #[Test]
    public function regressionFailsWithFailFlag(): void
    {
        $repository = new InMemoryEvaluationResultRepository();
        $repository->seed($this->perfectBaseline());
        $tester = new CommandTester($this->command(new StaticRetriever(), $repository));

        $exitCode = $tester->execute([
            'set' => self::SET_IDENTIFIER,
            'retriever' => self::RETRIEVER_IDENTIFIER,
            '--fail-on-regression' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    #[Test]
    public function dropWithinTolerancesIsNoRegression(): void
    {
        $repository = new InMemoryEvaluationResultRepository();
        $repository->seed($this->perfectBaseline());
        // doc-a is found top-1; the second question misses entirely: both
        // rates drop 50 pp, tolerated by loosened thresholds.
        $retriever = new StaticRetriever(['Where is the office?' => ['doc-a']]);
        $tester = new CommandTester($this->command($retriever, $repository));

        $exitCode = $tester->execute([
            'set' => self::SET_IDENTIFIER,
            'retriever' => self::RETRIEVER_IDENTIFIER,
            '--max-top1-drop' => '0.5',
            '--max-top3-drop' => '0.5',
            '--fail-on-regression' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No regression', $tester->getDisplay());
    }
}
