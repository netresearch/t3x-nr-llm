<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail;

use Netresearch\NrLlm\Domain\ValueObject\GuardrailHint;

use function array_key_last;
use function array_pop;
use function array_values;

/**
 * Hands the hints of the guardrails to a caller that gets no response object
 * (ADR-288).
 *
 * A method that returns a string, a decoded array or the result of a tool loop
 * cannot carry `CompletionResponse::$metadata`. Such a caller wraps its call in
 * {@see self::capture()} and receives the hints every screen inside it
 * reported, nested calls included. The output guardrail middleware and the
 * streaming dispatcher record into it; a stream records when it is drained.
 *
 * One instance per request through the container.
 *
 * @api
 */
final class GuardrailHintCollector
{
    /** @var list<list<GuardrailHint>> one list per open capture, innermost last */
    private array $open = [];

    /**
     * Run a call and collect the hints reported while it ran. When the call
     * throws, nothing is returned and the capture is closed.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return array{result: T, hints: list<GuardrailHint>}
     */
    public function capture(callable $call): array
    {
        $this->open[] = [];
        try {
            $result = $call();
        } finally {
            $hints = $this->open[array_key_last($this->open)] ?? [];
            array_pop($this->open);
        }

        return ['result' => $result, 'hints' => $hints];
    }

    /**
     * Record hints in every open capture. Without one, nothing is kept.
     */
    public function record(GuardrailHint ...$hints): void
    {
        if ($hints === []) {
            return;
        }

        $hints = array_values($hints);
        foreach ($this->open as $index => $collected) {
            $this->open[$index] = [...$collected, ...$hints];
        }
    }
}
