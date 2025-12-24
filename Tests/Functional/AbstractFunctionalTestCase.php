<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional;

use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base class for functional tests
 *
 * Sets up the nr_llm extension and common test utilities
 * for testing TYPO3 database operations and services.
 *
 * Requirements:
 * - Database credentials must be configured via environment variables or LocalConfiguration
 * - Run tests with: .Build/bin/phpunit -c Build/FunctionalTests.xml
 *
 * Environment variables for database:
 * - typo3DatabaseDriver: pdo_sqlite, mysqli, or pdo_mysql
 * - typo3DatabaseName: database name (not needed for SQLite)
 * - typo3DatabaseHost: database host (not needed for SQLite)
 * - typo3DatabaseUsername: database username (not needed for SQLite)
 * - typo3DatabasePassword: database password (not needed for SQLite)
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    /**
     * Extensions to load for functional tests
     *
     * @var non-empty-string[]
     */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-llm',
    ];

    /**
     * Core extensions required for testing
     *
     * @var non-empty-string[]
     */
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
    ];

    /**
     * Initialize the test database with schema
     */
    protected bool $initializeDatabase = true;

    private bool $skipped = false;

    protected function setUp(): void
    {
        // Check if we can run functional tests
        if (!$this->canRunFunctionalTests()) {
            $this->skipped = true;
            $this->markTestSkipped(
                'Functional tests require database configuration. '
                . 'Set typo3DatabaseDriver environment variable (e.g., pdo_sqlite) to enable.'
            );
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->skipped) {
            return;
        }
        parent::tearDown();
    }

    /**
     * Check if functional tests can run
     *
     * Functional tests require either:
     * - Environment variables for database configuration
     * - An existing LocalConfiguration.php with database settings
     */
    private function canRunFunctionalTests(): bool
    {
        // Check for environment-based configuration
        if (getenv('typo3DatabaseDriver')) {
            return true;
        }

        // Check for LocalConfiguration
        $localConfigPath = dirname(__DIR__, 2) . '/typo3conf/LocalConfiguration.php';
        if (file_exists($localConfigPath)) {
            return true;
        }

        return false;
    }

    /**
     * Import a CSV dataset from the Fixtures directory
     */
    protected function importFixture(string $filename): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/' . $filename);
    }

    /**
     * Get the connection pool for direct database queries
     */
    protected function getConnection(): \TYPO3\CMS\Core\Database\Connection
    {
        return $this->getConnectionPool()->getConnectionForTable('tx_nrllm_configuration');
    }
}
