<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHealthRecorderInterface;

/**
 * A health recorder that remembers instead of writing.
 *
 * What a test needs to know about liveness recording is whether it happened,
 * how often and for which server — never that a row changed, which is the
 * functional suite's question.
 */
final class RecordedContacts implements McpHealthRecorderInterface
{
    /** @var list<array{identifier: string, latencyMs: int}> */
    public array $contacts = [];

    public function recordContact(McpServerRecord $server, int $latencyMs): void
    {
        $this->contacts[] = ['identifier' => $server->identifier, 'latencyMs' => $latencyMs];
    }
}
