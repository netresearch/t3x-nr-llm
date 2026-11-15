<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use JsonException;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcError;

/**
 * The input contract of the page metadata skill, version 1 (ADR-296): the
 * whole text of the client's message is one JSON object.
 *
 *     {"page": 42, "language": 1, "fields": ["description", "seo_title"]}
 *
 * with exactly these three keys. `page` is the uid of a default-language
 * page, `language` a language uid (0 is the default language), `fields` a
 * non-empty list of distinct names from {@see self::FIELDS}. Numbers are JSON
 * integers, never strings. Any other key, a missing key or a wrong type is
 * refused before a task exists: nothing the client writes reaches the prompt
 * except these checked values.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPageMetadataRequest
{
    public const VERSION = 1;

    /**
     * The fields the skill may be asked for: the text fields
     * `update_page_metadata` writes, in its order.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'title',
        'subtitle',
        'nav_title',
        'abstract',
        'description',
        'keywords',
        'seo_title',
        'og_title',
        'og_description',
        'twitter_title',
        'twitter_description',
        'twitter_card',
    ];

    /** The longest text the contract reads; a larger one is not a request of this skill. */
    public const MAX_TEXT_BYTES = 2048;

    private const KEYS = ['page', 'language', 'fields'];

    private const SHAPE = 'the text must be one JSON object with "page", "language" and "fields"';

    /** The field of the request the refusals name. */
    private const FIELD = 'message.parts';

    /**
     * @param list<string> $fields
     */
    private function __construct(
        public int $page,
        public int $language,
        public array $fields,
    ) {}

    /**
     * The request the text states, or the refusal that says what is wrong.
     * The refusal names keys and rules only, never a value the client sent.
     *
     * @throws A2aRpcError
     */
    public static function fromText(string $text): self
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > self::MAX_TEXT_BYTES) {
            throw self::refusal(self::SHAPE);
        }

        try {
            $data = json_decode($text, true, 4, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw self::refusal(self::SHAPE);
        }

        if (!is_array($data) || array_is_list($data)) {
            throw self::refusal(self::SHAPE);
        }

        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw self::refusal('only the keys "page", "language" and "fields" are allowed');
            }
        }

        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                throw self::refusal(sprintf('"%s" is missing', $key));
            }
        }

        $page = $data['page'];
        if (!is_int($page) || $page < 1) {
            throw self::refusal('"page" must be a positive integer');
        }

        $language = $data['language'];
        if (!is_int($language) || $language < 0) {
            throw self::refusal('"language" must be an integer of 0 or more');
        }

        return new self($page, $language, self::fields($data['fields']));
    }

    /**
     * @return list<string>
     *
     * @throws A2aRpcError
     */
    private static function fields(mixed $fields): array
    {
        if (!is_array($fields) || !array_is_list($fields) || $fields === []) {
            throw self::refusal('"fields" must be a non-empty list of field names');
        }

        $names = [];
        foreach ($fields as $field) {
            if (!is_string($field) || !in_array($field, self::FIELDS, true)) {
                throw self::refusal('"fields" may name only: ' . implode(', ', self::FIELDS));
            }

            if (in_array($field, $names, true)) {
                throw self::refusal('"fields" names a field twice');
            }

            $names[] = $field;
        }

        return $names;
    }

    private static function refusal(string $description): A2aRpcError
    {
        return A2aRpcError::invalidParams(self::FIELD, $description);
    }
}
