<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Decision;

use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;

/**
 * One decision to evaluate: which profile, about what, on which
 * configuration, on whose behalf (ADR-211).
 *
 * `$configuration` names the LlmConfiguration whose model answers — a
 * decision model, or a chat model that answers through structured output.
 * Without it the extension setting `decision.configuration` applies; the
 * default chat configuration is never used. `$beUserUid` is the budget and
 * attribution subject; left null, the logged-in backend user is used, as by
 * the completion service. The caller source names the calling extension and
 * operation in telemetry and usage (ADR-177).
 *
 * @api
 */
final readonly class DecisionRequest
{
    public function __construct(
        public string $profile,
        public DecisionSubject $subject,
        public ?string $configuration = null,
        public ?int $beUserUid = null,
        public string $callerSourceExtension = '',
        public string $callerSourceOperation = '',
    ) {}

    public function withBeUserUid(int $beUserUid): self
    {
        return new self($this->profile, $this->subject, $this->configuration, $beUserUid, $this->callerSourceExtension, $this->callerSourceOperation);
    }
}
