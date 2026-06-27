<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Service\Skill\Exception\SkillParseException;
use Netresearch\NrLlm\Service\Skill\MarketplaceParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarketplaceParser::class)]
final class MarketplaceParserTest extends TestCase
{
    private MarketplaceParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarketplaceParser();
    }

    #[Test]
    public function parsesStringAndObjectSources(): void
    {
        $json = '{"name":"m","plugins":[{"name":"a","source":"acme/repo-a"},{"name":"b","source":{"source":"github","repo":"acme/repo-b"}}]}';
        $entries = $this->parser->parse($json);
        self::assertCount(2, $entries);
        self::assertSame('acme', $entries[0]->owner);
        self::assertSame('repo-a', $entries[0]->repo);
        self::assertSame('repo-b', $entries[1]->repo);
    }

    #[Test]
    public function ignoresUnknownKeysAndUnresolvableEntries(): void
    {
        $json = '{"plugins":[{"name":"a","source":"acme/repo-a","extra":true},{"name":"bad"}]}';
        $entries = $this->parser->parse($json);
        self::assertCount(1, $entries);
    }

    #[Test]
    public function throwsOnInvalidJson(): void
    {
        $this->expectException(SkillParseException::class);
        $this->parser->parse('{not json');
    }

    #[Test]
    public function throwsWhenPluginsMissing(): void
    {
        $this->expectException(SkillParseException::class);
        $this->parser->parse('{"name":"m"}');
    }
}
