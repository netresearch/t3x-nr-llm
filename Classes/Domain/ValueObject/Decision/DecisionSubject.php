<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject\Decision;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * What a decision is about (ADR-211). It is data, never instructions: the
 * questions and their criteria come from the profile alone, so nothing in a
 * subject replaces the rubric it is sent with. It can still try to sway the
 * answer — a model reads it next to the questions — which is why a decision
 * is information for the caller, never a permission.
 *
 * A field is absent when it is null (task, candidate) or empty (evidence).
 * An empty string is a present, empty value — a blank answer is something a
 * profile may well want judged.
 *
 * @api
 */
final readonly class DecisionSubject
{
    /**
     * @param list<string> $evidence one entry per source passage, in the order the caller holds them
     */
    public function __construct(
        public ?string $task = null,
        public ?string $candidate = null,
        public array $evidence = [],
    ) {
        // A list, so the order a caller holds its passages in is the order
        // every provider reads them in; string keys would not survive JSON.
        if (!array_is_list($evidence)) {
            throw new InvalidArgumentException('The evidence of a decision subject must be a list of passages, not a map.', 1795211008);
        }
    }

    public function has(SubjectField $field): bool
    {
        return match ($field) {
            SubjectField::Task      => $this->task !== null,
            SubjectField::Candidate => $this->candidate !== null,
            SubjectField::Evidence  => $this->evidence !== [],
        };
    }

    /**
     * The present fields keyed by their {@see SubjectField} value — the
     * state a model evaluates.
     *
     * @return array{task?: string, candidate?: string, evidence?: list<string>}
     */
    public function toState(): array
    {
        $state = [];
        if ($this->task !== null) {
            $state['task'] = $this->task;
        }

        if ($this->candidate !== null) {
            $state['candidate'] = $this->candidate;
        }

        if ($this->evidence !== []) {
            $state['evidence'] = $this->evidence;
        }

        return $state;
    }

    /**
     * A copy with every text passed through `$transform` — used to screen
     * the subject before it leaves the process.
     *
     * @param callable(string): string $transform
     */
    public function map(callable $transform): self
    {
        return new self(
            task: $this->task === null ? null : $transform($this->task),
            candidate: $this->candidate === null ? null : $transform($this->candidate),
            evidence: array_map($transform, $this->evidence),
        );
    }
}
