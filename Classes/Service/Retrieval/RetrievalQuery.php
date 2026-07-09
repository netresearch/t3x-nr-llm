<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

use InvalidArgumentException;

/**
 * A validated retrieval request against the site-search backends (ADR-049).
 *
 * Constructing an out-of-range query throws — callers that accept
 * model-chosen or user-supplied values MUST clamp before constructing
 * (the tools do; see SiteRagQueryTool).
 */
final readonly class RetrievalQuery
{
    public const MIN_QUERY_LENGTH = 2;

    public const MAX_QUERY_LENGTH = 200;

    public const MAX_SOURCES = 20;

    private function __construct(
        public string $query,
        public int $maxSources,
        public ?string $siteIdentifier,
        public int $languageId,
    ) {}

    public static function create(
        string $query,
        int $maxSources = 8,
        ?string $siteIdentifier = null,
        int $languageId = 0,
    ): self {
        $query = trim($query);
        $length = mb_strlen($query);
        if ($length < self::MIN_QUERY_LENGTH || $length > self::MAX_QUERY_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Query length must be %d-%d characters.', self::MIN_QUERY_LENGTH, self::MAX_QUERY_LENGTH),
                7688100001,
            );
        }

        if ($maxSources < 1 || $maxSources > self::MAX_SOURCES) {
            throw new InvalidArgumentException(
                sprintf('maxSources must be 1-%d.', self::MAX_SOURCES),
                7688100002,
            );
        }

        if ($languageId < 0) {
            throw new InvalidArgumentException('languageId must not be negative.', 7688100003);
        }

        return new self($query, $maxSources, $siteIdentifier, $languageId);
    }
}
