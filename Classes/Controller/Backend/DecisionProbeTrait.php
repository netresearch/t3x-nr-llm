<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\DecisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;
use Netresearch\NrLlm\Provider\Contract\DecisionCapableInterface;

/**
 * The connection test of a decision model (ADR-211): one yes/no question
 * about the test prompt.
 *
 * A decision model takes typed questions and no prompt, so the chat probe of
 * the model and configuration tests would be refused by its adapter. Like
 * that probe, this one calls the adapter directly: it verifies the record's
 * connection, key and model id with the smallest request the provider takes.
 *
 * @internal
 */
trait DecisionProbeTrait
{
    private const DECISION_PROBE_KEY = 'probe';

    /**
     * @param array<string, mixed> $options the call options; `model` names the model
     */
    private function probeDecision(DecisionCapableInterface $adapter, string $testPrompt, array $options): DecisionResponse
    {
        return $adapter->decide(
            new DecisionSubject(candidate: $testPrompt),
            [new YesNoQuestion(self::DECISION_PROBE_KEY, 'Is `candidate` a question or a request?')],
            $options,
        );
    }

    /**
     * The probability of "yes" the probe answered with.
     */
    private function decisionProbeYes(DecisionResponse $response): float
    {
        return $response->answers[self::DECISION_PROBE_KEY]->value ?? 0.0;
    }
}
