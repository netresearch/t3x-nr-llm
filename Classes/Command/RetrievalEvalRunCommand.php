<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Service\Evaluation\EvaluatableRetrieverInterface;
use Netresearch\NrLlm\Service\Evaluation\EvaluatableRetrieverRegistry;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultSummary;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSet;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSetRegistry;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Service\Evaluation\RegressionThresholds;
use Netresearch\NrLlm\Service\Evaluation\RetrievalEvaluationService;
use Netresearch\NrLlm\Service\Evaluation\RetrievalProvenance;
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
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
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
            ->addArgument(
                'set',
                InputArgument::REQUIRED,
                'Golden question set identifier (e.g. nr_ai_search.bmdv)',
            )
            ->addArgument('retriever', InputArgument::REQUIRED, 'Retriever identifier (e.g. nr_llm.lexical)')
            ->addOption(
                'max-top1-drop',
                null,
                InputOption::VALUE_REQUIRED,
                'Top-1 hit-rate drop (0..1) that counts as a regression',
                '0.1',
            )
            ->addOption(
                'max-top3-drop',
                null,
                InputOption::VALUE_REQUIRED,
                'Top-3 hit-rate drop (0..1) that counts as a regression',
                '0.1',
            )
            ->addOption(
                'fail-on-regression',
                null,
                InputOption::VALUE_NONE,
                'Fail on regression or when benchmark provenance is unknown or incompatible',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $set = $this->findSet($input, $io);
        if (!$set instanceof GoldenQuestionSet) {
            return Command::FAILURE;
        }

        $retriever = $this->findRetriever($input, $io);
        if (!$retriever instanceof EvaluatableRetrieverInterface) {
            return Command::FAILURE;
        }

        $result = $this->evaluationService->run($set, $retriever);
        $io->title(
            sprintf('Retrieval evaluation: %s vs %s', $set->identifier, $retriever->getIdentifier()),
        );
        $this->renderEvaluations($io, $result);

        $persistable = $result->toSetEvaluationResult();
        $previous = $this->repository->findLatest(
            $persistable->setIdentifier,
            $persistable->model,
            $persistable->grader,
        );
        $this->repository->save($persistable);

        return $this->renderComparison($input, $io, $persistable->toSummary(), $previous);
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
        $io->writeln(
            sprintf(
                'Top-1 hit rate: %.1f%% (%d/%d)',
                $result->top1HitRate() * 100,
                $result->top1HitCount(),
                $result->questionCount(),
            ),
        );
        $io->writeln(
            sprintf(
                'Top-3 hit rate: %.1f%% (%d/%d)',
                $result->top3HitRate() * 100,
                $result->top3HitCount(),
                $result->questionCount(),
            ),
        );

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

    /**
     * Resolve the requested set and report available identifiers on failure.
     */
    private function findSet(InputInterface $input, SymfonyStyle $io): ?GoldenQuestionSet
    {
        $argument = $input->getArgument('set');
        $identifier = is_string($argument) ? $argument : '';
        $set = $this->setRegistry->findByIdentifier($identifier);
        if (!$set instanceof GoldenQuestionSet) {
            $io->error(sprintf('Unknown golden question set "%s".', $identifier));
            $available = $this->setRegistry->identifiers();
            if ($available !== []) {
                $io->writeln('Available sets: ' . implode(', ', $available));
            }
        }

        return $set;
    }

    private function findRetriever(
        InputInterface $input,
        SymfonyStyle $io,
    ): ?EvaluatableRetrieverInterface {
        $argument = $input->getArgument('retriever');
        $identifier = is_string($argument) ? $argument : '';
        $retriever = $this->retrieverRegistry->findByIdentifier($identifier);
        if (!$retriever instanceof EvaluatableRetrieverInterface) {
            $io->error(sprintf('Unknown retriever "%s".', $identifier));
            $available = $this->retrieverRegistry->identifiers();
            if ($available !== []) {
                $io->writeln('Available retrievers: ' . implode(', ', $available));
            }
        }

        return $retriever;
    }

    private function baselineState(
        EvaluationResultSummary $current,
        ?EvaluationResultSummary $previous,
    ): string {
        return match (true) {
            !$current->retrievalProvenance instanceof RetrievalProvenance || $current->benchmarkFingerprint === '' => 'unknown',
            !$previous instanceof EvaluationResultSummary => 'baseline',
            !$previous->retrievalProvenance instanceof RetrievalProvenance || $previous->benchmarkFingerprint === '' => 'unknown',
            $previous->benchmarkFingerprint !== $current->benchmarkFingerprint => 'mismatch',
            default => 'comparable',
        };
    }

    private function renderProvenance(
        SymfonyStyle $io,
        EvaluationResultSummary $current,
        string $baselineState,
    ): void {
        $io->section('Retrieval provenance');
        if ($current->retrievalProvenance instanceof RetrievalProvenance) {
            foreach ($current->retrievalProvenance->toArray() as $key => $value) {
                $io->writeln($key . ': ' . ($value ?? 'unknown'));
            }
        }

        $io->writeln('Baseline state: ' . $baselineState);
    }

    private function renderComparison(
        InputInterface $input,
        SymfonyStyle $io,
        EvaluationResultSummary $current,
        ?EvaluationResultSummary $previous,
    ): int {
        $baselineState = $this->baselineState($current, $previous);
        $this->renderProvenance($io, $current, $baselineState);
        $failOnRegression = $input->getOption('fail-on-regression') === true;
        if ($baselineState === 'unknown' || $baselineState === 'mismatch') {
            $io->warning(
                'Regression comparison skipped: benchmark provenance is ' . $baselineState . '. The measurement was recorded.',
            );
            return $failOnRegression ? Command::FAILURE : Command::SUCCESS;
        }

        if ($previous instanceof EvaluationResultSummary && $previous->variantFingerprint !== $current->variantFingerprint) {
            $io->writeln(
                'Treatment changed: model, chunking or pipeline identity differs; the benchmark remains comparable.',
            );
        }

        $report = $this->regressionDetector->compare(
            $current,
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
        }

        return $report->isRegression && $failOnRegression ? Command::FAILURE : Command::SUCCESS;
    }
}
