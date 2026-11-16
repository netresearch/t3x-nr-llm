<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

/**
 * The runs whose answer is judged after their loop, not in each provider call
 * (ADR-302).
 *
 * A run with an end-of-run check judges its delivered answer once. Without
 * this the rule-set judge would also judge every answer of the loop, and each
 * text would cost two judge calls. Only the answer branch is deferred: the
 * arguments of a writing tool are judged in the call that proposes them, as
 * before.
 *
 * Set by the run executor on the server, never from a request. Shared by the
 * container, so it holds for the request or the worker process that runs the
 * loop.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class DeferredAnswerJudging
{
    /** @var array<int, true> */
    private array $runs = [];

    public function covers(int $runUid): bool
    {
        return $runUid > 0 && isset($this->runs[$runUid]);
    }

    /**
     * @template T
     *
     * @param callable(): T $loop
     *
     * @return T
     */
    public function during(int $runUid, callable $loop): mixed
    {
        if ($runUid <= 0) {
            return $loop();
        }

        $this->runs[$runUid] = true;
        try {
            return $loop();
        } finally {
            unset($this->runs[$runUid]);
        }
    }
}
