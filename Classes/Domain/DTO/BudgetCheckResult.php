<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\DTO;

/**
 * Result of a BudgetService::check() call.
 *
 * When allowed() is false, the remaining fields describe which bucket
 * was tripped so the caller can surface a meaningful message without
 * re-computing anything.
 */
final readonly class BudgetCheckResult
{
    public const LIMIT_NONE = '';
    public const LIMIT_DAILY_REQUESTS = 'daily_requests';
    public const LIMIT_DAILY_TOKENS = 'daily_tokens';
    public const LIMIT_DAILY_COST = 'daily_cost';
    public const LIMIT_MONTHLY_REQUESTS = 'monthly_requests';
    public const LIMIT_MONTHLY_TOKENS = 'monthly_tokens';
    public const LIMIT_MONTHLY_COST = 'monthly_cost';

    /**
     * @param non-empty-string|self::LIMIT_* $exceededLimit
     */
    public function __construct(
        public bool $allowed,
        public string $exceededLimit = self::LIMIT_NONE,
        public float $currentUsage = 0.0,
        public float $limit = 0.0,
        public string $reason = '',
    ) {}

    public static function allowed(): self
    {
        return new self(allowed: true);
    }

    public static function denied(
        string $exceededLimit,
        float $currentUsage,
        float $limit,
        string $reason = '',
    ): self {
        return new self(
            allowed: false,
            exceededLimit: $exceededLimit,
            currentUsage: $currentUsage,
            limit: $limit,
            reason: $reason !== '' ? $reason : sprintf(
                '%s limit reached: %s of %s',
                $exceededLimit,
                self::formatNumber($currentUsage),
                self::formatNumber($limit),
            ),
        );
    }

    private static function formatNumber(float $n): string
    {
        return fmod($n, 1.0) === 0.0 ? (string)(int)$n : number_format($n, 2);
    }
}
