<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\FetchLogsTool;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for FetchLogsTool.
 *
 * Verifies the sys_log read path: newest-first ordering, the hard 50-row
 * cap, the optional level filter and — load-bearing — that PII fields
 * (raw IP, backend user id, serialized payload) never reach the formatted
 * output that egresses to an external LLM.
 */
#[CoversClass(FetchLogsTool::class)]
final class FetchLogsToolTest extends AbstractFunctionalTestCase
{
    private const RAW_IP = '203.0.113.55';
    private const USER_ID = '4242';
    private const PAYLOAD_USERNAME = 'secretadmin';

    private FetchLogsTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->tool = new FetchLogsTool($connectionPool);
    }

    #[Test]
    public function getSpecDeclaresFetchLogsFunction(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('fetch_logs', $spec->name);
        self::assertSame('object', $spec->parameters['type'] ?? null);

        $properties = $spec->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('level', $properties);
        self::assertArrayHasKey('limit', $properties);
    }

    #[Test]
    public function returnsNewestEntriesCappedByLimit(): void
    {
        $this->importFixture('sys_log_tools.csv');

        $output = $this->tool->execute(['limit' => 2])->content;

        self::assertStringContainsString('Cache cleared', $output);
        self::assertStringContainsString('Login failed', $output);
        self::assertStringNotContainsString('User authenticated', $output);
        self::assertStringNotContainsString('Page content updated', $output);
        self::assertCount(2, $this->entryLines($output));
    }

    #[Test]
    public function redactsRawIpUserIdAndPayloadUsername(): void
    {
        $this->importFixture('sys_log_tools.csv');

        $output = $this->tool->execute(['limit' => 10])->content;

        self::assertStringNotContainsString(self::RAW_IP, $output);
        self::assertStringNotContainsString(self::USER_ID, $output);
        self::assertStringNotContainsString(self::PAYLOAD_USERNAME, $output);
    }

    #[Test]
    public function levelFilterRestrictsToMatchingEntries(): void
    {
        $this->importFixture('sys_log_tools.csv');

        $output = $this->tool->execute(['level' => 'error'])->content;

        self::assertStringContainsString('Login failed', $output);
        self::assertStringNotContainsString('Cache cleared', $output);
    }

    #[Test]
    public function hardCapsResultsAtFiftyEvenWhenLimitHigher(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_log');
        self::assertInstanceOf(Connection::class, $connection);

        for ($i = 1; $i <= 60; ++$i) {
            $connection->insert('sys_log', [
                'tstamp'   => 1799990000 + $i,
                'userid'   => 0,
                'type'     => 1,
                'action'   => 1,
                'error'    => 0,
                'level'    => 'info',
                'details'  => 'bulk entry ' . $i,
                'IP'       => '',
                'log_data' => '',
            ]);
        }

        $output = $this->tool->execute(['limit' => 9999])->content;

        self::assertCount(50, $this->entryLines($output));
    }

    #[Test]
    public function returnsPlaceholderWhenNoEntriesMatch(): void
    {
        $output = $this->tool->execute(['level' => 'no-such-level'])->content;

        self::assertSame('No log entries.', $output);
    }

    /**
     * Formatted log rows each begin with a "[" date bracket; the header line
     * does not. Counting them yields the number of returned entries.
     *
     * @return list<string>
     */
    private function entryLines(string $output): array
    {
        return array_values(array_filter(
            explode("\n", $output),
            static fn(string $line): bool => str_starts_with($line, '['),
        ));
    }
}
