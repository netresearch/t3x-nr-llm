<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use Netresearch\NrLlm\Domain\Enum\QuestionForm;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * A single labeled question inside a golden question set (ADR-072), the
 * retrieval counterpart of {@see GoldenPrompt}.
 *
 * `expectedDocumentIds` are ALL document ids that answer the question
 * (multi-target labels are intentional); a retrieved document counts as a
 * hit when it is any of them. An EMPTY list declares a question no indexed
 * document answers — such a question scores as a hit only when the
 * retriever correctly returns nothing.
 *
 * `hardClass` optionally marks a known-difficult retrieval class the set
 * wants broken out in reporting. The BMDV methodology uses
 * `near-duplicate`, `specific-vs-general`, `prose-free`, `boilerplate` and
 * `normal`; the value is free-form so consumer sets can extend the
 * taxonomy. `answerGist` is label documentation for humans re-verifying
 * the labels — the scoring never reads it.
 */
final readonly class GoldenQuestion
{
    /**
     * @param list<string> $expectedDocumentIds All document ids that answer the question; empty = expects no result
     */
    public function __construct(
        public string $id,
        public string $question,
        public QuestionForm $form,
        public array $expectedDocumentIds,
        public ?string $hardClass = null,
        public ?string $answerGist = null,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Golden question id must not be empty.', 1794000050);
        }
        if ($question === '') {
            throw new InvalidArgumentException(
                sprintf('Golden question "%s" must declare a non-empty question.', $id),
                1794000051,
            );
        }
        $seen = [];
        foreach ($expectedDocumentIds as $documentId) {
            if ($documentId === '') {
                throw new InvalidArgumentException(
                    sprintf('Golden question "%s" declares an empty expected document id.', $id),
                    1794000052,
                );
            }
            if (isset($seen[$documentId])) {
                throw new InvalidArgumentException(
                    sprintf('Golden question "%s" declares duplicate expected document id "%s".', $id, $documentId),
                    1794000053,
                );
            }
            $seen[$documentId] = true;
        }
        if ($hardClass === '') {
            throw new InvalidArgumentException(
                sprintf('Golden question "%s" must declare a non-empty hard class or none.', $id),
                1794000054,
            );
        }
    }

    /**
     * Whether the correct retrieval outcome for this question is an empty
     * result (no indexed document answers it).
     */
    public function expectsNoResult(): bool
    {
        return $this->expectedDocumentIds === [];
    }
}
