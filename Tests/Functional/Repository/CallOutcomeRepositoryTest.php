<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Domain\Enum\CallOutcomeSource;
use Netresearch\NrLlm\Service\Outcome\CallOutcomeRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\SemaphoreLockStrategy;
use TYPO3\CMS\Core\Locking\SimpleLockStrategy;

/**
 * The per-call outcome row (ADR-176), against a real schema.
 */
#[CoversClass(CallOutcomeRepository::class)]
final class CallOutcomeRepositoryTest extends AbstractFunctionalTestCase
{
    private const CALL = '11111111-2222-4333-8444-555555555555';

    private CallOutcomeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $repository = $this->get(CallOutcomeRepository::class);
        self::assertInstanceOf(CallOutcomeRepository::class, $repository);
        $this->repository = $repository;
    }

    #[Test]
    public function anOutcomeIsWrittenAndReadBack(): void
    {
        $this->repository->record(self::CALL, CallOutcome::HELPFUL);

        self::assertSame([CallOutcome::HELPFUL], $this->repository->findByCorrelation(self::CALL));
    }

    #[Test]
    public function aSecondRatingReplacesTheFirstRatherThanAddingToIt(): void
    {
        // The rater changed their mind. Two rows would leave the readout to
        // guess which one counts, which is the whole reason the repository
        // clears the same source first.
        $this->repository->record(self::CALL, CallOutcome::HELPFUL);
        $this->repository->record(self::CALL, CallOutcome::NOT_HELPFUL);

        self::assertSame([CallOutcome::NOT_HELPFUL], $this->repository->findByCorrelation(self::CALL));
        self::assertSame(1, $this->rowCount());
    }

    #[Test]
    public function theTwoSourcesDoNotDisplaceEachOther(): void
    {
        // ADR-176 keeps them apart, so an observed outcome must not clear an
        // explicit one. Asserted now rather than when the observed writer
        // arrives, because that writer would otherwise be the thing that
        // discovers it.
        $this->repository->record(self::CALL, CallOutcome::HELPFUL);
        $this->repository->record(self::CALL, CallOutcome::EDITED);

        self::assertSame(
            [CallOutcome::HELPFUL, CallOutcome::EDITED],
            $this->repository->findByCorrelation(self::CALL),
        );
    }

    #[Test]
    public function anEmptyCorrelationIdWritesNothing(): void
    {
        // Every send has a correlation id, but a caller that lost it must not
        // create a row keyed on the empty string — every such row would look
        // like the same call.
        $this->repository->record('', CallOutcome::HELPFUL);

        self::assertSame(0, $this->rowCount());
        self::assertSame([], $this->repository->findByCorrelation(''));
    }

    #[Test]
    public function anUnknownStoredValueIsSkippedRatherThanFatal(): void
    {
        $this->connection()->insert('tx_nrllm_call_outcome', [
            'pid'            => 0,
            'correlation_id' => self::CALL,
            'outcome'        => 'from_a_newer_version',
            'crdate'         => 1_700_000_000,
            'tstamp'         => 1_700_000_000,
        ]);
        $this->repository->record(self::CALL, CallOutcome::HELPFUL);

        self::assertSame([CallOutcome::HELPFUL], $this->repository->findByCorrelation(self::CALL));
    }

    #[Test]
    public function everyCaseAnswersExactlyOneSource(): void
    {
        // The source is derived, not stored. If a case ever answered neither,
        // record() would clear nothing and the replace-in-place rule above
        // would silently stop holding.
        foreach (CallOutcome::cases() as $case) {
            self::assertContains($case->source(), CallOutcomeSource::cases());
        }

        $explicit = array_filter(CallOutcome::cases(), static fn (CallOutcome $c): bool => $c->source() === CallOutcomeSource::EXPLICIT);
        $observed = array_filter(CallOutcome::cases(), static fn (CallOutcome $c): bool => $c->source() === CallOutcomeSource::OBSERVED);

        self::assertNotSame([], $explicit);
        self::assertNotSame([], $observed);
        self::assertCount(count(CallOutcome::cases()), [...$explicit, ...$observed]);
    }

    private function connection(): Connection
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('tx_nrllm_call_outcome');
    }

    private function rowCount(): int
    {
        return $this->connection()->count('uid', 'tx_nrllm_call_outcome', []);
    }

    #[Test]
    public function aFailedReplacementPreservesBothPreviousSources(): void
    {
        $connection = $this->connection();
        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'The injected database failure uses a SQLite trigger.',
            );
        }

        $this->repository->record(self::CALL, CallOutcome::HELPFUL);
        $this->repository->record(self::CALL, CallOutcome::EDITED);

        $connection->executeStatement(
            "CREATE TRIGGER reject_outcome_replacement BEFORE INSERT ON tx_nrllm_call_outcome WHEN NEW.outcome = 'not_helpful' BEGIN SELECT RAISE(ABORT, 'injected storage failure'); END",
        );
        $failed = false;
        try {
            $this->repository->record(self::CALL, CallOutcome::NOT_HELPFUL);
        } catch (Exception) {
            $failed = true;
        } finally {
            $connection->executeStatement(
                'DROP TRIGGER reject_outcome_replacement',
            );
        }

        self::assertTrue(
            $failed,
            'The database must reject the replacement after its prior row could have been deleted.',
        );
        self::assertSame(
            [CallOutcome::HELPFUL, CallOutcome::EDITED],
            $this->repository->findByCorrelation(self::CALL),
        );
        self::assertSame(2, $this->rowCount());
    }

    #[Test]
    public function overlappingSourcesSerializeReplacementWithoutLosingEitherSource(): void
    {
        $connection = $this->connection();
        if (!$connection->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped(
                'This fixture verifies overlapping writers against file-backed SQLite.',
            );
        }

        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $factory = new LockFactory();
        $factory->removeLockingStrategy(
            SemaphoreLockStrategy::class,
        );
        $factory->removeLockingStrategy(
            SimpleLockStrategy::class,
        );
        $repository = new CallOutcomeRepository($pool, $factory);
        $repository->record(self::CALL, CallOutcome::HELPFUL);

        $heldLock = $factory->createLocker(
            'nrllm-outcome-' . substr(
                hash(
                    'sha256',
                    self::CALL . ':' . CallOutcomeSource::EXPLICIT->value,
                ),
                0,
                2,
            ),
        );
        self::assertTrue($heldLock->acquire());
        $worker = <<<'PHP'
            $data = json_decode(trim(fgets(STDIN)), true, 512, JSON_THROW_ON_ERROR);
            require $data['autoload'];
            \TYPO3\CMS\Core\Core\Environment::initialize(
                new \TYPO3\CMS\Core\Core\ApplicationContext('Testing/Functional'),
                true, true, $data['projectPath'], $data['publicPath'],
                $data['varPath'], $data['configPath'], __FILE__, 'UNIX'
            );
            $GLOBALS['TYPO3_CONF_VARS'] = require $data['defaultConfig'];
            // The outcome columns are scalar; avoid a second TYPO3 container's schema cache.
            class OutcomeWorkerConnection extends \TYPO3\CMS\Core\Database\Connection
            {
                protected function ensureDatabaseValueTypes(string $tableName, array &$values, array &$types): void
                {
                    foreach ($values as $name => $value) {
                        $types[$name] = is_int($value) ? \Doctrine\DBAL\ParameterType::INTEGER : \Doctrine\DBAL\ParameterType::STRING;
                    }
                }
            }
            $params = $data['database'];
            $params['wrapperClass'] = OutcomeWorkerConnection::class;
            $connection = \Doctrine\DBAL\DriverManager::getConnection($params);
            $connection->defaultRestrictionContainer = \TYPO3\CMS\Core\Database\Query\Restriction\EmptyRestrictionContainer::class;
            $pool = new class($connection) extends \TYPO3\CMS\Core\Database\ConnectionPool {
                public function __construct(private \TYPO3\CMS\Core\Database\Connection $connection) {}
                public function getConnectionForTable(string $tableName): \TYPO3\CMS\Core\Database\Connection { return $this->connection; }
            };
            $factory = new \TYPO3\CMS\Core\Locking\LockFactory();
            $factory->removeLockingStrategy(\TYPO3\CMS\Core\Locking\SemaphoreLockStrategy::class);
            $factory->removeLockingStrategy(\TYPO3\CMS\Core\Locking\SimpleLockStrategy::class);
            echo "ready\n";
            flush();
            (new \Netresearch\NrLlm\Service\Outcome\CallOutcomeRepository($pool, $factory))
                ->record($data['correlation'], \Netresearch\NrLlm\Domain\Enum\CallOutcome::NOT_HELPFUL);
            echo "done\n";
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $worker],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            fwrite(
                $pipes[0],
                json_encode(
                    [
                        'autoload'      => dirname(__DIR__, 3) . '/.Build/vendor/autoload.php',
                        'defaultConfig' => dirname(__DIR__, 3) . '/.Build/vendor/typo3/cms-core/Configuration/DefaultConfiguration.php',
                        'projectPath'   => Environment::getProjectPath(),
                        'publicPath'    => Environment::getPublicPath(),
                        'varPath'       => Environment::getVarPath(),
                        'configPath'    => Environment::getConfigPath(),
                        'database'      => $connection->getParams(),
                        'correlation'   => self::CALL,
                    ],
                    JSON_THROW_ON_ERROR,
                ) . "\n",
            );
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 5);

            do {
                $ready = fgets($pipes[1]);
            } while ($ready !== false && trim($ready) === '');

            stream_set_blocking($pipes[2], false);
            self::assertSame(
                "ready\n",
                $ready,
                stream_get_contents($pipes[2]),
            );

            $read   = [$pipes[1]];
            $write  = null;
            $except = null;

            $available = stream_select($read, $write, $except, 0, 250000);
            stream_set_blocking($pipes[2], false);
            self::assertSame(
                0,
                $available,
                'A competing writer of the same source must wait for the held lock. ' . stream_get_contents($pipes[2]),
            );

            $repository->record(self::CALL, CallOutcome::EDITED);
            $heldLock->release();
            self::assertSame(
                "done\n",
                fgets($pipes[1]),
                stream_get_contents($pipes[2]),
            );
            self::assertSame('', stream_get_contents($pipes[2]));
            $outcomes = $repository->findByCorrelation(self::CALL);
            self::assertCount(2, $outcomes);
            self::assertContains(CallOutcome::NOT_HELPFUL, $outcomes);
            self::assertContains(CallOutcome::EDITED, $outcomes);
            self::assertSame(2, $this->rowCount());
        } finally {
            $heldLock->release();
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_terminate($process);
            proc_close($process);
        }
    }
}
