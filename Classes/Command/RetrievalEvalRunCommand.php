<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Service\Evaluation\EvaluatableRetrieverRegistry;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSetRegistry;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Service\Evaluation\RegressionThresholds;
use Netresearch\NrLlm\Service\Evaluation\RetrievalEvaluationService;
use Netresearch\NrLlm\Service\Evaluation\RetrievalSetEvaluationResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs a golden question set against a retriever, prints the per-question
 * hits and the top-1/top-3 hit rates with their by-form and by-hard-class
 * breakdowns, then compares the run against the previous one for the same
 * (set, retriever) and reports any regression (ADR-072) — the retrieval
 * sibling of {@see EvalRunCommand}.
 *
 * Explicitly invoked — nothing here runs in the request pipeline, and no
 * LLM is involved: the run costs one retrieval call per question.
 */
#[AsCommand(
    name: 'nrllm:eval:retrieval',
    description: 'Run a golden question set against a retriever and report hit rates and regression.',
)]
final class RetrievalEvalRunCommand extends Command
{
    public function __construct(
        private readonly GoldenQuestionSetRegistry $setRegistry,
        private readonly EvaluatableRetrieverRegistry $retrieverRegistry,
        private readonly RetrievalEvaluationService $evaluationService,
        private readonly EvaluationResultRepositoryInterface $repository,
        private readonly RegressionDetector $regressionDetector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('set', InputArgument::REQUIRED, 'Golden question set identifier (e.g. nr_ai_search.bmdv)')
            ->addArgument('retriever', InputArgument::REQUIRED, 'Retriever identifier (e.g. nr_llm.lexical)')
            ->addOption('max-top1-drop', null, InputOption::VALUE_REQUIRED, 'Top-1 hit-rate drop (0..1) that counts as a regression', '0.1')
            ->addOption('max-top3-drop', null, InputOption::VALUE_REQUIRED, 'Top-3 hit-rate drop (0..1) that counts as a regression', '0.1')
            ->addOption('fail-on-regression', null, InputOption::VALUE_NONE, 'Exit with a non-zero status when a regression is detected');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $setArgument = $input->getArgument('set');
        $setIdentifier = is_string($setArgument) ? $setArgument : '';
        $set = $this->setRegistry->findByIdentifier($setIdentifier);
        if ($set === null) {
            $io->error(sprintf('Unknown golden question set "%s".', $setIdentifier));
            $available = $this->setRegistry->identifiers();
            if ($available !== []) {
                $io->writeln('Available sets: ' . implode(', ', $available));
            }

            return Command::FAILURE;
        }

        $retrieverArgument = $input->getArgument('retriever');
        $retrieverIdentifier = is_string($retrieverArgument) ? $retrieverArgument : '';
        $retriever = $this->retrieverRegistry->findByIdentifier($retrieverIdentifier);
        if ($retriever === null) {
            $io->error(sprintf('Unknown retriever "%s".', $retrieverIdentifier));
            $available = $this->retrieverRegistry->identifiers();
            if ($available !== []) {
                $io->writeln('Available retrievers: ' . implode(', ', $available));
            }

            return Command::FAILURE;
        }

        $result = $this->evaluationService->run($set, $retriever);

        $io->title(sprintf('Retrieval evaluation: %s vs %s', $set->identifier, $retriever->getIdentifier()));
        $this->renderEvaluations($io, $result);

        $persistable = $result->toSetEvaluationResult();
        $previous = $this->repository->findLatest($persistable->setIdentifier, $persistable->model, $persistable->grader);
        $this->repository->save($persistable);

        $report = $this->regressionDetector->compare(
            $persistable->toSummary(),
            $previous,
            new RegressionThresholds(
                $this->floatOption($input, 'max-top1-drop', 0.1),
                $this->floatOption($input, 'max-top3-drop', 0.1),
            ),
        );

        $io->section('Regression check (pass rate = top-1 hit rate, mean score = top-3 hit rate)');
        $io->writeln($report->summary);

        if ($report->isRegression) {
            $io->warning('Retrieval regression detected against the previous run.');
            if ($input->getOption('fail-on-regression') === true) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    private function renderEvaluations(SymfonyStyle $io, RetrievalSetEvaluationResult $result): void
    {
        $rows = [];
        foreach ($result->evaluations as $evaluation) {
            $rows[] = [
                $evaluation->questionId,
                $evaluation->form->value,
                $evaluation->hardClass ?? '-',
                $evaluation->top1Hit ? 'hit' : 'MISS',
                $evaluation->top3Hit ? 'hit' : 'MISS',
                (string)$evaluation->latencyMs,
            ];
        }
        $io->table(['Question', 'Form', 'Hard class', 'Top-1', 'Top-3', 'Latency (ms)'], $rows);

        $io->writeln(sprintf('Retriever:      %s', $result->retriever));
        $io->writeln(sprintf(
            'Top-1 hit rate: %.1f%% (%d/%d)',
            $result->top1HitRate() * 100,
            $result->top1HitCount(),
            $result->questionCount(),
        ));
        $io->writeln(sprintf(
            'Top-3 hit rate: %.1f%% (%d/%d)',
            $result->top3HitRate() * 100,
            $result->top3HitCount(),
            $result->questionCount(),
        ));

        $this->renderBreakdown($io, 'By form', $result->hitRatesByForm());
        $this->renderBreakdown($io, 'By hard class', $result->hitRatesByHardClass());
    }

    /**
     * @param array<string, array{questions: int, top1HitRate: float, top3HitRate: float}> $breakdown
     */
    private function renderBreakdown(SymfonyStyle $io, string $title, array $breakdown): void
    {
        if ($breakdown === []) {
            return;
        }

        $io->section($title);
        $rows = [];
        foreach ($breakdown as $key => $rates) {
            $rows[] = [
                $key,
                (string)$rates['questions'],
                sprintf('%.1f%%', $rates['top1HitRate'] * 100),
                sprintf('%.1f%%', $rates['top3HitRate'] * 100),
            ];
        }
        $io->table(['Class', 'Questions', 'Top-1', 'Top-3'], $rows);
    }

    private function floatOption(InputInterface $input, string $name, float $default): float
    {
        $value = $input->getOption($name);

        return is_numeric($value) ? (float)$value : $default;
    }
}
