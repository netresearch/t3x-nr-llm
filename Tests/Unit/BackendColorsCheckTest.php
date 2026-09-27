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
 * `bg-light` card under light text, an unguarded prefers-color-scheme block,
 * `text-body-secondary` that core does not define. The review of #991 added the
 * shapes the first version let through: named colours in a JavaScript style
 * assignment, colours outside its short list, the newer colour functions,
 * colour-carrying properties beyond `color`/`background`, and icons.
 */
#[CoversNothing]
final class BackendColorsCheckTest extends AbstractUnitTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/nrllm-colors-check-' . bin2hex(random_bytes(6));
        foreach (['Resources/Private/Templates', 'Resources/Public/Css/Backend', 'Resources/Public/JavaScript/Backend', 'Resources/Public/Icons'] as $dir) {
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
            'text-body-secondary' => ['Resources/Private/Templates/a.html', "<p class=\"small text-body-secondary\">x</p>\n", 'scheme-pinned class'],
            'btn-outline' => ['Resources/Private/Templates/a.html', "<a class=\"btn btn-outline-primary\">x</a>\n", 'scheme-pinned class'],
            'alert-light' => ['Resources/Private/Templates/a.html', "<div class=\"alert alert-light\">x</div>\n", 'scheme-pinned class'],
            'named colour in a style assignment' => ['Resources/Public/JavaScript/Backend/a.js', "el.style.backgroundColor = 'white';\n", 'named colour in JavaScript'],
            'named colour in setProperty' => ['Resources/Public/JavaScript/Backend/a.js', "el.style.setProperty('color', 'black');\n", 'named colour in JavaScript'],
            'named colour outside the short list' => ['Resources/Private/Templates/a.html', "<div style=\"background-color: whitesmoke\">x</div>\n", 'named colour'],
            'named colour in box-shadow' => ['Resources/Public/Css/Backend/a.css', ".x { box-shadow: 0 0 2px darkred; }\n", 'named colour'],
            'oklch' => ['Resources/Public/Css/Backend/a.css', ".x { color: oklch(0.2 0 0); }\n", 'colour literal'],
            'color()' => ['Resources/Public/Css/Backend/a.css', ".x { color: color(display-p3 0 0 0); }\n", 'colour literal'],
            'icon fill' => ['Resources/Public/Icons/a.svg', "<svg><path fill=\"#000000\" d=\"M0 0\"/></svg>\n", 'icon paint'],
            'icon stroke in style' => ['Resources/Public/Icons/a.svg', "<svg><path style=\"stroke:black\" d=\"M0 0\"/></svg>\n", 'icon paint'],
            'icon paint in its own style block' => ['Resources/Public/Icons/a.svg', "<svg><style>path{fill:#000}</style></svg>\n", 'icon paint'],
            'icon hex outside a paint property' => ['Resources/Public/Icons/a.svg', "<svg><stop data-c=\"#ff0000\"/></svg>\n", 'colour literal'],
            'named colour in a custom property' => ['Resources/Public/Css/Backend/a.css', ".x { --nrllm-x: black; }\n", 'named colour in a custom property'],
            'named colour in filter' => ['Resources/Public/Css/Backend/a.css', ".x { filter: drop-shadow(0 0 2px black); }\n", 'named colour'],
            'hex in a data URI' => ['Resources/Public/Css/Backend/a.css', ".x { background: url(\"data:image/svg+xml,%3Cpath fill='%23000'/%3E\"); }\n", 'colour literal'],
            'named fill in a data URI' => ['Resources/Public/Css/Backend/a.css', ".x { background: url(\"data:image/svg+xml,%3Cpath fill%3D%27black%27/%3E\"); }\n", 'colour attribute'],
            'fill attribute in inline SVG' => ['Resources/Private/Templates/a.html', "<svg><path fill=\"black\" d=\"M0 0\"/></svg>\n", 'colour attribute'],
            'font colour attribute' => ['Resources/Private/Templates/a.html', "<font color=\"red\">x</font>\n", 'colour attribute'],
            'Chart.js option' => ['Resources/Public/JavaScript/Backend/a.js', "const options = { borderColor: 'black' };\n", 'named colour in JavaScript'],
            'Object.assign on a style' => ['Resources/Public/JavaScript/Backend/a.js', "Object.assign(el.style, { color: 'black' });\n", 'named colour in JavaScript'],
            'style bracket notation' => ['Resources/Public/JavaScript/Backend/a.js', "el.style['color'] = 'black';\n", 'named colour in JavaScript'],
            'canvas fillStyle' => ['Resources/Public/JavaScript/Backend/a.js', "ctx.fillStyle = 'black';\n", 'named colour in JavaScript'],
            'canvas strokeStyle' => ['Resources/Public/JavaScript/Backend/a.js', "ctx.strokeStyle = 'gold';\n", 'named colour in JavaScript'],
            'setAttribute fill' => ['Resources/Public/JavaScript/Backend/a.js', "path.setAttribute('fill', 'black');\n", 'named colour in JavaScript'],
            'setProperty on a custom property' => ['Resources/Public/JavaScript/Backend/a.js', "el.style.setProperty('--nrllm-x', 'black');\n", 'named colour in JavaScript'],
            'array of colours in a dataset' => ['Resources/Public/JavaScript/Backend/a.js', "const ds = { backgroundColor: ['black', 'white'] };\n", 'named colour in JavaScript'],
            'ternary assignment' => ['Resources/Public/JavaScript/Backend/a.js', "el.style.color = ok ? 'green' : 'red';\n", 'named colour in JavaScript'],
            'logical-or default' => ['Resources/Public/JavaScript/Backend/a.js', "el.style.color = c || 'black';\n", 'named colour in JavaScript'],
            'quoted camelCase key' => ['Resources/Public/JavaScript/Backend/a.js', "const o = { \"backgroundColor\": \"black\" };\n", 'named colour in JavaScript'],
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
            'exempt statement over several lines' => ['Resources/Public/JavaScript/Backend/a.js', "// scheme-independent: a light page on purpose\nframe.srcdoc = [\n    'body{color:#333}',\n    'pre{background:#f5f5f5}',\n].join('');\n"],
            'core muted text and alert' => ['Resources/Private/Templates/a.html', "<p class=\"text-variant\">x</p><p class=\"text-muted\">y</p><div class=\"alert alert-warning\">z</div>\n"],
            'icon in currentColor and the accent' => ['Resources/Public/Icons/a.svg', "<svg><path fill=\"currentColor\" d=\"M0 0\"/><path fill=\"var(--nr-icon-accent, #2F99A4)\" stroke=\"none\" d=\"M0 0\"/></svg>\n"],
            'v13 legacy tile' => ['Resources/Public/Icons/a.legacy.svg', "<svg><rect fill=\"#2F99A4\"/></svg>\n"],
            'extension tile' => ['Resources/Public/Icons/Extension.svg', "<svg><rect fill=\"#2F99A4\"/></svg>\n"],
            'not a colour property' => ['Resources/Public/Css/Backend/a.css', ".x { white-space: nowrap; }\n"],
            'custom property referencing a token' => ['Resources/Public/Css/Backend/a.css', ".x { --nrllm-x: var(--pg-teal); }\n"],
            'filter without colour' => ['Resources/Public/Css/Backend/a.css', ".x { filter: blur(2px); }\n"],
            'data attribute named like a colour' => ['Resources/Private/Templates/a.html', "<div data-color=\"red\">x</div>\n"],
            'URL-encoded fragment' => ['Resources/Private/Templates/a.html', "<a href=\"https://example.org/a%23section\">x</a>\n"],
            'Chart.js point style and label' => ['Resources/Public/JavaScript/Backend/a.js', "const o = { pointStyle: 'rectRot', label: 'Red team' };\n"],
            'colour from a resolved token' => ['Resources/Public/JavaScript/Backend/a.js', 'probe.style.color = `var(${name})`;' . "\n"],
            'colour key, then an unrelated label' => ['Resources/Public/JavaScript/Backend/a.js', "const o = { color: this.colors.text, label: 'Red team' };\n"],
            'comparison, not an assignment' => ['Resources/Public/JavaScript/Backend/a.js', "if (el.style.color === 'red') { x(); }\n"],
            'expression without a literal' => ['Resources/Public/JavaScript/Backend/a.js', "el.style.color = isDark ? cfg.dark : cfg.light;\n"],
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
    public function anExemptionCoversExactlyOneStatement(): void
    {
        $this->write(
            'Resources/Public/JavaScript/Backend/a.js',
            "// scheme-independent: a light page on purpose\nframe.style.background = '#fff';\nel.style.color = '#888';\n",
        );

        [$exit, $stderr] = $this->check();

        self::assertSame(1, $exit, $stderr);
        self::assertStringContainsString('a.js:3  colour literal:', $stderr);
        self::assertStringNotContainsString('a.js:2', $stderr);
    }

    #[Test]
    public function anExemptionInACssRuleCoversOneDeclaration(): void
    {
        $this->write(
            'Resources/Public/Css/Backend/a.css',
            ".a {\n    /* scheme-independent: a light page */\n    background: #fff;\n    color: #333;\n}\n",
        );

        [$exit, $stderr] = $this->check();

        self::assertSame(1, $exit, $stderr);
        self::assertStringContainsString('a.css:4  colour literal:', $stderr);
        self::assertStringNotContainsString('a.css:3', $stderr);
    }

    #[Test]
    public function anExemptionAboveACssRuleEndsWithThatRule(): void
    {
        $this->write(
            'Resources/Public/Css/Backend/a.css',
            "/* scheme-independent: a light page */\n.a { background: #fff; }\n.b { color: #333; }\n",
        );

        [$exit, $stderr] = $this->check();

        self::assertSame(1, $exit, $stderr);
        self::assertStringContainsString('a.css:3  colour literal:', $stderr);
        self::assertStringNotContainsString('a.css:2', $stderr);
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
