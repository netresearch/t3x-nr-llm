<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Utility;

use Netresearch\NrVault\Secret\SecretPatternLibrary;

/**
 * Masks the secret shapes nr-vault's catalogue knows (ADR-123, nr-vault ADR-031).
 *
 * The shapes are no longer defined here. They live in
 * {@see SecretPatternLibrary}, the org-wide catalogue shared with nr-vault's
 * plaintext scanner, so a shape added for either extension is known to both —
 * which is the whole point of moving it upstream. What stays here is the part
 * specific to this extension: two entry points with opposite behaviour when the
 * regex engine gives up.
 *
 * The library is read through STATIC methods rather than the injectable
 * {@see \Netresearch\NrVault\Secret\SecretRedactorInterface}, because this trait is
 * also used from objects the container never builds — a provider exception, a
 * backend response DTO constructed with ``new``. A trait that reached into the DI
 * container to mask a string would be a worse dependency than a static call to a
 * pure pattern list.
 *
 * This is best-effort: it recognises the catalogued shapes and nothing else, and
 * does not replace keeping secrets in nr-vault.
 */
trait SecretShapeRedactorTrait
{
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
     * Run every inline pattern in the shared catalogue, recording whether any
     * failed.
     *
     * `preg_replace()` returns null when the engine gives up (a backtrack limit
     * hit on a huge payload, for instance). A bare (string) cast would turn that
     * into '' — silently wiping the content, which on a redaction path looks
     * exactly like a successful, very thorough redaction. The last good string is
     * kept instead and the failure is reported through $failed so a caller that
     * must fail closed can.
     *
     * The catalogue's own order is preserved: credential-bearing URLs first, then
     * ``Bearer …``, then the prefix-specific vendor shapes. That is not cosmetic —
     * masking ``?token=<jwt>`` as one parameter beats masking the JWT and leaving a
     * dangling parameter name, and ``Bearer sk-…`` must collapse to a single mask
     * rather than two.
     */
    private function applySecretShapePatterns(string $content, bool &$failed): string
    {
        $redacted = $content;

        foreach (SecretPatternLibrary::all() as $pattern) {
            if ($pattern->inlinePattern === null) {
                continue;
            }

            $result = preg_replace($pattern->inlinePattern, $pattern->inlineReplacement, $redacted);

            if (\is_string($result)) {
                $redacted = $result;

                continue;
            }

            $failed = true;
        }

        return $redacted;
    }
}
