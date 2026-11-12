<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;

/**
 * What a domain handler builds the run's request from: the profile's LLM
 * configuration, the client's technical backend user as the actor, the
 * read-only tools the profile allows and the run's origin.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aRunContext
{
    /**
     * @param list<string> $toolNames
     */
    public function __construct(
        public LlmConfiguration $configuration,
        public AiActorContext $actor,
        public array $toolNames,
        public string $origin,
    ) {}
}
