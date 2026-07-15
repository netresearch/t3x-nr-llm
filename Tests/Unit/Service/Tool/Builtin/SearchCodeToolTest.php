<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\SearchCodeTool;
use Netresearch\NrLlm\Service\Tool\SourcePathGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(SearchCodeTool::class)]
final class SearchCodeToolTest extends TestCase
{
    private string $root;

    private SearchCodeTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/nrllm-searchcode-' . bin2hex(random_bytes(4));
        foreach (['Classes', 'vendor/acme', '.git', 'var/log', 'Resources'] as $dir) {
            mkdir($this->root . '/' . $dir, 0o777, true);
        }
        file_put_contents($this->root . '/Classes/Alpha.php', "<?php\nfunction findMeHere() {}\n\$apiKey = 'topsecret-findMeHere';\n");
        file_put_contents($this->root . '/Resources/notes.md', "findMeHere in docs\n");
        file_put_contents($this->root . '/Classes/binary.png', 'findMeHere-in-binary');
        file_put_contents($this->root . '/vendor/acme/Lib.php', "<?php // findMeHere in vendor\n");
        file_put_contents($this->root . '/.git/config', 'findMeHere in git');
        file_put_contents($this->root . '/var/log/typo3_x.log', 'findMeHere in log');

        $this->tool = new SearchCodeTool(new SourcePathGuard($this->root));
    }

    #[Test]
    public function specShape(): void
    {
        $spec = $this->tool->getSpec();

        self::assertSame('search_code', $spec->name);
        self::assertSame(
            'Search the project source files for a literal substring (or a regular expression with '
            . 'regex=true). Returns path:line hits. Vendor, var and dot directories are skipped.',
            $spec->description,
        );
        // Pin the whole JSON-Schema parameter block so any dropped item/type is caught.
        self::assertSame(
            [
                'type'       => 'object',
                'properties' => [
                    'pattern' => [
                        'type'        => 'string',
                        'description' => 'Literal substring to find (case-sensitive). With regex=true: a PCRE pattern body without delimiters.',
                    ],
                    'regex' => [
                        'type'        => 'boolean',
                        'description' => 'Treat pattern as a regular expression (default false = literal substring).',
                    ],
                    'path' => [
                        'type'        => 'string',
                        'description' => 'Optional subdirectory (relative to the project root) to restrict the search to.',
                    ],
                    'max_results' => [
                        'type'        => 'integer',
                        'description' => 'Maximum hits to return (default 30, cap 100).',
                    ],
                ],
                'required' => ['pattern'],
            ],
            $spec->parameters,
        );
        self::assertSame(['pattern'], $spec->parameters['required']);
        self::assertTrue($this->tool->requiresAdmin());
        self::assertSame('code', $this->tool->getGroup());
    }

    #[Test]
    public function findsLiteralHitsOnlyInAllowedSourceFiles(): void
    {
        $output = $this->tool->execute(['pattern' => 'findMeHere']);

        self::assertStringContainsString('Classes/Alpha.php:2:', $output);
        // Relative path is root-stripped with no leading slash and not the full/offset pathname:
        // each hit line begins right after a newline with "Classes/…".
        self::assertStringContainsString("\nClasses/Alpha.php:2:", $output);
        self::assertStringContainsString('Resources/notes.md:1:', $output);
        // Short hit lines are never length-truncated, so no ellipsis appears.
        self::assertStringNotContainsString('…', $output);
        // Skipped: vendor/, dot dirs, var/, non-source extensions.
        self::assertStringNotContainsString('vendor/acme', $output);
        self::assertStringNotContainsString('.git', $output);
        self::assertStringNotContainsString('var/log', $output);
        self::assertStringNotContainsString('binary.png', $output);
        // Secret assignment lines are value-redacted in the output.
        self::assertStringContainsString('[redacted]', $output);
        self::assertStringNotContainsString('topsecret', $output);
    }

    #[Test]
    public function regexModeValidatesThePattern(): void
    {
        self::assertStringContainsString(
            'invalid regular expression',
            $this->tool->execute(['pattern' => '([unclosed', 'regex' => true]),
        );
        self::assertStringContainsString(
            'Classes/Alpha.php:2:',
            $this->tool->execute(['pattern' => 'findMe\w+\(\)', 'regex' => true]),
        );
    }

    #[Test]
    public function capsResults(): void
    {
        $output = $this->tool->execute(['pattern' => 'findMeHere', 'max_results' => 1]);

        self::assertStringContainsString('1 match(es)', $output);
        self::assertStringContainsString('(capped)', $output);
    }

    #[Test]
    public function deniesEscapingSearchPath(): void
    {
        // The reported subPath is trimmed of surrounding slashes ("../" -> ".."),
        // so pin the exact denial message.
        self::assertSame(
            'Denied or not found: search path "..".',
            $this->tool->execute(['pattern' => 'x', 'path' => '../']),
        );
    }

    #[Test]
    public function reportsNoMatches(): void
    {
        self::assertStringContainsString('No matches', $this->tool->execute(['pattern' => 'zzz-not-there']));
    }

    #[Test]
    public function defaultsToLiteralNotRegexWhenFlagAbsent(): void
    {
        // "findMe." is absent as a literal but, as a regex, matches "findMeH".
        // Without regex=true the tool must treat it literally -> no match.
        self::assertStringContainsString('No matches', $this->tool->execute(['pattern' => 'findMe.']));
    }

    #[Test]
    public function regexEscapesTildeDelimiterInPattern(): void
    {
        file_put_contents($this->root . '/Classes/Tilde.php', "<?php\n// a~b marker\n");

        // "~" is the delimiter; it must be escaped in the pattern body, otherwise
        // "~a~b~" reads "b" as a modifier and the pattern is rejected as invalid.
        $output = $this->tool->execute(['pattern' => 'a~b', 'regex' => true]);

        self::assertStringContainsString('Classes/Tilde.php:2:', $output);
        self::assertStringNotContainsString('invalid regular expression', $output);
    }

    #[Test]
    public function regexScanDoesNotSkipFilesContainingThePatternLiterally(): void
    {
        // In regex mode the literal str_contains() pre-filter must NOT run;
        // a file whose bytes contain the raw pattern still has to be scanned.
        $output = $this->tool->execute(['pattern' => 'findMeHere', 'regex' => true]);

        self::assertStringContainsString('Classes/Alpha.php:2:', $output);
    }

    #[Test]
    public function searchesWithinValidSubPath(): void
    {
        // A valid subdirectory resolves under the root and scopes the walk to it.
        $output = $this->tool->execute(['pattern' => 'findMeHere', 'path' => 'Classes']);

        self::assertStringContainsString('Classes/Alpha.php:2:', $output);
        self::assertStringNotContainsString('Denied', $output);
        self::assertStringNotContainsString('Resources/notes.md', $output);
    }

    #[Test]
    public function searchesWholeRootWithDotPath(): void
    {
        // path="." resolves to the root itself; containment must accept root == candidate.
        $output = $this->tool->execute(['pattern' => 'findMeHere', 'path' => '.']);

        self::assertStringContainsString('Classes/Alpha.php:2:', $output);
        self::assertStringContainsString('Resources/notes.md:1:', $output);
    }

    #[Test]
    public function deniesSubPathPointingAtAFile(): void
    {
        // A file (not a directory) must be rejected by the is_dir() guard.
        self::assertStringContainsString(
            'Denied or not found',
            $this->tool->execute(['pattern' => 'findMeHere', 'path' => 'Classes/Alpha.php']),
        );
    }

    #[Test]
    public function deniesSiblingDirectorySharingRootPrefix(): void
    {
        // A sibling whose name is the root name plus a suffix shares the root's
        // string prefix; the trailing "/" in the containment needle must reject it.
        $sibling = $this->root . 'x';
        mkdir($sibling . '/Classes', 0o777, true);
        file_put_contents($sibling . '/Classes/Sib.php', "<?php // findMeHere\n");

        try {
            $output = $this->tool->execute([
                'pattern' => 'findMeHere',
                'path'    => '../' . basename($this->root) . 'x',
            ]);

            self::assertStringContainsString('Denied or not found', $output);
            self::assertStringNotContainsString('Sib.php', $output);
        } finally {
            GeneralUtility::rmdir($sibling, true);
        }
    }

    #[Test]
    public function trimsSurroundingWhitespaceFromMatchedLine(): void
    {
        file_put_contents($this->root . '/Classes/Indent.php', "<?php\n    findMeHere indented\n");

        $output = $this->tool->execute(['pattern' => 'findMeHere']);

        // The leading indentation is trimmed: exactly ": findMeHere indented" follows the line number.
        self::assertStringContainsString('Classes/Indent.php:2: findMeHere indented', $output);
    }

    #[Test]
    public function respectsMultibyteAwareLineLengthBoundary(): void
    {
        // Exactly MAX_LINE_LENGTH (200) chars: kept verbatim, no ellipsis (> not >=, not <=).
        $exact = 'findMeHere' . str_repeat('a', 190);
        // 160 characters but 310 bytes: mb_strlen (not strlen) keeps it below the cap.
        $multibyte = 'findMeHere' . str_repeat('é', 150);
        file_put_contents($this->root . '/Classes/Exact.php', $exact . "\n");
        file_put_contents($this->root . '/Classes/Multibyte.php', $multibyte . "\n");

        $output = $this->tool->execute(['pattern' => 'findMeHere']);

        self::assertStringContainsString('Classes/Exact.php:1: ' . $exact, $output);
        self::assertStringNotContainsString($exact . '…', $output);
        self::assertStringNotContainsString($multibyte . '…', $output);
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->root, true);
        parent::tearDown();
    }
}
