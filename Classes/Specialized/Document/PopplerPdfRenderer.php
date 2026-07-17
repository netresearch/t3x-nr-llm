<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Document;

use Netresearch\NrLlm\Specialized\Exception\PdfRasterizationException;
use Throwable;

/**
 * poppler-backed PDF rasterizer: `pdfimages -list` to find image-bearing
 * pages, `pdftoppm` to rasterize a single page or the whole document to PNG.
 * Invoked via proc_open with an ARRAY argv so no shell parses the path (no
 * command-injection surface).
 *
 * Ported from nr_ai_search's PopplerPdfRenderer (its ADR-034 renderer) with
 * the whole-document `renderDocument()` added for the ADR-076 rasterization
 * fallback. The poppler binaries are an OPTIONAL system dependency (see
 * composer.json `suggest`); a missing binary or a non-zero exit raises
 * PdfRasterizationException.
 */
final readonly class PopplerPdfRenderer implements PdfRasterizerInterface
{
    private const RENDER_DPI = 150;

    public function imagePages(string $absolutePath): array
    {
        $listing = $this->run(['pdfimages', '-list', $absolutePath]);
        $pages = [];
        foreach (explode("\n", $listing) as $index => $line) {
            if ($index < 2) {
                // First two lines are the column header and its underline rule.
                continue;
            }

            if (preg_match('/^\s*(\d+)\s/', $line, $matches) === 1) {
                $pages[(int)$matches[1]] = true;
            }
        }

        $pages = array_keys($pages);
        sort($pages);

        return $pages;
    }

    public function renderPage(string $absolutePath, int $page): string
    {
        $rendered = $this->rasterize(
            $absolutePath,
            ['-f', (string)$page, '-l', (string)$page],
            sprintf('pdftoppm produced no output for page %d.', $page),
        );

        $png = reset($rendered);
        if ($png === false) {
            throw new PdfRasterizationException(sprintf('pdftoppm produced no output for page %d.', $page), 1784211013);
        }

        return $png;
    }

    public function renderDocument(string $absolutePath): array
    {
        return $this->rasterize($absolutePath, [], 'pdftoppm produced no output for the document.');
    }

    public function isAvailable(): bool
    {
        try {
            $this->run(['pdftoppm', '-v']);
            $this->run(['pdfimages', '-v']);

            return true;
        } catch (PdfRasterizationException) {
            return false;
        }
    }

    /**
     * Run pdftoppm over the document (optionally restricted via `-f`/`-l`
     * arguments) and collect the produced PNGs keyed by 1-based page number.
     *
     * @param list<string> $rangeArguments
     *
     * @throws PdfRasterizationException
     *
     * @return array<int, string> PNG bytes per page, ascending
     */
    private function rasterize(string $absolutePath, array $rangeArguments, string $emptyOutputMessage): array
    {
        $stub = tempnam(sys_get_temp_dir(), 'nrllm_pdf_');
        if ($stub === false) {
            throw new PdfRasterizationException('Unable to allocate a temporary file for PDF rendering.', 1784211004);
        }

        // pdftoppm writes "<stub>-<page>.png"; the stub itself must not collide.
        // Path comes from tempnam() above, not user input.
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        unlink($stub);

        try {
            $this->run([
                'pdftoppm',
                '-png',
                '-r',
                (string)self::RENDER_DPI,
                ...$rangeArguments,
                $absolutePath,
                $stub,
            ]);
            $rendered = glob($stub . '*.png');
            if ($rendered === false || $rendered === []) {
                throw new PdfRasterizationException($emptyOutputMessage, 1784211005);
            }

            $pages = [];
            foreach ($rendered as $file) {
                if (preg_match('/-(\d+)\.png$/', $file, $matches) !== 1) {
                    continue;
                }

                $png = file_get_contents($file);
                if ($png === false || $png === '') {
                    throw new PdfRasterizationException(sprintf('Rendered page %d could not be read.', (int)$matches[1]), 1784211006);
                }

                $pages[(int)$matches[1]] = $png;
            }

            if ($pages === []) {
                throw new PdfRasterizationException($emptyOutputMessage, 1784211014);
            }

            ksort($pages);

            return $pages;
        } finally {
            $leftovers = glob($stub . '*.png');
            foreach ($leftovers === false ? [] : $leftovers as $leftover) {
                if (is_file($leftover)) {
                    // Path comes from glob() over the tempnam() stub, not user input.
                    // nosemgrep: php.lang.security.unlink-use.unlink-use
                    unlink($leftover);
                }
            }
        }
    }

    /**
     * @param list<string> $command
     *
     * @throws PdfRasterizationException on spawn failure or non-zero exit
     */
    private function run(array $command): string
    {
        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        } catch (Throwable $e) {
            // TYPO3's error handler can turn proc_open()'s "binary not found" warning
            // into an exception; treat that the same as a false return.
            throw new PdfRasterizationException(sprintf('Could not start "%s" - is poppler installed?', $command[0]), 1784211007, $e);
        }

        if (!is_resource($process)) {
            throw new PdfRasterizationException(sprintf('Could not start "%s" - is poppler installed?', $command[0]), 1784211015);
        }

        $stdout = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // `-v` prints its banner to stderr and exits 0; a missing sub-binary or a
        // real failure returns non-zero. poppler's -v exits 0, so this is a clean gate.
        if ($exitCode !== 0) {
            throw new PdfRasterizationException(sprintf('"%s" exited with code %d.', $command[0], $exitCode), 1784211008);
        }

        return $stdout;
    }
}
