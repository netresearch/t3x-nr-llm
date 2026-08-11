<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;

/**
 * Writes the liveness columns of `tx_nrllm_mcp_server` (ADR-154).
 *
 * One UPDATE of two columns, and the entire reason this class exists rather
 * than four lines in {@see McpClient}: the write sits on the tool-call path,
 * behind a call that has already succeeded, so it must never be able to turn a
 * working tool call into a failed one. Every failure — a locked table, a
 * column an install has not migrated yet, a connection that went away — is
 * swallowed and logged. A lost observation costs an operator a stale
 * timestamp; a thrown exception would cost them the answer they were waiting
 * for.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class McpHealthRecorder implements McpHealthRecorderInterface
{
    public function __construct(
        private McpServerRepository $servers,
        private Context $context,
        private LoggerInterface $logger,
    ) {}

    public function recordContact(McpServerRecord $server, int $latencyMs): void
    {
        // A record that never came out of the table has no row to update. The
        // fixtures and the connection test both build records by hand.
        if ($server->uid <= 0) {
            return;
        }

        try {
            $this->servers->recordSuccessfulContact($server->uid, $this->now(), max(0, $latencyMs));
        } catch (Throwable $e) {
            $this->logger->warning('An MCP liveness observation could not be stored', [
                'server'    => $server->identifier,
                'exception' => $e,
            ]);
        }
    }

    /**
     * The request's pinned timestamp, as {@see McpImportService} uses for the
     * import stamp, so the two dates in the module are on the same clock.
     */
    private function now(): int
    {
        $timestamp = $this->context->getPropertyFromAspect('date', 'timestamp');

        return is_int($timestamp) && $timestamp > 0 ? $timestamp : time();
    }
}
