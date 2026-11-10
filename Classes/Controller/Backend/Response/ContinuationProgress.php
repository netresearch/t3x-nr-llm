<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\Response;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;

/**
 * What the Agent Runs inbox sends while a decision continues a run (ADR-276).
 *
 * A reverse proxy counts its timeout between two reads, so a continuation of
 * many rounds that sends nothing until it ends is cut off. This writes a line
 * per recorded step, and so no read waits longer than one step.
 *
 * A line carries a step count and nothing else. The person deciding may hold
 * the approval grant without being allowed to read the run (ADR-153); what the
 * run did is on its detail page, for whoever may open it. The outcome is the
 * flash message the inbox queues as before, read on the list the client loads
 * after `settled`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ContinuationProgress
{
    /**
     * A text delta is no step of its own, but a round whose answer streams in
     * produces nothing else until it ends. Every so many seconds one keeps the
     * connection alive.
     */
    public const DELTA_INTERVAL_SECONDS = 10;

    private int $steps = 0;

    private ?int $lastLineAt = null;

    /**
     * @param (Closure(): int)|null $clock the current Unix time; null reads time()
     */
    public function __construct(
        private readonly NdjsonStream $stream,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * The step observer to hand to the runtime.
     *
     * @return Closure(RunStep): void
     */
    public function observer(): Closure
    {
        return $this->onStep(...);
    }

    /**
     * Whether a line has been sent, so the response is this stream now.
     */
    public function isOpen(): bool
    {
        return $this->stream->isOpen();
    }

    /**
     * Tell the client the continuation has ended. Nothing when no line was
     * sent: the caller answers with its ordinary response then.
     */
    public function settle(): void
    {
        if ($this->stream->isOpen()) {
            $this->stream->line(['event' => 'settled']);
        }
    }

    private function onStep(RunStep $step): void
    {
        $now = $this->clock instanceof Closure ? ($this->clock)() : time();

        if ($step->kind === RunStep::KIND_TEXT_DELTA || $step->kind === RunStep::KIND_TEXT_RESTART) {
            if ($this->lastLineAt !== null && $now - $this->lastLineAt < self::DELTA_INTERVAL_SECONDS) {
                return;
            }
        } else {
            ++$this->steps;
        }

        $this->stream->line(['event' => 'progress', 'steps' => $this->steps]);
        $this->lastLineAt = $now;
    }
}
