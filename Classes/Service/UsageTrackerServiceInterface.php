<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use DateTimeInterface;

/**
 * Interface for usage tracking services.
 *
 * Allows for mocking in tests while keeping UsageTrackerService final.
 */
interface UsageTrackerServiceInterface
{
    /**
     * Track service usage with daily aggregation.
     *
     * @param string $serviceType The service type (translation, speech, image)
     * @param string $provider    The provider name (deepl, whisper, dall-e, etc.)
     * @param array{
     *     tokens?: int,
     *     characters?: int,
     *     audioSeconds?: int,
     *     images?: int,
     *     cost?: float,
     * } $metrics Usage metrics to track
     * @param int|null $configurationUid Optional LlmConfiguration UID
     */
    public function trackUsage(
        string $serviceType,
        string $provider,
        array $metrics = [],
        ?int $configurationUid = null,
    ): void;

    /**
     * Get usage report for a service type within a date range.
     *
     * @param string            $serviceType The service type to report on
     * @param DateTimeInterface $from        Start date
     * @param DateTimeInterface $to          End date
     *
     * @return array<int, array{
     *     service_provider: string,
     *     total_requests: int,
     *     total_tokens: int,
     *     total_characters: int,
     *     total_audio_seconds: int,
     *     total_images: int,
     *     total_cost: float,
     * }>
     */
    public function getUsageReport(
        string $serviceType,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array;

    /**
     * Get usage for a specific backend user.
     *
     * @param int               $beUserUid Backend user UID
     * @param DateTimeInterface $from      Start date
     * @param DateTimeInterface $to        End date
     *
     * @return array<int, array{
     *     service_type: string,
     *     service_provider: string,
     *     total_requests: int,
     *     total_cost: float,
     * }>
     */
    public function getUserUsage(
        int $beUserUid,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array;

    /**
     * Get today's usage for a specific service and user.
     *
     * @return array{
     *     request_count: int,
     *     tokens_used: int,
     *     characters_used: int,
     *     audio_seconds_used: int,
     *     images_generated: int,
     *     estimated_cost: float,
     * }|null
     */
    public function getTodayUsage(string $serviceType, string $provider): ?array;

    /**
     * Get total estimated cost for current month.
     */
    public function getCurrentMonthCost(): float;
}
