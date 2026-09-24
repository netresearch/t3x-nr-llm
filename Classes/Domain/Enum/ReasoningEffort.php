<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * How much a reasoning model is asked to think before it answers (ADR-204).
 *
 * The seven values are the ones OpenAI's `reasoning.effort` parameter defines.
 * **Which of them a given model accepts is a property of that model, not of
 * this enum** — `gpt-6-astra` rejects `None` with HTTP 400 while `gpt-6-luna`
 * takes it, and a case existing here is no claim that any model will serve it.
 * `OpenAiModelProfile::supports()` answers that question, and a request is
 * clamped to a value the model allows before it is sent.
 *
 * The order of the cases is the scale, lowest first, and
 * {@see self::rank()} makes it comparable. Nothing else may assume that
 * ordering from the case names.
 *
 * A provider without an effort scale ignores the option. That is visible
 * rather than silent: a response records the effort that was applied, and a
 * provider that applied none writes no key at all (ADR-204).
 *
 * @api
 */
enum ReasoningEffort: string
{
    /**
     * No reasoning at all. Latency-critical work, and the only setting under
     * which Chat Completions serves function tools for GPT-6 Sol and Luna.
     */
    case None = 'none';

    /**
     * The smallest amount of reasoning a model offers that is not zero.
     */
    case Minimal = 'minimal';

    /**
     * Efficient reasoning with a modest latency increase.
     */
    case Low = 'low';

    /**
     * The default for the GPT-6 family and for `gpt-5.5`.
     */
    case Medium = 'medium';

    /**
     * Hard reasoning, complex debugging, deep planning.
     */
    case High = 'high';

    /**
     * Deep research and long agentic runs.
     */
    case XHigh = 'xhigh';

    /**
     * Maximum reasoning, for the most complex tasks.
     */
    case Max = 'max';

    /**
     * Position on the scale, lowest first. Only for comparing two efforts of
     * the same model — it says nothing about cost or latency across models.
     */
    public function rank(): int
    {
        return match ($this) {
            self::None    => 0,
            self::Minimal => 1,
            self::Low     => 2,
            self::Medium  => 3,
            self::High    => 4,
            self::XHigh   => 5,
            self::Max     => 6,
        };
    }

    /**
     * Read an effort out of an options array.
     *
     * Accepts the enum itself and its string value, because the options array
     * crosses a `toArray()` boundary and a persisted resume snapshot. Anything
     * else — including an unknown string — is null: an option nobody can
     * honour must not become a request parameter the API rejects.
     */
    public static function tryFromOption(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? self::tryFrom($value) : null;
    }
}
