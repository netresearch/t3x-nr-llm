<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Streaming;

use Closure;

/**
 * Carries the text of one tool round from the provider to the caller's sink
 * (ADR-240), with secrets masked before they leave.
 *
 * The provider call around it is the ordinary blocking pipeline call, so
 * budget, usage, telemetry and the output guardrails work on the complete
 * response as they always do. This class handles the one thing that is new:
 * text that has left before the response is complete.
 *
 * A call can be sent twice in one pipeline run, by the fallback chain or by a
 * guardrail's retry. The sink then hears `restart` before the second attempt's
 * text, and drops what it holds of this round.
 *
 * @internal
 */
final class ToolRoundTextRelay
{
    private ?StreamRedactionWindow $window = null;

    private int $attempts = 0;

    private ?int $startedNs = null;

    private ?float $firstTextMs = null;

    /**
     * @param Closure(string, bool): void             $sink    a piece of text, and whether to drop what came before
     * @param (Closure(): StreamRedactionWindow)|null $windows a fresh window for an attempt; null when no redactor is registered
     */
    public function __construct(
        private readonly Closure $sink,
        private readonly ?Closure $windows,
    ) {}

    /**
     * An attempt starts.
     */
    public function begin(): void
    {
        if ($this->attempts++ > 0) {
            ($this->sink)('', true);
        }

        $this->window      = $this->windows instanceof Closure ? ($this->windows)() : null;
        $this->startedNs   = hrtime(true);
        $this->firstTextMs = null;
    }

    public function push(string $text): void
    {
        if ($this->firstTextMs === null && $this->startedNs !== null) {
            $this->firstTextMs = round((hrtime(true) - $this->startedNs) / 1_000_000, 2);
        }

        if (!$this->window instanceof StreamRedactionWindow) {
            ($this->sink)($text, false);

            return;
        }

        foreach ($this->window->push($text) as $delta) {
            ($this->sink)($delta, false);
        }
    }

    /**
     * The attempt's text is complete: what the window held back goes out.
     */
    public function finish(): void
    {
        if ($this->window instanceof StreamRedactionWindow) {
            foreach ($this->window->flush() as $delta) {
                ($this->sink)($delta, false);
            }
        }
    }

    /**
     * Milliseconds from the attempt's start to its first text; null without text.
     */
    public function firstTextMs(): ?float
    {
        return $this->firstTextMs;
    }
}
