<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\InjectedContext;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Domain\ValueObject\RequestFacts;

/**
 * What a configuration-driven call carries into the middleware pipeline
 * beyond its configuration, operation and metadata.
 *
 * - `$run` — the agent run driving the call (ADR-153); null outside a run.
 * - `$injectedContext` — sources the run injects on top of the configuration
 *   (ADR-164); the ADR-144 ceiling binds against them too.
 * - `$facts` — pre-routing request facts, recorded before a model is chosen
 *   (ADR-174).
 * - `$resolution` — a routing decision the caller already took for this
 *   configuration (#922): the input-context gate reads its model instead of
 *   routing a second time.
 *
 * @internal
 */
final readonly class PipelineScope
{
    public function __construct(
        public ?AgentRunReference $run = null,
        public ?InjectedContext $injectedContext = null,
        public ?RequestFacts $facts = null,
        public ?ModelResolution $resolution = null,
    ) {}
}
