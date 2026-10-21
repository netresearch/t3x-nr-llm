<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

/**
 * Verifies a source's manifest fingerprint at ingest (ADR-061).
 *
 * An administrator declares an expected SHA-256 digest out of band. Sync
 * recomputes it from the collected identifier => body-checksum pairs before
 * materializing any skill. A mismatch aborts ingest and is audited.
 *
 * The fingerprint binds those identifiers and parsed markdown bodies. It
 * does not cover frontmatter, trust labels or approval state; the version
 * digest used for approval is a separate control. This is an expected
 * content digest, not a detached public-key signature.
 */
final class SkillManifestVerifier
{
    /**
     * Whether the source declares an expected fingerprint at all. Verification
     * is opt-in — an empty declaration means "not verified" (the SHA-pin still
     * applies), a non-empty one makes verification mandatory and fail-closed.
     */
    public function isDeclared(string $expectedFingerprint): bool
    {
        return trim($expectedFingerprint) !== '';
    }

    /**
     * Canonical manifest digest over identifier => body-checksum pairs.
     *
     * Sort identifiers, encode each as identifier:checksum, join with LF and
     * no trailing LF, then hash the bytes with SHA-256. Discovery order does
     * not affect the digest. Frontmatter and approval metadata are not inputs.
     *
     * @param array<string, string> $identifierToChecksum
     */
    public function computeFingerprint(array $identifierToChecksum): string
    {
        ksort($identifierToChecksum);

        $lines = [];
        foreach ($identifierToChecksum as $identifier => $checksum) {
            $lines[] = $identifier . ':' . $checksum;
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Verify the declared fingerprint against the computed manifest digest.
     *
     * Fail-closed: returns false for an empty declaration, an empty set, or any
     * mismatch. The comparison is constant-time and case-insensitive on the hex
     * (declarations are pasted by hand).
     *
     * @param array<string, string> $identifierToChecksum
     */
    public function verify(string $expectedFingerprint, array $identifierToChecksum): bool
    {
        if (!$this->isDeclared($expectedFingerprint)) {
            return false;
        }

        if ($identifierToChecksum === []) {
            return false;
        }

        return hash_equals(
            $this->computeFingerprint($identifierToChecksum),
            strtolower(trim($expectedFingerprint)),
        );
    }
}
