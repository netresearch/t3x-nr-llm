<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Netresearch\NrLlm\Domain\Model\DecisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Provider\Exception\InvalidDecisionResponseException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;

/**
 * A provider whose models answer typed questions natively (ADR-211).
 *
 * Opt-in, beside streaming, tools and vision: a provider implements it when
 * its API takes a subject and yes/no, choice and score questions and returns
 * typed answers. Feature code reaches it only through
 * {@see \Netresearch\NrLlm\Service\LlmServiceManager}, inside the middleware
 * pipeline, after the subject was screened; the backend connection tests call
 * it directly, as they call `complete()` on a chat model.
 *
 * @api Extension point: third parties implement this. No new abstract
 * member within a major version (ADR-127).
 */
interface DecisionCapableInterface
{
    /**
     * @param list<DecisionQuestion> $questions
     * @param array<string, mixed>   $options   the configuration's call options; `model` names the model
     *
     * @throws InvalidDecisionResponseException when the provider answered, but not with one valid answer per question
     * @throws ProviderException                on every other failure — a 4xx carries its HTTP status
     */
    public function decide(DecisionSubject $subject, array $questions, array $options = []): DecisionResponse;
}
