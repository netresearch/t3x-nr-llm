<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Service\Skill\SkillFrontmatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillFrontmatter::class)]
final class SkillFrontmatterTest extends TestCase
{
    #[Test]
    public function anAbsentAllowedToolsKeyIsNoDeclaration(): void
    {
        self::assertNull(SkillFrontmatter::allowedTools(['name' => 'x']));
    }

    #[Test]
    public function aStringDeclarationIsSplitIntoNames(): void
    {
        self::assertSame(['GetTca', 'GetEnv'], SkillFrontmatter::allowedTools(['allowed-tools' => 'GetTca, GetEnv']));
        self::assertSame(['GetTca', 'GetEnv'], SkillFrontmatter::allowedTools(['allowed_tools' => 'GetTca GetEnv']));
    }

    #[Test]
    public function anEmptyDeclarationIsTheDeclaredEmptyList(): void
    {
        self::assertSame([], SkillFrontmatter::allowedTools(['allowed-tools' => '']));
        self::assertSame([], SkillFrontmatter::allowedTools(['allowed-tools' => null]));
        self::assertSame([], SkillFrontmatter::allowedTools(['allowed-tools' => []]));
    }

    #[Test]
    public function aListDeclarationIsKeptAsAList(): void
    {
        self::assertSame(['GetTca', 'GetEnv'], SkillFrontmatter::allowedTools(['allowed-tools' => ['a' => 'GetTca', 'b' => 'GetEnv']]));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function processMarkers(): iterable
    {
        yield 'YAML true' => [true, true];
        yield 'YAML false' => [false, false];
        yield 'string true' => ['true', true];
        yield 'string yes' => ['yes', true];
        yield 'number 1' => [1, true];
        yield 'string false' => ['false', false];
        yield 'a word' => ['sometimes', false];
        yield 'a list' => [['true'], false];
        yield 'null' => [null, false];
    }

    #[Test]
    #[DataProvider('processMarkers')]
    public function onlyAnExplicitTrueMarksAProcess(mixed $value, bool $expected): void
    {
        self::assertSame($expected, SkillFrontmatter::isProcess(['process' => $value]));
    }

    #[Test]
    public function anAbsentMarkerIsNotAProcess(): void
    {
        self::assertFalse(SkillFrontmatter::isProcess(['name' => 'x']));
    }
}
