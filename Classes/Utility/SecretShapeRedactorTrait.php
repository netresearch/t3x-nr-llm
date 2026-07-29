<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Utility;

/**
 * The single catalogue of secret shapes this extension recognises (ADR-123).
 *
 * Three places used to mask secrets and each knew a different subset: the
 * response/prompt guardrails knew modern OpenAI project keys, GitHub PATs, AWS,
 * Google and Slack tokens and JWTs; the privacy redactor that decides what gets
 * WRITTEN to the database knew none of those; and the environment-listing tool
 * matched on variable NAMES only. So a secret correctly masked on its way to a
 * provider was still persisted in cleartext, and a variable whose name gave
 * nothing away — ``GITHUB_PAT``, ``STRIPE_LIVE`` — egressed its value verbatim.
 *
 * The masks are deliberately not uniform: ``sk-`` and ``Bearer`` keep their
 * prefix so a reader can tell WHAT was removed, while opaque vendor tokens
 * collapse to a bare mask.
 *
 * Two entry points, because the two kinds of caller need opposite behaviour when
 * the regex engine gives up — see {@see redactSecretShapes()} (fail open) and
 * {@see redactSecretShapesStrict()} (fail closed).
 *
 * This is best-effort: it recognises these shapes and nothing else, and does not
 * replace keeping secrets in nr-vault.
 */
trait SecretShapeRedactorTrait
{
    use ErrorMessageSanitizerTrait;

    private const SECRET_SHAPE_MASK = '***';

    /**
     * Mask every recognised secret shape, keeping the content when a pattern
     * fails.
     *
     * For paths where losing the text is worse than missing one pattern: a
     * guardrail rewriting a model response or an outgoing prompt must not replace
     * the whole payload with an empty string because the regex engine hit a
     * backtrack limit on a large input.
     */
    protected function redactSecretShapes(string $content): string
    {
        $failed = false;

        return $this->applySecretShapePatterns($content, $failed);
    }

    /**
     * Mask every recognised secret shape, or return null if any pattern failed.
     *
     * For egress paths where leaking is worse than losing the value: a caller
     * listing environment variables to a language model should substitute a mask
     * rather than forward a possibly secret-bearing string it could not fully
     * inspect.
     */
    protected function redactSecretShapesStrict(string $content): ?string
    {
        $failed = false;
        $redacted = $this->applySecretShapePatterns($content, $failed);

        return $failed ? null : $redacted;
    }

    /**
     * Run the pattern list in order, recording whether any pattern failed.
     *
     * `preg_replace()` returns null when the engine gives up (a backtrack limit
     * hit on a huge payload, for instance). A bare (string) cast would turn that
     * into '' — silently wiping the content, which on a redaction path looks
     * exactly like a successful, very thorough redaction. The last good string is
     * kept instead and the failure is reported through $failed so a caller that
     * must fail closed can.
     *
     * Order matters: credential-bearing URLs are masked first, so ``?token=<jwt>``
     * collapses to one masked parameter rather than a masked JWT behind a
     * dangling parameter name.
     */
    private function applySecretShapePatterns(string $content, bool &$failed): string
    {
        // Credential query parameters and connection-string userinfo.
        $redacted = $this->sanitizeErrorMessage($content);

        foreach (self::secretShapePatterns() as [$pattern, $replacement]) {
            $result = preg_replace($pattern, $replacement, (string)$redacted);

            if (is_string($result)) {
                $redacted = $result;

                continue;
            }

            $failed = true;
        }

        return $redacted;
    }

    /**
     * Secret shapes that survive the URL sanitiser, as [pattern, replacement].
     *
     * All patterns are bounded character classes separated by literals — no
     * nested quantifiers, so none is vulnerable to catastrophic backtracking.
     *
     * @return list<array{string, string}>
     */
    private static function secretShapePatterns(): array
    {
        return [
            // Bearer credentials FIRST: a 'Bearer <token>' match subsumes whatever
            // the token is, so running it before the prefix-specific shapes makes
            // 'Bearer sk-…' collapse to a single mask. The other way round, the
            // OpenAI rule rewrote the key to 'sk-***' and the Bearer rule then
            // matched the leftover 'Bearer sk-', yielding 'Bearer ******'.
            // The class covers base64-standard characters (+ / =) so a token's
            // tail is not left behind after the mask.
            ['/\b(Bearer\s+)[A-Za-z0-9._~+\/\-]+=*/i', '$1' . self::SECRET_SHAPE_MASK],
            // OpenAI. The class allows '-' and '_' so modern project keys
            // (sk-proj-…) match; the mask keeps the prefix.
            ['/\bsk-[A-Za-z0-9_\-]{16,}/', 'sk-' . self::SECRET_SHAPE_MASK],
            // A JWT is the canonical bearer secret even without a 'Bearer '
            // prefix. Only the header segment must start with 'eyJ'.
            ['/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', self::SECRET_SHAPE_MASK],
            // GitHub: classic tokens, then fine-grained PATs.
            ['/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{36,}/', self::SECRET_SHAPE_MASK],
            ['/\bgithub_pat_[A-Za-z0-9_]{22,}/', self::SECRET_SHAPE_MASK],
            ['/\bAKIA[0-9A-Z]{16,}/', self::SECRET_SHAPE_MASK],
            ['/\bAIza[0-9A-Za-z_\-]{35,}/', self::SECRET_SHAPE_MASK],
            ['/\bxox[baprs]-[A-Za-z0-9\-]{10,}/', self::SECRET_SHAPE_MASK],
            // Stripe secret and publishable keys. Note the underscore: these do
            // not collide with the hyphenated OpenAI shape above.
            ['/\b[sp]k_(?:live|test)_[A-Za-z0-9]{24,}/', self::SECRET_SHAPE_MASK],
            ['/\bSG\.[A-Za-z0-9_\-]{22}\.[A-Za-z0-9_\-]{43}/', self::SECRET_SHAPE_MASK],
        ];
    }
}
