<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpServerRecord::class)]
final class McpServerRecordTest extends TestCase
{
    /**
     * The only value that means "no approval" is the explicit 0 an operator
     * writes by unticking the box (ADR-134). Everything else — a column this
     * version cannot read, a NULL that arrived as '', a value from a newer
     * schema — reads as "approval required", because the alternative is an
     * unreadable byte silently letting an unattended remote write through.
     */
    #[Test]
    #[DataProvider('storedFlags')]
    public function readsAnythingButAnExplicitZeroAsApprovalRequired(string $stored, bool $expected): void
    {
        self::assertSame($expected, $this->record($stored)->approvalRequired());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function storedFlags(): iterable
    {
        yield 'explicit off'                 => ['0', false];
        yield 'explicit on'                  => ['1', true];
        yield 'missing column, hydrated as empty' => ['', true];
        yield 'garbage'                      => ['maybe', true];
        yield 'unknown future value'         => ['2', true];
        yield 'zero that is not the integer' => ['0.0', true];
    }

    private function record(string $requiresApproval): McpServerRecord
    {
        return new McpServerRecord(
            uid: 1,
            pid: 0,
            identifier: 'srv',
            name: 'A server',
            description: '',
            url: 'https://mcp.example.com/rpc',
            authCredential: '',
            authPlacement: 'bearer',
            authHeaderName: '',
            dataClass: 'publicContent',
            requiresApproval: $requiresApproval,
            enabled: true,
            importStatus: 'ok',
            importError: '',
            lastImported: 0,
            toolCount: 0,
            tstamp: 0,
            crdate: 0,
        );
    }
}
