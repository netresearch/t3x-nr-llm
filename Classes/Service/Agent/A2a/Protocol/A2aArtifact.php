<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * One `Artifact` of a task (proto `Artifact`).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aArtifact
{
    /**
     * @param list<A2aPart>        $parts
     * @param array<string, mixed> $metadata
     * @param list<string>         $extensions
     */
    public function __construct(
        public string $artifactId,
        public array $parts,
        public string $name = '',
        public string $description = '',
        public array $metadata = [],
        public array $extensions = [],
    ) {}

    /**
     * The text of every text part, in order, joined by a blank line.
     */
    public function text(): string
    {
        $texts = [];
        foreach ($this->parts as $part) {
            if ($part->kind === A2aPartKind::TEXT) {
                $texts[] = $part->text;
            }
        }

        return implode("\n\n", $texts);
    }
}
