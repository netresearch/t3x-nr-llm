<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A domain handler of the server role (spec 051 A2A-SV4, ADR-283): it turns
 * the client's message into the run's request and the run's answer into the
 * task's artifacts. A publication profile names one by its identifier.
 *
 * Register a handler as a service implementing this interface; it is tagged
 * `nr_llm.a2a_domain_handler` automatically.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AutoconfigureTag(name: self::TAG)]
interface A2aDomainHandlerInterface
{
    public const TAG = 'nr_llm.a2a_domain_handler';

    /**
     * The identifier a profile record names, unique per installation.
     */
    public function identifier(): string;

    /**
     * Whether the handler may be chosen in this installation now.
     */
    public function isAvailable(): bool;

    /**
     * What becomes of the message: a run, or a refusal before the run starts.
     * It runs in the request and must not call a model or a tool.
     */
    public function decide(A2aMessage $message, A2aSkillProfile $profile, A2aRunContext $context): A2aHandlerDecision;

    /**
     * The task's artifacts from the answer as it is kept (the redacted text
     * at a `redacted` answer level), A2A JSON.
     *
     * @return list<array<string, mixed>>
     */
    public function artifacts(string $answer): array;
}
