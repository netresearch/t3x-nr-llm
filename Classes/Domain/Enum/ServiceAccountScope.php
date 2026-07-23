<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * A capability a non-interactive service account is permitted (ADR-110).
 *
 * A backend user is authorised by ownership and admin rights; a service account
 * (CLI, scheduler task, queue worker — {@see \Netresearch\NrLlm\Domain\ValueObject\AiActorContext::serviceAccount()})
 * owns nothing and has no backend privileges, so it would otherwise have to be
 * trusted for everything. Instead it carries an explicit, minimal set of scopes,
 * and every entry point a service account can reach checks the ONE scope that
 * entry point requires. Fail-closed: a service account declaring no scopes may
 * do nothing, so a narrow automation (a nightly cancel sweep) can never be
 * escalated into full access by guessing a uuid or session id.
 *
 * Each case maps to exactly one enforcement point — there is no wildcard scope,
 * so a new automation must name precisely what it needs.
 */
enum ServiceAccountScope: string
{
    /**
     * Approve or submit input to a run suspended for a human decision
     * ({@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface::approve()},
     * {@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface::submitInput()}).
     */
    case AGENT_APPROVE = 'agent:approve';

    /**
     * Cancel a queued, running or suspended run
     * ({@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface::cancel()}).
     */
    case AGENT_CANCEL = 'agent:cancel';

    /**
     * Read a run's status and event stream
     * ({@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface::status()},
     * {@see \Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface::events()}).
     */
    case AGENT_READ = 'agent:read';

    /**
     * Read and continue a conversation session on the system's behalf
     * ({@see \Netresearch\NrLlm\Service\Feature\ConversationService::send()}).
     */
    case CONVERSATION_ACCESS = 'conversation:access';

    /**
     * Use an LLM configuration that is restricted to specific backend groups
     * ({@see \Netresearch\NrLlm\Service\ConfigurationResolver}).
     */
    case CONFIGURATION_USE = 'configuration:use';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $c): string => $c->value, self::cases());
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
