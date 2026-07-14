<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

/**
 * Verifies a source's *manifest fingerprint* at ingest (ADR-061).
 *
 * The URL SHA-pin (ADR-035) binds "these bytes came from this commit". The
 * fingerprint adds a publisher-identity binding on top: an admin declares, out
 * of band, the sha256 digest they expect the source's whole skill set to hash
 * to. On sync the digest is recomputed from the materialised
 * (identifier → body-checksum) pairs and compared with {@see hash_equals}; a
 * mismatch is fail-closed (no skill is enabled) and audited.
 *
 * This is a deliberate, documented middle ground rather than a full detached
 * public-key signature: it needs no key-management infrastructure yet still
 * binds a publisher-declared identity to the exact reviewed content across all
 * source types. A single_file source degenerates to one entry; repo and
 * marketplace sources fold every discovered skill into one canonical digest.
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
     * Canonical manifest digest over the (identifier → body-checksum) pairs.
     *
     * Order-independent: identifiers are sorted before hashing, so the digest
     * depends only on the set of skills and their content — not on discovery
     * order.
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
