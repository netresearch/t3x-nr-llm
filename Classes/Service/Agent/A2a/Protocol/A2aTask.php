<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

/**
 * A remote `Task` as the remote reported it (proto `Task`).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aTask
{
    /**
     * @param list<A2aArtifact>    $artifacts
     * @param list<A2aMessage>     $history
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $contextId,
        public A2aTaskStatus $status,
        public array $artifacts = [],
        public array $history = [],
        public array $metadata = [],
    ) {}

    /**
     * The text of every artifact, in order, joined by a blank line.
     */
    public function artifactText(): string
    {
        $texts = [];
        foreach ($this->artifacts as $artifact) {
            $text = $artifact->text();
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return implode("\n\n", $texts);
    }
}
