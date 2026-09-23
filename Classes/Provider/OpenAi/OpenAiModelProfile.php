<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;

/**
 * What one OpenAI model family allows (ADR-203).
 *
 * This replaces the `/^(o[1-9]|gpt-5)/` regex that decided whether to send
 * sampling parameters. That regex answered one question and was consulted for
 * a second one it had never been written for, and it silently excluded GPT-6 —
 * which is why `temperature` was still being sent to a reasoning model and why
 * tool calls went to an endpoint that refuses them.
 *
 * Three separate facts live here, and they are separate because the models
 * separate them:
 *
 * - `$isReasoningModel` decides whether sampling parameters are sent.
 * - `$supportedEfforts` decides what `reasoning.effort` may say, and is the
 *   reason a `think = false` request to `gpt-6-astra` is clamped to `low`
 *   rather than refused: Astra has no `None`.
 * - `$toolsOnChatCompletions` decides the transport. GPT-6 Astra serves no
 *   function calling on `chat/completions` at all; Sol and Luna serve it only
 *   at `None`, which is not a price this extension pays by default.
 */
final readonly class OpenAiModelProfile
{
    /**
     * @param list<ReasoningEffort> $supportedEfforts the efforts this family
     *                                                accepts, lowest first; empty for a model with no
     *                                                reasoning scale
     */
    public function __construct(
        public string $family,
        public bool $isReasoningModel,
        public array $supportedEfforts = [],
        public ?ReasoningEffort $defaultEffort = null,
        public bool $toolsOnChatCompletions = true,
    ) {}

    /**
     * The profile for a model this extension knows nothing about.
     *
     * It is deliberately the CONSERVATIVE answer and not a guess at the
     * family: no reasoning, no effort scale, tools on the endpoint that has
     * always served them. An unknown model id is most often an operator's own
     * deployment name or a compatible gateway's, and changing the transport
     * under it would break an installation that works.
     */
    public static function unknown(string $model): self
    {
        return new self(
            family: $model,
            isReasoningModel: false,
        );
    }

    public function supports(ReasoningEffort $effort): bool
    {
        return in_array($effort, $this->supportedEfforts, true);
    }

    public function hasEffortScale(): bool
    {
        return $this->supportedEfforts !== [];
    }

    /**
     * The lowest effort this model allows, which is what `think = false` asks
     * for. Null where the model has no scale at all — and null is not `None`:
     * a model without a scale must receive no `reasoning` parameter, while a
     * model whose floor is `None` must receive one.
     */
    public function lowestEffort(): ?ReasoningEffort
    {
        $lowest = null;
        foreach ($this->supportedEfforts as $effort) {
            if (!$lowest instanceof ReasoningEffort || $effort->rank() < $lowest->rank()) {
                $lowest = $effort;
            }
        }

        return $lowest;
    }

    /**
     * Move a requested effort onto this model's scale.
     *
     * An effort the model allows is returned unchanged. One it does not is
     * moved to the nearest allowed value — asking for `None` on Astra gives
     * `Low`, asking for `Max` on a model that stops at `High` gives `High`.
     * Two values equally near go to the higher one: `Minimal` on a GPT-6
     * scale sits between `None` and `Low`, and a request for a little
     * reasoning must not come back as none at all. The caller records what
     * came back, so a clamp is readable rather than silent (ADR-204).
     */
    public function clamp(ReasoningEffort $requested): ?ReasoningEffort
    {
        if (!$this->hasEffortScale()) {
            return null;
        }

        if ($this->supports($requested)) {
            return $requested;
        }

        $best = null;
        foreach ($this->supportedEfforts as $candidate) {
            if (!$best instanceof ReasoningEffort) {
                $best = $candidate;

                continue;
            }

            $distance     = abs($candidate->rank() - $requested->rank());
            $bestDistance = abs($best->rank() - $requested->rank());
            if ($distance < $bestDistance
                || ($distance === $bestDistance && $candidate->rank() > $best->rank())) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
