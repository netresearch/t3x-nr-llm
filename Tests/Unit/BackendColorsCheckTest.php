<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Drives Build/Scripts/check-backend-colors.php against throwaway trees.
 *
 * The check refuses colour that ignores the TYPO3 backend colour scheme. Each
 * refusal case is a shape that shipped and broke the dark (or light) scheme:
 * `btn-secondary` drawn as a dark tile, `text-bg-success` at 3.6:1, a
 * `bg-light` card under light text, an unguarded prefers-color-scheme block.
 */
#[CoversNothing]
final class BackendColorsCheckTest extends AbstractUnitTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/nrllm-colors-check-' . bin2hex(random_bytes(6));
        foreach (['Resources/Private/Templates', 'Resources/Public/Css/Backend', 'Resources/Public/JavaScript/Backend'] as $dir) {
            mkdir($this->root . '/' . $dir, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refused(): array
    {
        return [
            'hex in CSS' => ['Resources/Public/Css/Backend/a.css', ".x { color: #6b7280; }\n", 'colour literal'],
            'rgba in CSS' => ['Resources/Public/Css/Backend/a.css', ".x { background: rgba(0, 0, 0, .1); }\n", 'colour literal'],
            'named colour in CSS' => ['Resources/Public/Css/Backend/a.css', ".x { color: white; }\n", 'named colour'],
            'hex in JS' => ['Resources/Public/JavaScript/Backend/a.js', "el.style.color = '#888';\n", 'colour literal'],
            'inline style hex' => ['Resources/Private/Templates/a.html', "<pre style=\"background:#f5f5f5\"></pre>\n", 'colour literal'],
            'Bootstrap variable' => ['Resources/Public/JavaScript/Backend/a.js', "el.style.backgroundColor = 'var(--bs-body-bg)';\n", 'Bootstrap variable'],
            'unguarded media query' => ['Resources/Public/Css/Backend/a.css', "@media (prefers-color-scheme: dark) { .x { color: var(--y); } }\n", 'own scheme switch'],
            'explicit scheme selector' => ['Resources/Public/Css/Backend/a.css', "[data-color-scheme=\"dark\"] .x { color: var(--y); }\n", 'own scheme switch'],
            'btn-secondary' => ['Resources/Private/Templates/a.html', "<a class=\"btn btn-secondary\">x</a>\n", 'scheme-pinned class'],
            'text-bg badge' => ['Resources/Private/Templates/a.html', "<span class=\"badge text-bg-success\">x</span>\n", 'scheme-pinned class'],
            'bg-light card' => ['Resources/Private/Templates/a.html', "<div class=\"card bg-light\">x</div>\n", 'scheme-pinned class'],
            'bg-body-tertiary' => ['Resources/Private/Templates/a.html', "<pre class=\"bg-body-tertiary\">x</pre>\n", 'scheme-pinned class'],
            'subtle background' => ['Resources/Public/JavaScript/Backend/a.js', "html = '<blockquote class=\"bg-success-subtle\"></blockquote>';\n", 'scheme-pinned class'],
            'text-dark' => ['Resources/Private/Templates/a.html', "<span class=\"text-dark\">x</span>\n", 'scheme-pinned class'],
            'Bootstrap colour on a badge' => ['Resources/Public/JavaScript/Backend/a.js', "badge.className = 'badge bg-success ms-1';\n", 'Bootstrap colour on a badge'],
        ];
    }

    #[Test]
    #[DataProvider('refused')]
    public function refusesColourThatIgnoresTheScheme(string $file, string $content, string $rule): void
    {
        $this->write($file, $content);

        [$exit, $stderr] = $this->check();

        self::assertSame(1, $exit, $stderr);
        self::assertStringContainsString($file . ':1  ' . $rule . ':', $stderr);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function accepted(): array
    {
        return [
            'core variable' => ['Resources/Public/Css/Backend/a.css', ".x { color: var(--typo3-text-color-base); }\n"],
            'light-dark pair' => ['Resources/Public/Css/Backend/a.css', ".x { --b: light-dark(#a85800, #ff9e33); --g: light-dark(rgba(0, 0, 0, .1), rgba(255, 255, 255, .12)); }\n"],
            'colour word in a custom property name' => ['Resources/Public/Css/Backend/a.css', ".x { color: var(--pg-teal); }\n"],
            'CSS comment' => ['Resources/Public/Css/Backend/a.css', "/* #2f99a4 is 3.38:1 on white */\n.x { color: var(--y); }\n"],
            'JS comment' => ['Resources/Public/JavaScript/Backend/a.js', "// was #888, 3.31:1 in light\nconst a = 1;\n"],
            'Fluid comment' => ['Resources/Private/Templates/a.html', "<f:comment>Not text-bg-danger: white on red is 4.37:1</f:comment>\n"],
            'core badge and button' => ['Resources/Private/Templates/a.html', "<span class=\"badge badge-success\">x</span><a class=\"btn btn-default\">y</a>\n"],
            'HTML entity and anchor' => ['Resources/Private/Templates/a.html', "<a href=\"#tablePickerCollapse\">&#10003;</a>\n"],
            'exempt block' => ['Resources/Public/JavaScript/Backend/a.js', "// scheme-independent: a light page on purpose\nframe.style.background = '#fff';\nframe.srcdoc = 'body{color:#333}';\n"],
        ];
    }

    #[Test]
    #[DataProvider('accepted')]
    public function acceptsSchemeAwareColour(string $file, string $content): void
    {
        $this->write($file, $content);

        [$exit, $stderr] = $this->check();

        self::assertSame(0, $exit, $stderr);
    }

    #[Test]
    public function anExemptionEndsAtTheNextBlankLine(): void
    {
        $this->write(
            'Resources/Public/JavaScript/Backend/a.js',
            "// scheme-independent: a light page on purpose\nframe.style.background = '#fff';\n\nel.style.color = '#888';\n",
        );

        [$exit, $stderr] = $this->check();

        self::assertSame(1, $exit, $stderr);
        self::assertStringContainsString('a.js:4  colour literal:', $stderr);
        self::assertStringNotContainsString('a.js:2', $stderr);
    }

    private function write(string $file, string $content): void
    {
        file_put_contents($this->root . '/' . $file, $content);
    }

    /**
     * @return array{int, string}
     */
    private function check(): array
    {
        $script = dirname(__DIR__, 2) . '/Build/Scripts/check-backend-colors.php';
        $proc = proc_open([PHP_BINARY, $script, $this->root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('cannot start the check', 1790330001);
        }

        stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stderr];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
