<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Document;

use ErrorException;
use Netresearch\NrLlm\Specialized\Document\PopplerPdfRenderer;
use Netresearch\NrLlm\Specialized\Exception\PdfRasterizationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises real process invocation through offline Poppler fixture binaries.
 */
#[CoversClass(PopplerPdfRenderer::class)]
final class PopplerPdfRendererTest extends TestCase
{
    private string $directory;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/nrllm_poppler_test_' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0o700));

        foreach (['PATH', 'NRLLM_POPPLER_TEST_OUTPUT', 'NRLLM_POPPLER_TEST_MODE'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
        }

        putenv('PATH=' . $this->directory);
        putenv('NRLLM_POPPLER_TEST_OUTPUT=' . $this->directory);
        putenv('NRLLM_POPPLER_TEST_MODE=normal');
        $this->installBinary('pdftoppm');
        $this->installBinary('pdfimages');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }

        $argv = $this->arguments('argv.log');
        $prefix = $argv === [] ? null : $argv[array_key_last($argv)];
        if (is_string($prefix) && str_starts_with($prefix, sys_get_temp_dir() . '/nrllm_pdf_')) {
            foreach (glob($prefix . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    #[Test]
    public function availabilityRequiresBothExecutables(): void
    {
        $renderer = new PopplerPdfRenderer();
        self::assertTrue($renderer->isAvailable());

        self::assertTrue(unlink($this->directory . '/pdfimages'));
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            },
        );
        try {
            self::assertFalse($renderer->isAvailable());
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function imagePagesSkipsHeadersDeduplicatesAndSorts(): void
    {
        $path = $this->directory . '/an image-bearing document.pdf';

        self::assertSame([2, 5], (new PopplerPdfRenderer())->imagePages($path));
        self::assertSame(['-list', $path], $this->arguments('image-argv.log'));
    }

    #[Test]
    public function wholeDocumentUsesLiteralArgumentsAndReturnsOrderedBytesWithNoTemporaryFiles(): void
    {
        $unexpected = $this->directory . '/unexpected';
        $path = $this->directory . '/source with spaces;$(touch ' . $unexpected . ').pdf';

        self::assertSame([1 => 'page-one', 2 => 'page-two'], (new PopplerPdfRenderer())->renderDocument($path));
        $arguments = $this->arguments('argv.log');

        self::assertCount(5, $arguments);
        self::assertSame(['-png', '-r', '150', $path], array_slice($arguments, 0, 4));
        self::assertStringStartsWith(sys_get_temp_dir() . '/nrllm_pdf_', $arguments[4]);
        self::assertFileDoesNotExist($unexpected);
        $this->assertTemporaryFilesRemoved($arguments[4]);
    }

    #[Test]
    public function aSinglePagePassesTheSameFirstAndLastPageAndReturnsItsBytes(): void
    {
        putenv('NRLLM_POPPLER_TEST_MODE=page');
        $path = $this->directory . '/source.pdf';

        self::assertSame('page-seven', (new PopplerPdfRenderer())->renderPage($path, 7));
        $arguments = $this->arguments('argv.log');

        self::assertCount(9, $arguments);
        self::assertSame(['-png', '-r', '150', '-f', '7', '-l', '7', $path], array_slice($arguments, 0, 8));
        $this->assertTemporaryFilesRemoved($arguments[8]);
    }

    #[Test]
    public function stdoutAndStderrAreBothDrainedBeforeWaitingForTheChild(): void
    {
        putenv('NRLLM_POPPLER_TEST_MODE=flood');

        try {
            self::assertSame(
                [1 => 'page-one', 2 => 'page-two'],
                (new PopplerPdfRenderer())->renderDocument('/source.pdf'),
            );
        } catch (PdfRasterizationException $e) {
            self::fail(
                'Both pipe writers must complete before the fixture timeout: ' . $e->getMessage(),
            );
        }

        $arguments = $this->arguments('argv.log');
        self::assertCount(5, $arguments);
        $this->assertTemporaryFilesRemoved($arguments[4]);
    }

    #[Test]
    public function processFailureIsTypedAndItsPartialOutputIsRemoved(): void
    {
        putenv('NRLLM_POPPLER_TEST_MODE=fail');

        try {
            (new PopplerPdfRenderer())->renderDocument('/source.pdf');
            self::fail('A nonzero process exit must be rejected.');
        } catch (PdfRasterizationException $e) {
            self::assertSame(1784211008, $e->getCode());
            self::assertStringContainsString('code 23', $e->getMessage());
            self::assertStringContainsString('controlled renderer failure', $e->getMessage());
        }

        $arguments = $this->arguments('argv.log');
        self::assertCount(5, $arguments);
        $this->assertTemporaryFilesRemoved($arguments[4]);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unusableOutput(): iterable
    {
        yield 'no rendered files' => ['empty', 1784211005];
        yield 'empty PNG bytes' => ['unreadable', 1784211006];
        yield 'no numbered page filename' => ['malformed', 1784211014];
    }

    #[Test]
    #[DataProvider('unusableOutput')]
    public function unusableOutputIsTypedAndCleaned(string $mode, int $code): void
    {
        putenv('NRLLM_POPPLER_TEST_MODE=' . $mode);

        try {
            (new PopplerPdfRenderer())->renderDocument('/source.pdf');
            self::fail('Unusable rasterization output must be rejected.');
        } catch (PdfRasterizationException $e) {
            self::assertSame($code, $e->getCode());
        }

        $arguments = $this->arguments('argv.log');
        self::assertCount(5, $arguments);
        $this->assertTemporaryFilesRemoved($arguments[4]);
    }

    private function installBinary(string $name): void
    {
        self::assertTrue(copy(__DIR__ . '/Fixtures/' . $name . '.sh', $this->directory . '/' . $name));
        self::assertTrue(chmod($this->directory . '/' . $name, 0o700));
    }

    /**
     * @return list<string>
     */
    private function arguments(string $filename): array
    {
        $path = $this->directory . '/' . $filename;
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        return $lines === false ? [] : $lines;
    }

    private function assertTemporaryFilesRemoved(string $prefix): void
    {
        self::assertFileDoesNotExist($prefix, 'The tempnam reservation must be removed.');
        self::assertSame([], glob($prefix . '*.png'), 'Every rasterized file must be removed.');
    }
}
