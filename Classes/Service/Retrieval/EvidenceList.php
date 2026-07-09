<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

/**
 * The evidence package one retrieval run produced (ADR-049): which backend
 * answered, the curated sources, and human-readable degradation notes
 * (skipped backends, fallback reasons) so the model knows the evidence
 * quality.
 */
final readonly class EvidenceList
{
    /**
     * @param list<EvidenceSource> $sources
     * @param list<string>         $notes
     */
    public function __construct(
        public string $backend,
        public array $sources,
        public array $notes = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->sources === [];
    }

    /**
     * @param list<string> $notes
     */
    public function withNotes(array $notes): self
    {
        return new self($this->backend, $this->sources, array_values([...$this->notes, ...$notes]));
    }
}
