<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Netresearch\NrLlm\Service\Decision\DecisionException;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepositoryInterface;
use Netresearch\NrLlm\Service\Evaluation\EvaluationService;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $setArgument = $input->getArgument('set');
        $setIdentifier = is_string($setArgument) ? $setArgument : '';
        $set = $this->registry->findByIdentifier($setIdentifier);
        if (!$set instanceof GoldenPromptSet) {
            $io->error(sprintf('Unknown golden prompt set "%s".', $setIdentifier));
            $available = $this->registry->identifiers();
            if ($available !== []) {
                $io->writeln('Available sets: ' . implode(', ', $available));
            }

            return Command::FAILURE;
        }

        $graderOption = $input->getOption('grader');
        $graderId = is_string($graderOption) ? $graderOption : DeterministicGrader::IDENTIFIER;
        $availableGraders = $this->evaluationService->availableGraders();
        if (!in_array($graderId, $availableGraders, true)) {
            $io->error(sprintf('Unknown grader "%s".', $graderId));
            $io->writeln('Available graders: ' . implode(', ', $availableGraders));

            return Command::FAILURE;
        }

        try {
            $result = $this->evaluationService->run($set, $graderId, $this->buildBaseOptions($input));
        } catch (DecisionException $e) {
            // The decision grader cannot grade this run at all; nothing was spent.
            $io->error(sprintf('The "%s" grader cannot run: %s', $graderId, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->title(sprintf('Evaluation: %s', $set->identifier));
        $this->renderEvaluations($io, $result);

        // A run without one yardstick, or one in which no model answered at
        // all, cannot be compared — and a gate must not read "could not
        // judge" as "no regression" (ADR-211).
        if (!$result->sharesOneYardstick() || $result->grader === DecisionGrader::FAILED_SERIES) {
            $this->repository->save($result);
            $io->section('Regression check');
            $reason = $result->grader === DecisionGrader::FAILED_SERIES
                ? 'Not compared: no decision of this run could be made (stored as "%s").'
                : 'Not compared: the gradings of this run do not share one yardstick (stored as "%s"). '
                    . 'A decision failed for some prompts, or the model changed during the run.';
            if ($input->getOption('fail-on-regression') === true) {
                $io->error(sprintf($reason, $result->grader));

                return Command::FAILURE;
            }

            $io->warning(sprintf($reason, $result->grader));

            return Command::SUCCESS;
        }

        $previous = $this->repository->findLatest($result->setIdentifier, $result->model, $result->grader);
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
            $io->warning('Quality regression detected against the previous run.');
            if ($input->getOption('fail-on-regression') === true) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
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
}
