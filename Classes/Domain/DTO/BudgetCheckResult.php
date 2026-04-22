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
     * Human-friendly labels, keyed by LIMIT_* value. Kept here as a
     * constant (not a locallang lookup) so the DTO stays side-effect-free
     * and callers can render it unchanged in logs / JSON responses, or
     * substitute their own localised string using `exceededLimit` as a
     * stable machine key.
     *
     * @var array<string, string>
     */
    private const LIMIT_LABELS = [
        self::LIMIT_DAILY_REQUESTS => 'daily request count',
        self::LIMIT_DAILY_TOKENS => 'daily token usage',
        self::LIMIT_DAILY_COST => 'daily cost',
        self::LIMIT_MONTHLY_REQUESTS => 'monthly request count',
        self::LIMIT_MONTHLY_TOKENS => 'monthly token usage',
        self::LIMIT_MONTHLY_COST => 'monthly cost',
    ];

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
                'AI budget exhausted: %s is at %s of %s',
                self::LIMIT_LABELS[$exceededLimit] ?? $exceededLimit,
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
