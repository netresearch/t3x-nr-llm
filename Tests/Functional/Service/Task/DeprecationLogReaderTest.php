<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Task;

use Netresearch\NrLlm\Service\Task\DeprecationLogReader;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Best-effort tail read of TYPO3's deprecation log for the Task input
 * source: a missing file yields the localized placeholder (never an
 * exception), an existing file yields its tail capped at the requested
 * line count.
 */
#[CoversClass(DeprecationLogReader::class)]
final class DeprecationLogReaderTest extends AbstractFunctionalTestCase
{
    private const LOG_PATH = 'var/log/typo3_deprecations.log';

    private DeprecationLogReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = new DeprecationLogReader();
        $this->removeLog();
    }

    protected function tearDown(): void
    {
        $this->removeLog();
        parent::tearDown();
    }

    #[Test]
    public function missingLogYieldsPlaceholderMessageInsteadOfFailing(): void
    {
        $message = $this->reader->readTail();

        self::assertNotSame('', $message);
        self::assertStringNotContainsString('deprecation entry', $message);
    }

    #[Test]
    public function existingLogIsReturnedVerbatimWhenShorterThanTheCap(): void
    {
        $this->writeLog("first deprecation entry\nsecond deprecation entry");

        $content = $this->reader->readTail();

        self::assertSame("first deprecation entry\nsecond deprecation entry", $content);
    }

    #[Test]
    public function longLogIsCappedToTheRequestedTail(): void
    {
        $lines = [];
        for ($i = 1; $i <= 10; ++$i) {
            $lines[] = 'deprecation entry ' . $i;
        }
        $this->writeLog(implode("\n", $lines));

        $tail = $this->reader->readTail(3);

        self::assertSame(
            "deprecation entry 8\ndeprecation entry 9\ndeprecation entry 10",
            $tail,
        );
    }

    private function logFile(): string
    {
        return GeneralUtility::getFileAbsFileName(self::LOG_PATH);
    }

    private function writeLog(string $content): void
    {
        $file = $this->logFile();
        self::assertNotSame('', $file, 'test instance must resolve the log path');
        GeneralUtility::mkdir_deep(dirname($file));
        file_put_contents($file, $content);
    }

    private function removeLog(): void
    {
        $file = $this->logFile();
        if ($file !== '' && file_exists($file)) {
            unlink($file);
        }
    }
}
