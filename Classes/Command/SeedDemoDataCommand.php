<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Command;

use Doctrine\DBAL\Exception as DbalException;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Seeds the demo record graph — providers, models, configurations, tasks and a
 * span of usage history.
 *
 * This replaces three files under `.ddev/`: two MySQL dumps and a raw-PDO
 * script hard-wired to host `db`, database `v14` and root/root. That shape tied
 * the demo data to one local development tool, so nothing else could use it —
 * not the functional suite, which runs on SQLite, and not a documentation
 * screenshot run, which needs the same rows to exist somewhere it can serve
 * TYPO3 from.
 *
 * The data itself lives in `Resources/Private/Demo/DemoData.php` and ships with
 * the extension. It is data, not SQL: this command writes it through the
 * ConnectionPool, so the DBMS is whatever the instance uses.
 *
 * IDEMPOTENT BY IDENTIFIER. Every table carries one, and every relation in the
 * data file names its target by identifier rather than by uid. Re-running
 * updates the rows it already wrote instead of adding a second copy, which is
 * what makes this safe to put in an install routine.
 *
 * DETERMINISTIC. The usage history is generated from a fixed seed, so the same
 * `--days` produces the same numbers on every machine. Documentation
 * screenshots therefore show figures that do not drift between runs, and a
 * reviewer comparing two screenshots sees a real difference rather than noise.
 *
 * The numbers are REAL in the sense that matters: they are rows the analytics
 * read path aggregates like any other, priced from each model's own
 * cost_input/cost_output. Nothing here writes a total anywhere.
 */
#[AsCommand(
    name: 'nrllm:demo:seed',
    description: 'Seed the demo record graph and usage history for demos, tests and screenshots.',
)]
/**
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class SeedDemoDataCommand extends Command
{
    // Level 10 sees every option and every column value as mixed; the shared
    // helpers are the repository's answer to that (Classes/AGENTS.md).
    use SafeCastTrait;

    /**
     * Fixed so the generated history is identical on every machine. Changing it
     * changes every screenshot's numbers, which is a documentation change.
     */
    private const RANDOM_SEED = 20260601;

    private const DATA_FILE = __DIR__ . '/../../Resources/Private/Demo/DemoData.php';

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Days of usage history to generate. 0 writes the record graph only.',
            '90',
        );
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Seed even in Production context.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Demo rows in a production database are a footgun the SQL dumps this
        // replaces had no guard against: they were run by hand against whatever
        // `ddev` pointed at. A named override keeps the escape hatch honest.
        if (Environment::getContext()->isProduction() && $input->getOption('force') !== true) {
            $io->error('Refusing to seed demo data in Production context. Pass --force if that is really what you want.');

            return Command::FAILURE;
        }

        $days = max(0, self::toInt($input->getOption('days')));

        /** @var array{providers: list<array<string, mixed>>, models: list<array<string, mixed>>, configurations: list<array<string, mixed>>, tasks: list<array<string, mixed>>} $data */
        $data = require self::DATA_FILE;

        try {
            $providers      = $this->upsertAll('tx_nrllm_provider', $data['providers'], []);
            $models         = $this->upsertAll('tx_nrllm_model', $data['models'], ['provider' => ['provider_uid', $providers]]);
            $configurations = $this->upsertAll('tx_nrllm_configuration', $data['configurations'], ['model' => ['model_uid', $models]]);
            $tasks          = $this->upsertAll('tx_nrllm_task', $data['tasks'], ['configuration' => ['configuration_uid', $configurations]]);
        } catch (DbalException $e) {
            $io->error('Seeding the record graph failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(sprintf(
            '  %d provider(s), %d model(s), %d configuration(s), %d task(s)',
            count($providers),
            count($models),
            count($configurations),
            count($tasks),
        ));

        if ($days > 0) {
            try {
                $rows = $this->seedUsage($days, $models, $configurations, $tasks);
            } catch (DbalException $e) {
                $io->error('Seeding usage history failed: ' . $e->getMessage());

                return Command::FAILURE;
            }

            $io->writeln(sprintf('  %d usage row(s) across %d day(s)', $rows, $days));
        }

        $io->success('Demo data seeded.');

        return Command::SUCCESS;
    }

    /**
     * Writes every record of one table and returns identifier => uid.
     *
     * @param list<array<string, mixed>>                             $records
     * @param array<string, array{0: string, 1: array<string, int>}> $relations field in the data => [column, identifier => uid map]
     *
     * @throws DbalException
     *
     * @return array<string, int>
     */
    private function upsertAll(string $table, array $records, array $relations): array
    {
        $connection = $this->connection($table);
        $now        = self::toInt($GLOBALS['EXEC_TIME'] ?? time());
        $uids       = [];

        foreach ($records as $record) {
            $row = $this->resolveRow($table, $record, $relations);

            $identifier = $row['identifier'] ?? null;
            if (!is_string($identifier) || $identifier === '') {
                throw new InvalidArgumentException(sprintf('Demo data: a %s record has no identifier.', $table), 1787360002);
            }

            $row['pid']    = 0;
            $row['tstamp'] = $now;

            $existing = $connection->select(['uid'], $table, ['identifier' => $identifier, 'deleted' => 0])
                ->fetchAssociative();

            if (is_array($existing) && isset($existing['uid'])) {
                $uid = self::toInt($existing['uid']);
                $connection->update($table, $row, ['uid' => $uid]);
            } else {
                $row['crdate'] = $now;
                $connection->insert($table, $row);
                $uid = self::toInt($connection->lastInsertId());
            }

            $uids[$identifier] = $uid;
        }

        return $uids;
    }

    /**
     * One record with its identifier references turned into uids.
     *
     * Split out of upsertAll() so that method reads as write-or-update and this
     * one as resolve. Together they carried a cognitive complexity the analyser
     * was right to flag.
     *
     * @param array<string, mixed>                                   $record
     * @param array<string, array{0: string, 1: array<string, int>}> $relations
     *
     * @return array<string, mixed>
     */
    private function resolveRow(string $table, array $record, array $relations): array
    {
        $row = [];
        foreach ($record as $field => $value) {
            if (!isset($relations[$field])) {
                $row[$field] = $value;

                continue;
            }

            [$column, $map] = $relations[$field];

            // A dangling reference is a defect in the data file, not a runtime
            // condition: fail loudly rather than write a 0, which renders in the
            // backend as "nothing selected" rather than as an error.
            if (!is_string($value) || !isset($map[$value])) {
                throw new InvalidArgumentException(sprintf(
                    'Demo data: %s references unknown %s "%s".',
                    $table,
                    $field,
                    is_scalar($value) ? (string)$value : gettype($value),
                ), 1787360001);
            }

            $row[$column] = $map[$value];
        }

        return $row;
    }

    /**
     * Generates one usage row per day per (model, task) pair.
     *
     * Rows are deleted and rewritten rather than accumulated: two runs of
     * `--days=90` must describe ninety days, not one hundred and eighty
     * requests per day.
     *
     * @param array<string, int> $models
     * @param array<string, int> $configurations
     * @param array<string, int> $tasks
     *
     * @throws DbalException
     */
    private function seedUsage(int $days, array $models, array $configurations, array $tasks): int
    {
        $connection = $this->connection('tx_nrllm_service_usage');
        $now        = self::toInt($GLOBALS['EXEC_TIME'] ?? time());

        // Only the demo graph's own rows: an instance may carry real usage next
        // to the demo data, and a blanket TRUNCATE would take it.
        if ($models !== []) {
            $connection->delete('tx_nrllm_service_usage', ['pid' => 0], ['pid' => Connection::PARAM_INT]);
        }

        $modelData = $this->modelPricing(array_values($models));
        $taskUids  = array_values($tasks);
        $configUid = $configurations !== [] ? self::toInt(reset($configurations)) : 0;
        $today     = self::toInt(strtotime('today', $now));
        $written   = 0;

        for ($day = $days - 1; $day >= 0; $day--) {
            $date = $today - ($day * 86400);

            foreach ($modelData as $modelUid => $model) {
                // Not every model is used every day — a flat line reads as
                // generated data, and the analytics charts exist to show shape.
                if ($this->pick($day, $modelUid, 'used', 0, 9) < 2) {
                    continue;
                }

                $requests   = $this->pick($day, $modelUid, 'requests', 3, 28);
                $promptAvg  = $this->pick($day, $modelUid, 'prompt', 180, 1400);
                $outputAvg  = $this->pick($day, $modelUid, 'output', 60, 700);
                $prompt     = $requests * $promptAvg;
                $completion = $requests * $outputAvg;

                // cost_input/cost_output are CENTS per 1M tokens — the unit
                // Model::getCostInputDollars() reads by dividing by 100. Getting
                // this wrong is invisible in a unit test and produces a
                // five-figure demo dashboard.
                $cost = (($prompt * $model['cost_input']) + ($completion * $model['cost_output']))
                    / 100_000_000.0;

                $connection->insert('tx_nrllm_service_usage', [
                    'pid'               => 0,
                    'service_type'      => 'completion',
                    'service_provider'  => $model['provider'],
                    'configuration_uid' => $configUid,
                    'model_uid'         => $modelUid,
                    'model_id'          => $model['model_id'],
                    'task_uid'          => $taskUids === [] ? 0 : $taskUids[$this->pick($day, $modelUid, 'task', 0, count($taskUids) - 1)],
                    'source_extension'  => '',
                    'be_user'           => 1,
                    'request_count'     => $requests,
                    'tokens_used'       => $prompt + $completion,
                    'prompt_tokens'     => $prompt,
                    'completion_tokens' => $completion,
                    'characters_used'   => 0,
                    'audio_seconds_used' => 0,
                    'images_generated'  => 0,
                    'estimated_cost'    => number_format($cost, 6, '.', ''),
                    'request_date'      => $date,
                    'tstamp'            => $date,
                    'crdate'            => $date,
                ]);
                $written++;
            }
        }

        return $written;
    }

    /**
     * A value in [$min, $max] derived from the seed and the row it belongs to.
     *
     * Deliberately not a seeded global stream. Two things break that here, and
     * both did: the value depends on how many numbers were drawn before it, so
     * the history changes when the iteration order does — and PHP-CS-Fixer
     * rewrites `mt_srand`/`mt_rand` to `srand`/`random_int`, which is
     * cryptographically seeded and ignores the seed entirely. The reproducible
     * history this command promises would have been silently un-reproducible
     * from the first `-s cgl` run onwards.
     *
     * Deriving each value from (seed, day, model, purpose) makes every row
     * independent of every other, and depends on no global state a formatter
     * can rewrite.
     */
    private function pick(int $day, int $modelUid, string $purpose, int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        $hash = hash('xxh3', self::RANDOM_SEED . ':' . $day . ':' . $modelUid . ':' . $purpose);

        return $min + (int)(hexdec(substr($hash, 0, 8)) % (($max - $min) + 1));
    }

    /**
     * Reads model_id, provider identifier and per-1k pricing for each model uid.
     *
     * @param list<int> $modelUids
     *
     * @throws DbalException
     *
     * @return array<int, array{model_id: string, provider: string, cost_input: float, cost_output: float}>
     */
    private function modelPricing(array $modelUids): array
    {
        if ($modelUids === []) {
            return [];
        }

        $models    = $this->connection('tx_nrllm_model');
        $providers = $this->connection('tx_nrllm_provider');
        $out       = [];

        foreach ($modelUids as $uid) {
            $row = $models->select(['model_id', 'provider_uid', 'cost_input', 'cost_output'], 'tx_nrllm_model', ['uid' => $uid])
                ->fetchAssociative();
            if (!is_array($row)) {
                continue;
            }

            $providerRow = $providers->select(['identifier'], 'tx_nrllm_provider', ['uid' => self::toInt($row['provider_uid'] ?? 0)])
                ->fetchAssociative();

            $out[$uid] = [
                'model_id'    => self::toStr($row['model_id'] ?? ''),
                'provider'    => is_array($providerRow) ? self::toStr($providerRow['identifier'] ?? '') : '',
                'cost_input'  => self::toFloat($row['cost_input'] ?? 0.0),
                'cost_output' => self::toFloat($row['cost_output'] ?? 0.0),
            ];
        }

        return $out;
    }

    private function connection(string $table): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
    }
}
