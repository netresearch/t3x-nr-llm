<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetFullTcaTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for GetFullTcaTool (ADR-045): the TCA index lists accessible
 * tables, omits sensitive ones even for admins, and honours the filter.
 */
#[CoversClass(GetFullTcaTool::class)]
final class GetFullTcaToolTest extends AbstractFunctionalTestCase
{
    private GetFullTcaTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->tool = new GetFullTcaTool(new TableReadAccessService());
    }

    #[Test]
    public function listsCoreTablesAndOmitsSensitiveOnesForAdmin(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute([]);

        self::assertStringContainsString('pages', $output);
        self::assertStringContainsString('tt_content', $output);
        self::assertStringContainsString('get_table_schema(table)', $output);
        // Sensitive tables never appear, even for an admin.
        self::assertStringNotContainsString('be_users', $output);
        self::assertStringNotContainsString('sys_log', $output);
    }

    #[Test]
    public function filterNarrowsTheList(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute(['filter' => 'tt_content']);

        self::assertStringContainsString('tt_content', $output);
        self::assertStringNotContainsString("\npages —", $output);
    }

    #[Test]
    public function noBackendUserYieldsEmptyIndex(): void
    {
        self::assertStringContainsString('(0)', $this->tool->execute([]));
    }

    #[Test]
    public function listedTablesAreSortedAlphabetically(): void
    {
        $this->setUpBackendUser(1);

        $output = $this->tool->execute([]);

        // Drop the header line; every remaining line starts with a table name.
        $lines = explode("\n", $output);
        array_shift($lines);
        $names = array_map(
            static fn(string $line): string => explode(' — ', $line)[0],
            array_filter($lines, static fn(string $line): bool => $line !== ''),
        );

        $sorted = $names;
        sort($sorted);
        // Deterministic (alphabetical) order, independent of TCA load order.
        self::assertSame($sorted, $names);
    }
}
