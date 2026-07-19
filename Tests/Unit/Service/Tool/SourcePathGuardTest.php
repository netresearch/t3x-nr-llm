<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\SourcePathGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Load-bearing security tests for the path gate (ADR-044): traversal,
 * symlink escape, dotfiles, settings.php, key material and var/* must all
 * fail closed; var/log and ordinary source files pass; secret assignment
 * lines are value-redacted.
 */
#[CoversClass(SourcePathGuard::class)]
final class SourcePathGuardTest extends TestCase
{
    private string $root;

    private string $outside;

    private SourcePathGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/nrllm-guard-' . bin2hex(random_bytes(4));
        $this->root    = $base . '/project';
        $this->outside = $base . '/outside';

        foreach (
            [
                $this->root . '/Classes',
                $this->root . '/config/system',
                $this->root . '/var/log',
                $this->root . '/var/session',
                $this->root . '/.ddev',
                $this->outside,
            ] as $dir
        ) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($this->root . '/Classes/Service.php', "<?php\n\$a = 1;\n\$apiKey = 'super-secret';\n\$b = 2;\n");
        file_put_contents($this->root . '/config/system/settings.php', "<?php return ['db' => 'secret'];");
        file_put_contents($this->root . '/config/additional.php', '<?php // creds');
        file_put_contents($this->root . '/var/log/typo3_abc.log', 'log line');
        file_put_contents($this->root . '/var/session/sess.dat', 'session');
        file_put_contents($this->root . '/.ddev/config.yaml', 'name: x');
        file_put_contents($this->root . '/.env', 'TOKEN=x');
        file_put_contents($this->root . '/server.key', 'PRIVATE KEY');
        file_put_contents($this->outside . '/secret.php', '<?php // outside');
        symlink($this->outside . '/secret.php', $this->root . '/Classes/escape.php');

        $this->guard = new SourcePathGuard($this->root);
    }

    #[Test]
    public function resolvesAnOrdinarySourceFile(): void
    {
        self::assertNotNull($this->guard->resolve('Classes/Service.php'));
        self::assertNotNull($this->guard->resolve('var/log/typo3_abc.log'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deniedPaths(): array
    {
        return [
            'traversal out of root'  => ['Classes/../../outside/secret.php'],
            'dotfile'                => ['.env'],
            'dot directory'          => ['.ddev/config.yaml'],
            'settings.php'           => ['config/system/settings.php'],
            'additional.php'         => ['config/additional.php'],
            'key material'           => ['server.key'],
            'var outside log'        => ['var/session/sess.dat'],
            'symlink escaping root'  => ['Classes/escape.php'],
            'missing file'           => ['Classes/Nope.php'],
            'empty path'             => [''],
        ];
    }

    #[Test]
    #[DataProvider('deniedPaths')]
    public function deniesPath(string $path): void
    {
        self::assertNull($this->guard->resolve($path));
    }

    #[Test]
    public function deniesAbsolutePathOutsideRoot(): void
    {
        self::assertNull($this->guard->resolve($this->outside . '/secret.php'));
    }

    #[Test]
    public function deniesComposerAuthJson(): void
    {
        // Composer auth.json holds registry/OAuth deploy tokens — read_source must
        // never surface it, at the root or nested.
        self::assertTrue($this->guard->isDeniedRelativePath('auth.json'));
        self::assertTrue($this->guard->isDeniedRelativePath('some/dir/auth.json'));
        // A non-credential JSON of a similar name is still readable.
        self::assertFalse($this->guard->isDeniedRelativePath('Configuration/author.json'));
    }

    #[Test]
    public function deniesCredentialMentioningPath(): void
    {
        self::assertTrue($this->guard->isDeniedRelativePath('Classes/CredentialStore.php'));
    }

    #[Test]
    public function readLinesIsRangedNumberedAndSecretRedacted(): void
    {
        $read = $this->guard->readLines('Classes/Service.php', 2, 2);

        self::assertNotNull($read);
        self::assertSame(5, $read['total']);
        self::assertSame([2, 3], array_keys($read['lines']));
        self::assertSame('$a = 1;', $read['lines'][2]);
        self::assertStringContainsString('[redacted]', $read['lines'][3]);
        self::assertStringNotContainsString('super-secret', $read['lines'][3]);
    }

    #[Test]
    public function readLinesDeniedPathIsNull(): void
    {
        self::assertNull($this->guard->readLines('config/system/settings.php', 1, 5));
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir(dirname($this->root), true);
        parent::tearDown();
    }
}
