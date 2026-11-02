<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

/**
 * The model used its whole output limit and wrote no answer (ADR-241).
 *
 * A reasoning model counts its thinking against the same limit as its answer, so
 * a small limit can end the call with an empty text and finish reason `length`.
 * That text is not an answer, and handing it on turned into a JSON syntax error
 * or an empty field far from the cause.
 *
 * @api
 */
final class OutputBudgetExhaustedException extends RuntimeException implements NrLlmExceptionInterface
{
    public function __construct(
        public readonly string $configurationIdentifier,
        public readonly int $outputTokens,
    ) {
        parent::__construct(
            sprintf(
                'The model used its whole output limit (%d tokens) before it wrote an answer. Raise "Max. tokens" on the configuration "%s".',
                $outputTokens,
                $configurationIdentifier,
            ),
            2041730951,
        );
    }
}
