<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Parses realistic TYPO3 FileWriter records (ADR-044): error levels only,
 * newest first, exception class + throw site + stack frames extracted from
 * the JSON data suffix, search and time-window filters applied.
 */
#[CoversClass(LogExceptionReader::class)]
final class LogExceptionReaderTest extends TestCase
{
    private string $logDir;

    private LogExceptionReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . '/nrllm-logs-' . bin2hex(random_bytes(4));
        mkdir($this->logDir, 0o777, true);

        $exceptionJson = json_encode([
            'exception' => "JsonException: Malformed UTF-8 characters in /app/Classes/Provider/AbstractProvider.php:282\n"
                . "Stack trace:\n"
                . "#0 /app/Classes/Provider/AbstractProvider.php(282): json_encode()\n"
                . '#1 /app/vendor/typo3/cms-core/Classes/Http/Dispatcher.php(50): run()',
        ], JSON_THROW_ON_ERROR);

        file_put_contents(
            $this->logDir . '/typo3_abc.log',
            'Mon, 06 Jul 2026 01:10:00 +0200 [INFO] request="r1" component="Some.Component": all fine' . "\n"
            . 'Mon, 06 Jul 2026 01:12:00 +0200 [ERROR] request="r2" component="Vendor.Ext.Older": older failure' . "\n"
            . 'Mon, 06 Jul 2026 01:13:18 +0200 [ERROR] request="r3" component="Netresearch.NrLlm.Controller": '
            . 'Tool playground run failed - ' . $exceptionJson . "\n"
            . 'Mon, 06 Jul 2026 01:14:00 +0200 [WARNING] request="r4" component="Some.Component": just a warning' . "\n",
        );

        $this->reader = new LogExceptionReader($this->logDir);
    }

    #[Test]
    public function returnsOnlyErrorLevelsNewestFirst(): void
    {
        $entries = $this->reader->read(10);

        self::assertCount(2, $entries);
        self::assertSame('Netresearch.NrLlm.Controller', $entries[0]->component);
        self::assertSame('Vendor.Ext.Older', $entries[1]->component);
        self::assertGreaterThan($entries[1]->timestamp, $entries[0]->timestamp);
    }

    #[Test]
    public function parsesExceptionClassThrowSiteAndFrames(): void
    {
        $entry = $this->reader->read(1)[0];

        self::assertSame('JsonException', $entry->exceptionClass);
        self::assertSame('Tool playground run failed', $entry->message);
        self::assertNotSame([], $entry->frames);
        // Throw site first, then the numbered frames.
        self::assertSame('/app/Classes/Provider/AbstractProvider.php', $entry->frames[0]['file']);
        self::assertSame(282, $entry->frames[0]['line']);
        self::assertSame('(throw site)', $entry->frames[0]['call']);
        self::assertSame('json_encode()', $entry->frames[1]['call']);
    }

    #[Test]
    public function searchFiltersByMessageComponentOrClass(): void
    {
        self::assertCount(1, $this->reader->read(10, 'playground'));
        self::assertCount(1, $this->reader->read(10, 'jsonexception'));
        self::assertCount(1, $this->reader->read(10, 'Older'));
        self::assertCount(0, $this->reader->read(10, 'no-such-thing'));
    }

    #[Test]
    public function timeWindowFilters(): void
    {
        $probe = (int)strtotime('Mon, 06 Jul 2026 01:13:20 +0200');

        $entries = $this->reader->read(10, null, $probe - 30, $probe + 30);

        self::assertCount(1, $entries);
        self::assertSame('JsonException', $entries[0]->exceptionClass);
    }

    #[Test]
    public function emptyDirectoryYieldsNoEntries(): void
    {
        $empty = sys_get_temp_dir() . '/nrllm-logs-empty-' . bin2hex(random_bytes(4));
        mkdir($empty, 0o777, true);

        self::assertSame([], (new LogExceptionReader($empty))->read());

        rmdir($empty);
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->logDir, true);
        parent::tearDown();
    }
}
