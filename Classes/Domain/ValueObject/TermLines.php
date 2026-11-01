<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The term text of a term list, one term per line (ADR-238).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TermLines
{
    /**
     * Empty lines and surrounding white space are dropped, and a repeated term
     * counts once. A line that is only `*` would match every word, so it is
     * dropped as well.
     *
     * @return list<string>
     */
    public static function parse(string $text): array
    {
        $terms = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $term = trim($line);
            if ($term === '' || trim($term, "* \t") === '') {
                continue;
            }

            $terms[$term] = true;
        }

        return array_map(strval(...), array_keys($terms));
    }
}
