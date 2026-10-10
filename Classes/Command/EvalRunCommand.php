<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
use Netresearch\NrLlm\Service\Decision\DecisionException;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultSummary;
use Netresearch\NrLlm\Service\Evaluation\EvaluationService;
use Netresearch\NrLlm\Service\Evaluation\GeneratorEvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetRegistry;
use Netresearch\NrLlm\Service\Evaluation\Grader\DecisionGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\RegressionDetector;
use Netresearch\NrLlm\Service\Evaluation\RegressionThresholds;
use Netresearch\NrLlm\Service\Evaluation\SetEvaluationResult;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs a golden prompt set against a model, prints the per-prompt gradings
 * and the set aggregate, then compares the run against the previous one for
 * the same (set, model) and reports any regression (ADR-060).
 *
 * Explicitly invoked — nothing here runs in the request pipeline. The
 * deterministic grader is the default; `--grader decision` opts into the
 * token-spending decision grader (ADR-211).
 */
#[AsCommand(
    name: 'nrllm:eval:run',
    description: 'Run a golden prompt set against a model and report grading and regression.',
)]
/**
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class EvalRunCommand extends Command
{
    public function __construct(
        private readonly GoldenPromptSetRegistry $registry,
        private readonly EvaluationService $evaluationService,
        private readonly EvaluationResultRepositoryInterface $repository,
        private readonly RegressionDetector $regressionDetector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('set', InputArgument::REQUIRED, 'Golden prompt set identifier (e.g. nr_llm.smoke)')
            ->addOption('grader', null, InputOption::VALUE_REQUIRED, 'Grader: deterministic or decision', DeterministicGrader::IDENTIFIER)
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Model id to evaluate; defaults to the configured default')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider id to evaluate against')
            ->addOption('max-pass-rate-drop', null, InputOption::VALUE_REQUIRED, 'Pass-rate drop (0..1) that counts as a regression', '0.1')
            ->addOption('max-mean-score-drop', null, InputOption::VALUE_REQUIRED, 'Mean-score drop (0..1) that counts as a regression', '0.1')
            ->addOption('fail-on-regression', null, InputOption::VALUE_NONE, 'Exit with a non-zero status when a regression is detected');
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $set = $this->findSet($input, $io);
        if (!$set instanceof GoldenPromptSet) {
            return Command::FAILURE;
        }

        $graderId = $this->findGrader($input, $io);
        if ($graderId === null) {
            return Command::FAILURE;
        }

        return $this->evaluate($input, $io, $set, $graderId);
    }

    private function renderEvaluations(SymfonyStyle $io, SetEvaluationResult $result): void
    {
        $rows = [];
        foreach ($result->evaluations as $evaluation) {
            $rows[] = [
                $evaluation->promptId,
                $evaluation->result->passed ? 'pass' : 'FAIL',
                sprintf('%.2f', $evaluation->result->score),
                (string)$evaluation->latencyMs,
                $evaluation->result->reason,
            ];
        }

        $io->table(['Prompt', 'Result', 'Score', 'Latency (ms)', 'Detail'], $rows);

        $io->writeln(sprintf('Model:      %s', $result->model !== '' ? $result->model : '(unknown)'));
        $io->writeln(sprintf('Grader:     %s', $result->grader));
        $io->writeln(sprintf('Pass rate:  %.1f%% (%d/%d)', $result->passRate() * 100, $result->passedCount(), $result->promptCount()));
        $io->writeln(sprintf('Mean score: %.3f', $result->meanScore()));
    }

    private function buildBaseOptions(InputInterface $input): ?ChatOptions
    {
        $model = $input->getOption('model');
        $provider = $input->getOption('provider');
        if (!is_string($model) && !is_string($provider)) {
            return null;
        }

        $options = new ChatOptions();
        if (is_string($model) && $model !== '') {
            $options = $options->withModel($model);
        }

        if (is_string($provider) && $provider !== '') {
            return $options->withProvider($provider);
        }

        return $options;
    }

    private function floatOption(InputInterface $input, string $name, float $default): float
    {
        $value = $input->getOption($name);

        return is_numeric($value) ? (float)$value : $default;
    }

    private function findSet(
        InputInterface $input,
        SymfonyStyle $io,
    ): ?GoldenPromptSet {
        $argument = $input->getArgument('set');
        $identifier = is_string($argument) ? $argument : '';
        $set = $this->registry->findByIdentifier($identifier);
        if (!$set instanceof GoldenPromptSet) {
            $io->error(sprintf('Unknown golden prompt set "%s".', $identifier));
            $available = $this->registry->identifiers();
            if ($available !== []) {
                $io->writeln('Available sets: ' . implode(', ', $available));
            }
        }

        return $set;
    }

    private function findGrader(
        InputInterface $input,
        SymfonyStyle $io,
    ): ?string {
        $option = $input->getOption('grader');
        $identifier = is_string($option) ? $option : DeterministicGrader::IDENTIFIER;
        $available = $this->evaluationService->availableGraders();
        if (!in_array($identifier, $available, true)) {
            $io->error(sprintf('Unknown grader "%s".', $identifier));
            $io->writeln('Available graders: ' . implode(', ', $available));
            return null;
        }

        return $identifier;
    }

    private function evaluate(
        InputInterface $input,
        SymfonyStyle $io,
        GoldenPromptSet $set,
        string $graderId,
    ): int {
        try {
            $result = $this->evaluationService->run(
                $set,
                $graderId,
                $this->buildBaseOptions($input),
            );
        } catch (DecisionException $e) {
            $io->error(
                sprintf(
                    'The "%s" grader cannot run: %s',
                    $graderId,
                    $e->getMessage(),
                ),
            );
            return Command::FAILURE;
        }

        $io->title(sprintf('Evaluation: %s', $set->identifier));
        $this->renderEvaluations($io, $result);
        return $this->renderComparison($input, $io, $result);
    }

    private function notComparedReason(SetEvaluationResult $result): ?string
    {
        $reason = null;
        if (!$result->sharesOneYardstick() || $result->grader === DecisionGrader::FAILED_SERIES) {
            $reason = $result->grader === DecisionGrader::FAILED_SERIES ? sprintf(
                'Not compared: no decision of this run could be made (stored as "%s").',
                $result->grader,
            ) : sprintf(
                'Not compared: the gradings of this run do not share one yardstick (stored as "%s"). A decision failed for some prompts, or the grading model changed during the run.',
                $result->grader,
            );
        } elseif (!$result->verifiedGeneratorProvenance() instanceof GeneratorProvenance) {
            $reason = 'Not compared: no single verified serving generator for this run. Aggregate grades remain stored.';
        } elseif (!$this->repository instanceof GeneratorEvaluationResultRepositoryInterface) {
            $reason = 'Not compared: configured evaluation repository has no verified-generator capability.';
        }

        return $reason;
    }

    private function findGeneratorBaseline(
        SetEvaluationResult $result,
    ): ?EvaluationResultSummary {
        $record = $result->verifiedGeneratorProvenance();
        if (!$record instanceof GeneratorProvenance || !$this->repository instanceof GeneratorEvaluationResultRepositoryInterface) {
            return null;
        }

        return $this->repository->findLatestForGenerator(
            $result->setIdentifier,
            $record->providerIdentifier,
            $record->modelId,
            $record->reportedModelId,
            $result->grader,
        );
    }

    private function renderComparison(
        InputInterface $input,
        SymfonyStyle $io,
        SetEvaluationResult $result,
    ): int {
        $reason = $this->notComparedReason($result);
        if ($reason !== null) {
            $this->repository->save($result);
            $io->section('Regression check');
            if ($input->getOption('fail-on-regression') === true) {
                $io->error($reason);
            } else {
                $io->warning($reason);
            }

            return $input->getOption('fail-on-regression') === true ? Command::FAILURE : Command::SUCCESS;
        }

        $previous = $this->findGeneratorBaseline($result);
        $this->repository->save($result);
        $report = $this->regressionDetector->compare(
            $result->toSummary(),
            $previous,
            new RegressionThresholds(
                $this->floatOption($input, 'max-pass-rate-drop', 0.1),
                $this->floatOption($input, 'max-mean-score-drop', 0.1),
            ),
        );
        $io->section('Regression check');
        $io->writeln($report->summary);
        if ($report->isRegression) {
            $io->warning(
                'Quality regression detected against the previous run.',
            );
        }

        return $report->isRegression && $input->getOption('fail-on-regression') === true ? Command::FAILURE : Command::SUCCESS;
    }
}
