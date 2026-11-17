<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Runtime;

use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;

/**
 * The card of a remote agent record as stored now, against the record object
 * a send would use (spec 051 A2A-R8, spec 060 RQ2). Shared by every path that
 * sends a message to a remote agent: the record object may come from an
 * identity map that is older than the row.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aRemoteAgentCard
{
    /**
     * Why nothing may be sent, or null: a card waiting for confirmation, or a
     * card an import changed since the record object was read.
     *
     * @param array{cardUrl: string, interfaceUrl: string, interfaceTenant: string, cardChanged: bool, confirmedInterface: string} $card
     */
    public static function refusal(array $card, A2aRemoteAgent $agent): ?string
    {
        if ($card['cardChanged']) {
            return 'the card changed and waits for an administrator to confirm it';
        }

        $read = [$agent->getCardUrl(), $agent->getInterfaceUrl(), $agent->getInterfaceTenant(), $agent->getConfirmedInterface()];
        if ($read !== [$card['cardUrl'], $card['interfaceUrl'], $card['interfaceTenant'], $card['confirmedInterface']]) {
            return 'the card changed since this worker read the record; nothing was sent';
        }

        return null;
    }

    /**
     * Where the record's remote agent answers, as one digest of the stored
     * card: its URL, the interface, the tenant and the confirmed interface.
     *
     * @param array{cardUrl: string, interfaceUrl: string, interfaceTenant: string, cardChanged: bool, confirmedInterface: string} $card
     */
    public static function endpoint(array $card): string
    {
        return self::digest($card['cardUrl'], $card['interfaceUrl'], $card['interfaceTenant'], $card['confirmedInterface']);
    }

    /**
     * The same digest of the record object a call just used, so a question
     * is bound to the endpoint that asked it, not to a row read afterwards.
     */
    public static function endpointOf(A2aRemoteAgent $agent): string
    {
        return self::digest($agent->getCardUrl(), $agent->getInterfaceUrl(), $agent->getInterfaceTenant(), $agent->getConfirmedInterface());
    }

    private static function digest(string ...$parts): string
    {
        return hash('sha256', implode("\0", $parts));
    }
}
