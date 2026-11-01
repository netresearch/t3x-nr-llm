<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\EventListener;

use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\EventListener\MarkAiWrittenRecord;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The fact of an AI write on the row (ADR-239), through the real dispatcher, so
 * the listener's registration is part of what is tested.
 */
#[CoversClass(MarkAiWrittenRecord::class)]
final class MarkAiWrittenRecordTest extends AbstractFunctionalTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function labelledTables(): iterable
    {
        yield 'pages' => ['pages'];
        yield 'tt_content' => ['tt_content'];
    }

    #[Test]
    #[DataProvider('labelledTables')]
    public function aWriteMarksTheRowAndTouchesNothingElse(string $table): void
    {
        $this->row($table, 7);

        $this->dispatch($table, 7, WriteKind::UPDATED);

        self::assertSame(1, $this->mark($table, 7));
        self::assertSame(1234, (int) $this->connection($table)->select(['tstamp'], $table, ['uid' => 7])->fetchOne());
        self::assertSame(0, $this->connection('sys_history')->count('uid', 'sys_history', []));
    }

    #[Test]
    public function aCreatedRecordIsMarked(): void
    {
        $this->row('tt_content', 8);

        $this->dispatch('tt_content', 8, WriteKind::CREATED);

        self::assertSame(1, $this->mark('tt_content', 8));
    }

    #[Test]
    public function onlyTheWrittenRowIsMarked(): void
    {
        $this->row('tt_content', 8);
        $this->row('tt_content', 9);

        $this->dispatch('tt_content', 8, WriteKind::UPDATED);

        self::assertSame(0, $this->mark('tt_content', 9));
    }

    #[Test]
    public function aMovedPublishedOrCopiedRecordIsNotMarked(): void
    {
        $this->row('tt_content', 8);

        $this->dispatch('tt_content', 8, WriteKind::TEXT_UNCHANGED);

        self::assertSame(0, $this->mark('tt_content', 8));
    }

    #[Test]
    public function aDeletionMarksNothing(): void
    {
        $this->row('tt_content', 8);

        $this->dispatch('tt_content', 8, WriteKind::DELETED);

        self::assertSame(0, $this->mark('tt_content', 8));
    }

    #[Test]
    public function aWriteToAnotherTableIsLeftAlone(): void
    {
        $this->dispatch('sys_category', 3, WriteKind::UPDATED);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aClearedFieldStaysClearedUntilTheNextWrite(): void
    {
        $this->row('pages', 7);
        $this->dispatch('pages', 7, WriteKind::UPDATED);

        $this->connection('pages')->update('pages', ['tx_nrllm_ai_written' => 0], ['uid' => 7]);
        self::assertSame(0, $this->mark('pages', 7));

        $this->dispatch('pages', 7, WriteKind::UPDATED);
        self::assertSame(1, $this->mark('pages', 7));
    }

    #[Test]
    public function theFieldSitsNextToHiddenOnBothTables(): void
    {
        self::assertStringContainsString('tx_nrllm_ai_written', $this->tcaString(['tt_content', 'palettes', 'hidden', 'showitem']));
        self::assertStringContainsString('tx_nrllm_ai_written', $this->tcaString(['pages', 'types', '1', 'showitem']));
    }

    /**
     * @param list<string> $path
     */
    private function tcaString(array $path): string
    {
        $value = $GLOBALS['TCA'];
        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? '') : '';
        }

        return is_string($value) ? $value : '';
    }

    private function dispatch(string $table, int $uid, WriteKind $kind): void
    {
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new AfterAiRecordWrittenEvent('run-uuid', new RecordReference($table, $uid), $kind));
    }

    private function row(string $table, int $uid): void
    {
        $this->connection($table)->insert($table, ['uid' => $uid, 'pid' => 0, 'tstamp' => 1234]);
    }

    private function mark(string $table, int $uid): int
    {
        return (int) $this->connection($table)->select(['tx_nrllm_ai_written'], $table, ['uid' => $uid])->fetchOne();
    }

    private function connection(string $table): Connection
    {
        $pool = $this->getService(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool->getConnectionForTable($table);
    }
}
