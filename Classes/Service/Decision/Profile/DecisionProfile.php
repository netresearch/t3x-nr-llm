<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Decision\Profile;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\SubjectField;
use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * What a consumer wants decided, declared once and versioned (ADR-211).
 *
 * The profile owns the questions and their criteria; the operator owns which
 * model answers them, through the configuration a request names. The data
 * class says how sensitive the subject is: the service refuses a
 * configuration whose provider — or any fallback — sits in a trust zone that
 * may not receive it. Raise `$version` whenever a question, an option, a
 * level or an instruction changes: thresholds a caller tuned against one
 * version do not carry over to the next.
 *
 * @api
 */
final readonly class DecisionProfile
{
    /**
     * @param string                 $identifier `<extension>.<name>`, unique across the installation
     * @param int                    $version    the criteria version, 1 or higher
     * @param list<DecisionQuestion> $questions  at least one, keys unique
     * @param list<SubjectField>     $requires   subject fields a request must carry
     * @param ToolDataClass          $dataClass  the most sensitive data the subject can hold
     */
    public function __construct(
        public string $identifier,
        public int $version,
        public array $questions,
        public array $requires = [],
        public ToolDataClass $dataClass = ToolDataClass::EDITOR_CONTENT,
        public string $description = '',
    ) {
        if (trim($identifier) === '') {
            throw new InvalidArgumentException('A decision profile needs an identifier.', 1795211010);
        }

        if ($version < 1) {
            throw new InvalidArgumentException(
                sprintf('Decision profile "%s" needs a version of 1 or higher, %d given.', $identifier, $version),
                1795211011,
            );
        }

        if ($questions === []) {
            throw new InvalidArgumentException(
                sprintf('Decision profile "%s" declares no question.', $identifier),
                1795211012,
            );
        }

        $keys = array_map(static fn(DecisionQuestion $question): string => $question->key(), $questions);
        $duplicates = array_keys(array_filter(array_count_values($keys), static fn(int $count): bool => $count > 1));
        if ($duplicates !== []) {
            throw new InvalidArgumentException(
                sprintf('Decision profile "%s" uses the question key "%s" more than once.', $identifier, $duplicates[0]),
                1795211013,
            );
        }
    }
}
