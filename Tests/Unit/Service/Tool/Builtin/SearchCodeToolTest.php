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
        self::assertSame(['pattern'], $spec->parameters['required'] ?? null);
        self::assertTrue($this->tool->requiresAdmin());
        self::assertSame('code', $this->tool->getGroup());
    }

    #[Test]
    public function findsLiteralHitsOnlyInAllowedSourceFiles(): void
    {
        $output = $this->tool->execute(['pattern' => 'findMeHere']);

        self::assertStringContainsString('Classes/Alpha.php:2:', $output);
        self::assertStringContainsString('Resources/notes.md:1:', $output);
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
        self::assertStringContainsString(
            'Denied or not found',
            $this->tool->execute(['pattern' => 'x', 'path' => '../']),
        );
    }

    #[Test]
    public function reportsNoMatches(): void
    {
        self::assertStringContainsString('No matches', $this->tool->execute(['pattern' => 'zzz-not-there']));
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->root, true);
        parent::tearDown();
    }
}
