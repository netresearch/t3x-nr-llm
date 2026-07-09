<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

/**
 * A parsed `source_id` (ADR-049): `<backend>:<part>[:<part>...]`.
 *
 * Source ids round-trip through the model (`site_rag_query` emits them,
 * `site_fetch_source` receives them back), so parsing is strict: a fixed
 * grammar, bounded length, no surprises. Anything else is rejected with
 * null — never an exception — because the input is model-chosen.
 */
final readonly class SourceReference
{
    private const MAX_LENGTH = 200;

    private const GRAMMAR = '/^[a-z][a-z0-9_]{0,30}(?::[A-Za-z0-9_.\-]{1,64}){1,4}$/';

    /**
     * @param list<string> $parts
     */
    private function __construct(
        public string $backend,
        public array $parts,
    ) {}

    public static function parse(string $sourceId): ?self
    {
        if (strlen($sourceId) > self::MAX_LENGTH || preg_match(self::GRAMMAR, $sourceId) !== 1) {
            return null;
        }

        $segments = explode(':', $sourceId);
        $backend = array_shift($segments);

        return new self($backend, $segments);
    }

    public function toString(): string
    {
        return $this->backend . ':' . implode(':', $this->parts);
    }
}
