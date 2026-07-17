<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Rerank\Exception;

use Netresearch\NrLlm\Exception\NrLlmExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * Reranker sidecar failure: unreachable endpoint, non-200 status, or a
 * response outside the protocol shape (ADR-075). Consumers own the
 * degradation policy — e.g. fall back to the pre-rerank ordering.
 */
final class RerankerException extends RuntimeException implements NrLlmExceptionInterface
{
    public static function forTransportFailure(string $endpoint, Throwable $previous): self
    {
        return new self(sprintf('Reranker request to "%s" failed: %s', $endpoint, $previous->getMessage()), 1784750001, $previous);
    }

    public static function forStatus(string $endpoint, int $status): self
    {
        return new self(sprintf('Reranker at "%s" returned HTTP %d', $endpoint, $status), 1784750002);
    }

    public static function forInvalidJson(string $endpoint, Throwable $previous): self
    {
        return new self(sprintf('Reranker response from "%s" is not valid JSON', $endpoint), 1784750003, $previous);
    }

    public static function forMissingScores(string $endpoint): self
    {
        return new self(sprintf('Reranker response from "%s" is missing a "scores" array', $endpoint), 1784750004);
    }
}
