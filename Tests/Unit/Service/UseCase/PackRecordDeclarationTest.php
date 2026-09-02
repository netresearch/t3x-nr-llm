<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\UseCase;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\UseCase\PackSnippet;
use Netresearch\NrLlm\Service\UseCase\PackTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The declared record shapes are checked at declaration time because the
 * installer writes through Extbase rather than FormEngine: an overlong or
 * malformed value would reach a strict-mode DBMS unvalidated.
 *
 * The refusal tests assign the constructed object and name it in the
 * `self::fail()` message. The line is unreachable while the constructor throws,
 * which is the point — and it keeps the instantiation from reading as a value
 * created only to be dropped.
 */
#[CoversClass(PackTask::class)]
#[CoversClass(PackSnippet::class)]
final class PackRecordDeclarationTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function invalidTaskIdentifiers(): array
    {
        return [
            'dotted (the configuration convention, not the task one)' => ['editorial.starter', 1791460001],
            'uppercase' => ['Editorial', 1791460001],
            'empty' => ['', 1791460001],
            'too long' => [str_repeat('a', 101), 1791460002],
        ];
    }

    #[Test]
    #[DataProvider('invalidTaskIdentifiers')]
    public function anInvalidTaskIdentifierIsRefused(string $identifier, int $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode($code);

        $task = new PackTask($identifier, 'Name', '', 'Prompt {{input}}');
        self::fail('Expected the identifier to be refused, got ' . $task->identifier);
    }

    #[Test]
    public function anEmptyTaskNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460003);

        $task = new PackTask('task', '', '', 'Prompt {{input}}');
        self::fail('Expected the empty name to be refused, got ' . $task->name);
    }

    #[Test]
    public function anEmptyTaskPromptIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460004);

        $task = new PackTask('task', 'Name', '', "  \n ");
        self::fail('Expected the empty prompt to be refused, got ' . $task->promptTemplate);
    }

    #[Test]
    public function aValidTaskKeepsItsDeclaredValues(): void
    {
        $task = new PackTask('proofread', 'Proofread', 'Corrections only', 'Check {{input}}');

        self::assertSame('proofread', $task->identifier);
        self::assertSame('Proofread', $task->name);
        self::assertSame('Check {{input}}', $task->promptTemplate);
    }

    #[Test]
    public function anInvalidSnippetIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460011);

        $snippet = new PackSnippet('House Style', 'House style', '', 'Write plainly.');
        self::fail('Expected the identifier to be refused, got ' . $snippet->identifier);
    }

    #[Test]
    public function anEmptySnippetBodyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460014);

        $snippet = new PackSnippet('house-style', 'House style', '', '   ');
        self::fail('Expected the empty body to be refused, got ' . $snippet->snippet);
    }

    #[Test]
    public function overlongSnippetTagsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460015);

        $snippet = new PackSnippet('house-style', 'House style', '', 'Write plainly.', [str_repeat('t', 256)]);
        self::fail('Expected the overlong tags to be refused, got ' . $snippet->tagList());
    }

    #[Test]
    public function tagsAreStoredAsTheCommaSeparatedFormTheColumnHolds(): void
    {
        $snippet = new PackSnippet(
            'house-style',
            'House style',
            '',
            'Write plainly.',
            ['tone_of_voice', 'audience'],
            ToolDataClass::PUBLIC_CONTENT,
        );

        self::assertSame('tone_of_voice,audience', $snippet->tagList());
        self::assertSame(ToolDataClass::PUBLIC_CONTENT, $snippet->dataClass);
    }

    #[Test]
    public function aSnippetDataClassIsOptionalAndDefaultsToUndeclared(): void
    {
        // ADR-144: an undeclared snippet cannot block a call. A pack must not
        // decide a governance question the operator never answered.
        $snippet = new PackSnippet('house-style', 'House style', '', 'Write plainly.');

        self::assertNull($snippet->dataClass);
    }

    #[Test]
    public function declaredMetadataBecomesTheJsonObjectTheColumnHolds(): void
    {
        $snippet = new PackSnippet(
            'anna',
            'Anna',
            '',
            'A curious host who asks the obvious question.',
            ['persona'],
            metadata: ['voice' => 'nova'],
        );

        self::assertSame('{"voice":"nova"}', $snippet->metadataJson());
    }

    #[Test]
    public function undeclaredMetadataIsStoredAsTheEmptyStringRatherThanAnEmptyObject(): void
    {
        // '' is what a hand-created record carries, and PromptSnippet reads ''
        // and '{}' the same way — so the installed record stays byte-identical
        // to one an editor would have written.
        $snippet = new PackSnippet('house-style', 'House style', '', 'Write plainly.');

        self::assertSame([], $snippet->metadata);
        self::assertSame('', $snippet->metadataJson());
    }

    #[Test]
    public function metadataThatCannotBeJsonEncodedIsRefusedAtDeclarationTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460016);

        // NAN has no JSON representation. Refusing here puts the failure in the
        // registry constructor, where the pack's author is, instead of on the
        // operator's install screen.
        $snippet = new PackSnippet('anna', 'Anna', '', 'A curious host.', metadata: ['voice' => NAN]);
        self::fail('Expected the unencodable metadata to be refused, got ' . $snippet->metadataJson());
    }

    #[Test]
    public function aSnippetIsComposedByConfigurationUnlessTheDeclarationSaysOtherwise(): void
    {
        // ADR-186: the default is the ADR-031 behaviour every pre-existing pack
        // relies on. Only a pack whose extension resolves the snippet by uid
        // opts out.
        $composed = new PackSnippet('house-style', 'House style', '', 'Write plainly.');
        $ownRead  = new PackSnippet('anna', 'Anna', '', 'A curious host.', composedByConfiguration: false);

        self::assertTrue($composed->composedByConfiguration);
        self::assertFalse($ownRead->composedByConfiguration);
    }
}
