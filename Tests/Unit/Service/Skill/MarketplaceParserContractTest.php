<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Service\Skill\Exception\SkillParseException;
use Netresearch\NrLlm\Service\Skill\MarketplaceParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(MarketplaceParser::class)]
final class MarketplaceParserContractTest extends TestCase
{
    #[Test]
    #[DataProvider('unsupportedPlugins')]
    public function skipsUnsupportedPluginAndStillProcessesTheNextEntry(
        mixed $unsupported,
    ): void {
        $entries = null;
        $caught = null;
        $json = json_encode(
            [
                'plugins' => [
                    $unsupported,
                    ['source' => 'sentinel/repository', 'ref' => 'release/v2'],
                ],
            ],
            JSON_THROW_ON_ERROR,
        );

        try {
            $entries = (new MarketplaceParser())->parse($json);
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertNull(
            $caught,
            'An unsupported plugin must not abort the remaining catalogue.',
        );
        self::assertNotNull($entries);
        self::assertCount(1, $entries);
        self::assertSame('sentinel', $entries[0]->owner);
        self::assertSame('repository', $entries[0]->repo);
        self::assertSame('release/v2', $entries[0]->ref);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unsupportedPlugins(): iterable
    {
        yield 'null entry' => [null];
        yield 'boolean entry' => [false];
        yield 'number entry' => [42];
        yield 'string entry' => ['unrecognized'];
        yield 'empty entry' => [[]];
        yield 'missing source' => [['name' => 'unrecognized']];
        yield 'null source' => [['source' => null]];
        yield 'boolean source' => [['source' => false]];
        yield 'object without repository' => [['source' => ['source' => 'git']]];
        yield 'non-string repository' => [['source' => ['repo' => 42]]];
        yield 'non-string url' => [['source' => ['url' => ['github.com']]]];
        yield 'slug without separator' => [['source' => 'repository']];
        yield 'empty owner' => [['source' => '/repository']];
        yield 'empty repository' => [['source' => 'owner/']];
        yield 'external git host' => [['source' => ['url' => 'https://gitlab.com/owner/repository.git']]];
        yield 'host suffix' => [['source' => ['url' => 'https://github.com.example/owner/repository']]];
        yield 'relative url' => [['source' => ['url' => '/owner/repository']]];
        yield 'invalid port' => [['source' => ['url' => 'https://github.com:99999/owner/repository']]];
        yield 'missing path' => [['source' => ['url' => 'https://github.com']]];
        yield 'owner-only path' => [['source' => ['url' => 'https://github.com/owner']]];
        yield 'empty repository after git suffix' => [['source' => ['url' => 'https://github.com/owner/.git']]];
    }

    /**
     * @param array<string, mixed> $plugin
     */
    #[Test]
    #[DataProvider('references')]
    public function preservesStringReferencesAndLeavesOtherReferencesUnspecified(
        array $plugin,
        ?string $expected,
    ): void {
        $entries = (new MarketplaceParser())->parse(
            json_encode(
                ['plugins' => [$plugin + ['source' => 'owner/repository']]],
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertCount(1, $entries);
        self::assertSame('owner', $entries[0]->owner);
        self::assertSame('repository', $entries[0]->repo);
        self::assertSame($expected, $entries[0]->ref);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string}>
     */
    public static function references(): iterable
    {
        yield 'branch' => [['ref' => 'release/v2'], 'release/v2'];
        yield 'tag' => [['ref' => 'v2.3.4'], 'v2.3.4'];
        yield 'commit' => [
            ['ref' => '0123456789abcdef0123456789abcdef01234567'],
            '0123456789abcdef0123456789abcdef01234567',
        ];
        yield 'empty string' => [['ref' => ''], ''];
        yield 'absent' => [[], null];
        yield 'null' => [['ref' => null], null];
        yield 'number' => [['ref' => 42], null];
        yield 'boolean' => [['ref' => false], null];
        yield 'array' => [['ref' => ['main']], null];
    }

    #[Test]
    #[DataProvider('supportedSources')]
    public function resolvesSupportedSourcesWithTheirExactRepositoryIdentity(
        mixed $source,
        string $owner,
        string $repository,
    ): void {
        $entries = (new MarketplaceParser())->parse(
            json_encode(
                ['plugins' => [['source' => $source, 'ref' => 'main']]],
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertCount(1, $entries);
        self::assertSame($owner, $entries[0]->owner);
        self::assertSame($repository, $entries[0]->repo);
        self::assertSame('main', $entries[0]->ref);
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function supportedSources(): iterable
    {
        yield 'slug' => ['Owner/repository', 'Owner', 'repository'];
        yield 'repository object' => [['repo' => 'Owner/repository'], 'Owner', 'repository'];
        yield 'repository object takes precedence' => [
            [
                'repo' => 'Owner/repository',
                'url' => 'https://gitlab.com/other/ignored',
            ],
            'Owner',
            'repository',
        ];
        yield 'git url' => [
            [
                'source' => 'git',
                'url' => 'https://github.com/Owner/repository.git',
            ],
            'Owner',
            'repository',
        ];
        yield 'uppercase host' => [
            ['url' => 'https://GITHUB.COM/Owner/repository.git'],
            'Owner',
            'repository',
        ];
        yield 'mixed-case www host' => [
            ['url' => 'https://WwW.GitHub.Com/Owner/repository'],
            'Owner',
            'repository',
        ];
        yield 'github url with further path' => [
            ['url' => 'https://github.com/Owner/repository/tree/main'],
            'Owner',
            'repository',
        ];
    }

    #[Test]
    #[DataProvider('invalidCatalogues')]
    public function rejectsAnInvalidCatalogueWithItsExactDiagnostic(
        string $json,
        string $reason,
    ): void {
        $caught = null;

        try {
            (new MarketplaceParser())->parse($json);
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(SkillParseException::class, $caught);
        self::assertSame(
            'Cannot parse skill "marketplace.json": ' . $reason,
            $caught->getMessage(),
        );
        self::assertSame(1719500000, $caught->getCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCatalogues(): iterable
    {
        yield 'invalid syntax' => ['{not json', 'invalid JSON'];
        yield 'null JSON' => ['null', 'invalid JSON'];
        yield 'boolean JSON' => ['false', 'invalid JSON'];
        yield 'numeric JSON' => ['42', 'invalid JSON'];
        yield 'string JSON' => ['"catalogue"', 'invalid JSON'];
        yield 'empty object' => ['{}', 'missing "plugins" array'];
        yield 'empty list' => ['[]', 'missing "plugins" array'];
        yield 'null plugins' => ['{"plugins":null}', 'missing "plugins" array'];
        yield 'scalar plugins' => ['{"plugins":42}', 'missing "plugins" array'];
    }

    #[Test]
    public function acceptsAnEmptyPluginCatalogue(): void
    {
        self::assertSame([], (new MarketplaceParser())->parse('{"plugins":[]}'));
    }
}
