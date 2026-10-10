<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

/**
 * Characters a model reads and a reviewer cannot see, in the text a skill
 * version would instruct with (ADR-214 item 2).
 *
 * An approval binds to the bytes of a version. That is only an approval of
 * what the reviewer saw if every byte is visible on the review page; tag
 * characters, zero-width and direction marks render as nothing in a browser
 * and as text to a model. A version that carries any of them cannot be
 * approved, and the review page names each one with its position.
 *
 * The character class is the one the write tools' approval previews use
 * ({@see \Netresearch\NrLlm\Service\Tool\Builtin\WritesThroughDataHandlerTrait},
 * `INVISIBLE_CHARACTERS`; a test keeps both in step), plus U+2800, the Braille
 * pattern blank, which renders as nothing too. Line feed, carriage return and
 * tab are exempt: they are layout in a Markdown body, not hidden.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class SkillInvisibleCharacters
{
    public const PATTERN = '/[\p{C}\p{Zl}\p{Zp}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{E0100}-\x{E01EF}\x{2800}]|(?! )\p{Zs}/u';

    /** How many findings are listed by position; the rest are counted. */
    private const MAX_LISTED = 20;

    /**
     * Every invisible character in the given texts, as "<field>, line <n>:
     * U+XXXX", in field order then position. Text that is not valid UTF-8 is
     * reported as one finding, since nothing in it can be shown reliably.
     *
     * @param array<string, string> $texts field label => text
     *
     * @return list<string>
     */
    public static function findIn(array $texts): array
    {
        $findings = [];
        $total    = 0;
        foreach ($texts as $field => $text) {
            if (!mb_check_encoding($text, 'UTF-8')) {
                if (count($findings) < self::MAX_LISTED) {
                    $findings[] = sprintf('%s: not valid UTF-8', $field);
                }

                ++$total;
                continue;
            }

            // Layout whitespace becomes a plain space of the same byte length,
            // so offsets stay those of the original text.
            $probe = strtr($text, ["\n" => ' ', "\r" => ' ', "\t" => ' ']);
            if (preg_match_all(self::PATTERN, $probe, $matches, PREG_OFFSET_CAPTURE) === false) {
                if (count($findings) < self::MAX_LISTED) {
                    $findings[] = sprintf('%s: could not be checked', $field);
                }

                ++$total;
                continue;
            }

            foreach ($matches[0] as [$character, $offset]) {
                ++$total;
                if (count($findings) >= self::MAX_LISTED) {
                    continue;
                }

                $findings[] = sprintf(
                    '%s, line %d: U+%04X',
                    $field,
                    substr_count($text, "\n", 0, $offset) + 1,
                    mb_ord($character, 'UTF-8'),
                );
            }
        }

        if ($total > count($findings)) {
            $findings[] = sprintf('… and %d more', $total - count($findings));
        }

        return $findings;
    }
}
