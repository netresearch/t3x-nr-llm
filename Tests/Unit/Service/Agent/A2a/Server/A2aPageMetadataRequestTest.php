<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcError;
use Netresearch\NrLlm\Service\Agent\A2a\Server\Handler\A2aPageMetadataRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The input contract of the page metadata skill, version 1 (ADR-296, spec 055
 * PM1): one JSON object, three keys, strict types.
 */
#[CoversClass(A2aPageMetadataRequest::class)]
final class A2aPageMetadataRequestTest extends TestCase
{
    #[Test]
    public function aWellFormedRequestIsRead(): void
    {
        $request = A2aPageMetadataRequest::fromText("  {\"page\": 42, \"language\": 0, \"fields\": [\"description\", \"twitter_card\"]}\n");

        self::assertSame([42, 0, ['description', 'twitter_card']], [$request->page, $request->language, $request->fields]);
    }

    #[Test]
    public function everyFieldOfTheToolIsAccepted(): void
    {
        $request = A2aPageMetadataRequest::fromText(json_encode(['page' => 1, 'language' => 2, 'fields' => A2aPageMetadataRequest::FIELDS], JSON_THROW_ON_ERROR));

        self::assertSame(A2aPageMetadataRequest::FIELDS, $request->fields);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedTexts(): iterable
    {
        $shape = 'the text must be one JSON object with "page", "language" and "fields"';
        $only  = '"fields" may name only: ' . implode(', ', A2aPageMetadataRequest::FIELDS);

        yield 'empty' => ['', $shape];
        yield 'prose' => ['Update page 42.', $shape];
        yield 'a list' => ['[42, 1, ["description"]]', $shape];
        yield 'a number' => ['42', $shape];
        yield 'too long' => ['{"page": 1, "language": 0, "fields": ["title"], "x": "' . str_repeat('a', A2aPageMetadataRequest::MAX_TEXT_BYTES) . '"}', $shape];
        yield 'too deep' => ['{"page": 1, "language": 0, "fields": [[[["title"]]]]}', $shape];
        yield 'an unknown key' => ['{"page": 1, "language": 0, "fields": ["title"], "admin": true}', 'only the keys "page", "language" and "fields" are allowed'];
        yield 'no language' => ['{"page": 1, "fields": ["title"]}', '"language" is missing'];
        yield 'no fields' => ['{"page": 1, "language": 0}', '"fields" is missing'];
        yield 'a page as string' => ['{"page": "1", "language": 0, "fields": ["title"]}', '"page" must be a positive integer'];
        yield 'a page as float' => ['{"page": 1.0, "language": 0, "fields": ["title"]}', '"page" must be a positive integer'];
        yield 'page zero' => ['{"page": 0, "language": 0, "fields": ["title"]}', '"page" must be a positive integer'];
        yield 'a huge page' => ['{"page": 99999999999999999999999, "language": 0, "fields": ["title"]}', '"page" must be a positive integer'];
        yield 'a negative language' => ['{"page": 1, "language": -1, "fields": ["title"]}', '"language" must be an integer of 0 or more'];
        yield 'a language as null' => ['{"page": 1, "language": null, "fields": ["title"]}', '"language" must be an integer of 0 or more'];
        yield 'empty fields' => ['{"page": 1, "language": 0, "fields": []}', '"fields" must be a non-empty list of field names'];
        yield 'fields as object' => ['{"page": 1, "language": 0, "fields": {"a": "title"}}', '"fields" must be a non-empty list of field names'];
        yield 'fields as object with list keys' => ['{"page": 1, "language": 0, "fields": {"0": "title"}}', '"fields" must be a non-empty list of field names'];
        yield 'too long before trimming' => [str_repeat(' ', A2aPageMetadataRequest::MAX_TEXT_BYTES) . '{"page": 1, "language": 0, "fields": ["title"]}', $shape];
        yield 'a field outside the list' => ['{"page": 1, "language": 0, "fields": ["slug"]}', $only];
        yield 'a field of another case' => ['{"page": 1, "language": 0, "fields": ["Title"]}', $only];
        yield 'a field twice' => ['{"page": 1, "language": 0, "fields": ["title", "title"]}', '"fields" names a field twice'];
    }

    #[Test]
    #[DataProvider('refusedTexts')]
    public function aTextOutsideTheContractIsRefusedWithItsRule(string $text, string $description): void
    {
        try {
            A2aPageMetadataRequest::fromText($text);
            self::fail('Expected a refusal');
        } catch (A2aRpcError $error) {
            self::assertSame(-32602, $error->rpcCode);
            self::assertSame([['field' => 'message.parts', 'description' => $description]], $error->violations);
        }
    }

    #[Test]
    public function aRefusalNeverRepeatsAValueTheClientSent(): void
    {
        try {
            A2aPageMetadataRequest::fromText('{"page": 1, "language": 0, "fields": ["<script>secret</script>"]}');
            self::fail('Expected a refusal');
        } catch (A2aRpcError $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
    }
}
