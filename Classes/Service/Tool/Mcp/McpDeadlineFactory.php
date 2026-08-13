<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Turns operator configuration into one {@see McpOperationDeadline} per MCP
 * operation (ADR-170).
 *
 * The budget is a product decision, not an implementation detail, so it lives
 * in `ext_conf_template.txt` where an operator can see and raise it — rather
 * than in a constant that only a code reader can find. That matters here
 * because the number is a trade: too low and a slow but legitimate server is
 * cut off mid-import, too high and an agent run keeps a backend user waiting.
 * Only the installation knows which of its servers is which.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class McpDeadlineFactory
{
    /**
     * The whole-operation budget, in seconds, when nothing is configured.
     *
     * Composed rather than inherited: 15 seconds is what a single request had
     * before this existed, and the extra 5 pay for the two handshake legs in
     * front of the work. That is a composition, not a floor under the payload
     * leg — there is one budget and the legs spend it in order, so `tools/call`
     * or a catalogue page is granted 20 minus what the handshake elapsed. On a
     * healthy server that is close to the whole 20, more than the leg had
     * before: `initialize` answers out of memory and the readiness notification
     * is answered with a 202. Where the handshake costs more than 5 seconds the
     * payload leg gets less than 15, and a server slow to handshake AND slow to
     * work is newly cut off — accepted, visible in the refusal, and answered by
     * raising the configured budget (ADR-170, decision 5).
     *
     * The worst case an operator can hit falls from about 45 seconds to this
     * number plus the sub-second overrun
     * {@see McpOperationDeadline::legTimeoutSeconds()} admits.
     *
     * It is deliberately NOT the old 15 as a total, which would put the payload
     * leg under 15 on every multi-leg call rather than only on a slow one.
     */
    public const DEFAULT_TOTAL_SECONDS = 20;

    /** The extension configuration key an operator raises. */
    private const CONFIGURATION_KEY = 'mcpOperationTimeout';

    public function __construct(
        private McpClockInterface $clock,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Open the budget for one operation. Every leg of that operation spends it.
     */
    public function forOperation(): McpOperationDeadline
    {
        return McpOperationDeadline::start($this->clock, $this->configuredTotalSeconds());
    }

    /**
     * An empty, non-numeric or non-positive value falls back to the default.
     *
     * There is no upper clamp. A long budget costs the operator who set it the
     * wait they asked for, and an MCP tool that legitimately runs for a minute
     * is a server this extension has no business overruling. Past the
     * installation's own PHP limit that wait is not paid gracefully — a backend
     * tool call runs inside the AJAX request, so the request dies on
     * `max_execution_time` or a gateway timeout instead of returning the failed
     * tool result {@see McpTool::execute()} writes. ADR-170 decision 5 records
     * that consequence and still refuses the clamp.
     */
    private function configuredTotalSeconds(): int
    {
        $raw = trim($this->readString(self::CONFIGURATION_KEY));

        if ($raw !== '' && is_numeric($raw) && (int)$raw > 0) {
            return (int)$raw;
        }

        return self::DEFAULT_TOTAL_SECONDS;
    }

    private function readString(string $key): string
    {
        try {
            $raw = $this->extensionConfiguration->get('nr_llm', $key);
        } catch (Throwable) {
            return '';
        }

        // Values saved through the install tool arrive as strings, but an
        // int-typed template field may come back as int when set
        // programmatically.
        if (\is_int($raw) || \is_float($raw)) {
            return (string)$raw;
        }

        return is_string($raw) ? $raw : '';
    }
}
