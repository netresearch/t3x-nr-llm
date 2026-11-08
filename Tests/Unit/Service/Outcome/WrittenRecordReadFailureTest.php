<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Outcome;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Service\Outcome\WrittenRecordRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(WrittenRecordRepository::class)]
final class WrittenRecordReadFailureTest extends TestCase
{
    #[Test]
    public function unavailableStorageIsNotAConfirmedMissingRecord(): void
    {
        $pool    = $this->createMock(ConnectionPool::class);
        $failure = new RuntimeException('Database temporarily unavailable');
        $pool
            ->expects(self::once())
            ->method('getQueryBuilderForTable')
            ->with('pages')
            ->willThrowException($failure);
        $subject = new WrittenRecordRepository($pool);

        $this->expectExceptionObject($failure);
        $subject->recordExists(new RecordReference('pages', 42));
    }
}
