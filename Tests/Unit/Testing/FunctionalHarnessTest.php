<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use TYPO3\TestingFramework\Core\Testbase;

/**
 * Actual child-PHPUnit outcomes, including TYPO3 bootstrap and teardown.
 */
#[CoversNothing]
final class FunctionalHarnessTest extends TestCase
{
    /** @var list<Process> */
    private array $children = [];

    /** @var list<non-empty-string> */
    private array $instancePaths = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->children as $child) {
                if ($child->isRunning()) {
                    $child->stop(0.1);
                }
            }

            $testbase = new Testbase();
            foreach ($this->instancePaths as $instancePath) {
                $testbase->removeOldInstanceIfExists($instancePath);
                self::assertDirectoryDoesNotExist($instancePath);
            }
        } finally {
            parent::tearDown();
        }
    }

    #[Test]
    public function configuredBootstrapFailureMakesTheFunctionalRunFail(): void
    {
        $process = $this->runFixture('BrokenBootstrapTest.php');
        $output = $process->getOutput() . $process->getErrorOutput();
        self::assertSame(2, $process->getExitCode(), $output);
        self::assertStringContainsString('audit_missing_extension', $output);
        self::assertStringContainsString('Errors: 1', $output);
    }

    #[Test]
    public function teardownFailureAfterSuccessfulSetupMakesTheRunFail(): void
    {
        $process = $this->runFixture('BrokenTeardownTest.php');
        $output = $process->getOutput() . $process->getErrorOutput();
        self::assertSame(1, $process->getExitCode(), $output);
        self::assertStringContainsString(
            'tearDown() integrity check found changed error_reporting',
            $output,
        );
        self::assertStringContainsString('Failures: 1', $output);
    }

    #[Test]
    public function successfulBootstrapAndCleanTeardownExecuteTheTest(): void
    {
        $process = $this->runFixture('SuccessfulLifecycleTest.php');
        $output = $process->getOutput() . $process->getErrorOutput();
        self::assertSame(0, $process->getExitCode(), $output);
        self::assertStringContainsString('OK (1 test, 1 assertion)', $output);
    }

    #[Test]
    public function absentDatabaseConfigurationStillSkipsDeliberately(): void
    {
        $process = $this->runFixture('SuccessfulLifecycleTest.php', false);
        $output = $process->getOutput() . $process->getErrorOutput();
        self::assertSame(0, $process->getExitCode(), $output);
        self::assertStringContainsString(
            'Functional tests require database configuration.',
            $output,
        );
        self::assertStringContainsString('Skipped: 1', $output);
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function concurrentFixtures(): array
    {
        return [
            'success' => ['SuccessfulLifecycleTest.php', 0, 'OK (1 test, 1 assertion)'],
            'bootstrap failure' => ['BrokenBootstrapTest.php', 2, 'audit_missing_extension'],
        ];
    }

    #[Test]
    #[DataProvider('concurrentFixtures')]
    public function concurrentChildrenKeepTheirOwnInstances(
        string $fixture,
        int $expectedExit,
        string $expectedMessage,
    ): void {
        for ($round = 0; $round < 3; $round++) {
            $children = [
                $this->createFixtureProcess($fixture),
                $this->createFixtureProcess($fixture),
            ];
            foreach ($children as $child) {
                $child->start();
            }

            foreach ($children as $child) {
                $this->waitForFixture($child);
                $output = $child->getOutput() . $child->getErrorOutput();
                self::assertSame($expectedExit, $child->getExitCode(), $output);
                self::assertStringContainsString($expectedMessage, $output);
            }
        }
    }

    private function runFixture(
        string $fixture,
        bool $databaseConfigured = true,
    ): Process {
        $process = $this->createFixtureProcess($fixture, $databaseConfigured);
        $process->start();
        $this->waitForFixture($process);
        return $process;
    }

    private function createFixtureProcess(
        string $fixture,
        bool $databaseConfigured = true,
    ): Process {
        $project = dirname(__DIR__, 3);
        $identifier = bin2hex(random_bytes(16));
        $webRoot = $project . '/.Build/Web';
        $this->instancePaths[] = $webRoot . '/typo3temp/var/tests/functional-' . $identifier;
        $process = new Process(
            [
                PHP_BINARY,
                $project . '/.Build/bin/phpunit',
                '--no-configuration',
                '--bootstrap',
                $project . '/Build/FunctionalTestsBootstrap.php',
                '--colors=never',
                '--display-skipped',
                $project . '/Tests/Fixtures/FunctionalHarness/' . $fixture,
            ],
            $project,
            [
                'typo3DatabaseDriver' => $databaseConfigured ? 'pdo_sqlite' : false,
                'TYPO3_PATH_WEB' => $webRoot,
                'NR_LLM_FUNCTIONAL_FIXTURE_ID' => $identifier,
            ],
        );
        $process->setTimeout(30);
        $this->children[] = $process;
        return $process;
    }

    private function waitForFixture(Process $process): void
    {
        try {
            $process->wait();
        } catch (ProcessTimedOutException $failure) {
            self::fail(
                'The functional harness fixture exceeded its bounded runtime: ' . $failure->getMessage(),
            );
        }
    }
}
