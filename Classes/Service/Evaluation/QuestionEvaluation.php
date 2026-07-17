<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Domain\Enum\QuestionForm;

/**
 * The evaluation of one golden question within a retrieval run (ADR-072):
 * the top-1/top-3 hit verdicts, the documents the retriever actually
 * returned, and the wall-clock latency of the retrieval call — the
 * retrieval counterpart of {@see PromptEvaluation}.
 *
 * `form` and `hardClass` are copied from the question so the result can
 * compute its by-form and by-hard-class breakdowns without re-resolving
 * the set.
 */
final readonly class QuestionEvaluation
{
    /**
     * @param list<string> $retrievedDocumentIds Distinct document ids the retriever returned, best first
     */
    public function __construct(
        public string $questionId,
        public QuestionForm $form,
        public ?string $hardClass,
        public bool $top1Hit,
        public bool $top3Hit,
        public array $retrievedDocumentIds,
        public int $latencyMs,
    ) {}

    /**
     * @return array{questionId: string, form: string, hardClass: string|null, top1Hit: bool, top3Hit: bool, retrievedDocumentIds: list<string>, latencyMs: int}
     */
    public function toArray(): array
    {
        return [
            'questionId' => $this->questionId,
            'form' => $this->form->value,
            'hardClass' => $this->hardClass,
            'top1Hit' => $this->top1Hit,
            'top3Hit' => $this->top3Hit,
            'retrievedDocumentIds' => $this->retrievedDocumentIds,
            'latencyMs' => $this->latencyMs,
        ];
    }
}
