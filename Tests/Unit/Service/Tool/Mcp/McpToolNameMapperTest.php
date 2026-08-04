<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Netresearch\NrLlm\Service\Tool\Mcp\McpToolNameMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpToolNameMapper::class)]
final class McpToolNameMapperTest extends TestCase
{
    private McpToolNameMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new McpToolNameMapper();
    }

    #[Test]
    public function prefixesServerIdentifierAndRemoteName(): void
    {
        self::assertSame(
            'mcp_typo3_get_page_tree',
            $this->mapper->localName('typo3', 'get_page_tree'),
        );
    }

    #[Test]
    public function acceptsHyphensAndDigits(): void
    {
        self::assertSame(
            'mcp_srv2_list-items9',
            $this->mapper->localName('srv2', 'list-items9'),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedNames(): array
    {
        return [
            'dot in remote name'       => ['typo3', 'page.tree'],
            'slash in remote name'     => ['typo3', 'page/tree'],
            'space in remote name'     => ['typo3', 'page tree'],
            'non-ASCII remote name'    => ['typo3', 'seitenbäume'],
            'trailing newline'         => ['typo3', "page_tree\n"],
            'dot in server identifier' => ['ty.po3', 'page_tree'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedNames')]
    public function rejectsNamesOutsideTheAcceptedCharacterSet(string $serverIdentifier, string $remoteName): void
    {
        self::assertNull($this->mapper->localName($serverIdentifier, $remoteName));
    }

    /**
     * The mapper judges the concatenated result, and the bare prefix is a
     * legal tool name, so an empty remote name passes here. Callers that
     * import a catalogue have to reject a nameless remote tool themselves.
     */
    #[Test]
    public function anEmptyRemoteNameYieldsTheBarePrefix(): void
    {
        self::assertSame('mcp_typo3_', $this->mapper->localName('typo3', ''));
    }

    #[Test]
    public function acceptsAResultOfExactlySixtyFourCharacters(): void
    {
        $remoteName = str_repeat('a', 64 - \strlen('mcp_typo3_'));

        $localName = $this->mapper->localName('typo3', $remoteName);

        self::assertNotNull($localName);
        self::assertSame(64, \strlen($localName));
    }

    #[Test]
    public function rejectsAResultExceedingSixtyFourCharacters(): void
    {
        $remoteName = str_repeat('a', 64 - \strlen('mcp_typo3_') + 1);

        self::assertNull($this->mapper->localName('typo3', $remoteName));
    }

    #[Test]
    public function groupIsThePrefixedServerIdentifier(): void
    {
        self::assertSame('mcp_typo3', $this->mapper->group('typo3'));
    }
}
