<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * One `tx_nrllm_promptsnippet` record a pack declares (ADR-163).
 *
 * Like {@see PackTask}, the installed record is an ordinary snippet. The data
 * class stays OPTIONAL and defaults to undeclared, matching the TCA default:
 * ADR-144 made an undeclared snippet one that cannot block a call, and a pack
 * inventing a sensitivity ceiling on the operator's behalf would decide a
 * governance question the operator never answered.
 */
final readonly class PackSnippet
{
    /** `alphanum_x,lower` — lowercase letters, digits, `_` and `-`. No dots. */
    private const IDENTIFIER_PATTERN = '/^[a-z0-9_-]+$/';

    /** The `tx_nrllm_promptsnippet.identifier` column is varchar(100). */
    private const IDENTIFIER_MAX_LENGTH = 100;

    /** The `tx_nrllm_promptsnippet.name` and `.tags` columns are varchar(255). */
    private const VARCHAR_MAX_LENGTH = 255;

    /**
     * @param list<string> $tags Free-form tags; stored comma-separated
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public string $description,
        public string $snippet,
        public array $tags = [],
        public ?ToolDataClass $dataClass = null,
    ) {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid pack snippet identifier "%s": expected lowercase [a-z0-9_-], matching the tx_nrllm_promptsnippet TCA contract.',
                    $identifier,
                ),
                1791460011,
            );
        }

        if (strlen($identifier) > self::IDENTIFIER_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Pack snippet identifier "%s" exceeds the %d-character identifier column limit.',
                    $identifier,
                    self::IDENTIFIER_MAX_LENGTH,
                ),
                1791460012,
            );
        }

        if ($name === '' || mb_strlen($name) > self::VARCHAR_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Pack snippet "%s" name must be 1-%d characters (name column limit); got %d.',
                    $identifier,
                    self::VARCHAR_MAX_LENGTH,
                    mb_strlen($name),
                ),
                1791460013,
            );
        }

        if (trim($snippet) === '') {
            throw new InvalidArgumentException(
                sprintf('Pack snippet "%s" declares an empty snippet body.', $identifier),
                1791460014,
            );
        }

        if (mb_strlen($this->tagList()) > self::VARCHAR_MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Pack snippet "%s" tags exceed the %d-character tags column limit.',
                    $identifier,
                    self::VARCHAR_MAX_LENGTH,
                ),
                1791460015,
            );
        }
    }

    /**
     * The comma-separated form the `tags` column stores.
     */
    public function tagList(): string
    {
        return implode(',', $this->tags);
    }
}
