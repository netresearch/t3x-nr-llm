<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

/**
 * Interface for cache management operations.
 *
 * Extracted from CacheManager to enable testing with mocks.
 */
interface CacheManagerInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function generateCacheKey(string $provider, string $operation, array $params): string;

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $cacheKey): ?array;

    /**
     * @param array<string, mixed> $data
     * @param array<string>        $tags
     */
    public function set(string $cacheKey, array $data, int $lifetime = 3600, array $tags = []): void;

    public function has(string $cacheKey): bool;

    public function remove(string $cacheKey): void;

    public function flush(): void;

    public function flushByTag(string $tag): void;

    public function flushByProvider(string $provider): void;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $options
     * @param array<string, mixed>                             $response
     */
    public function cacheCompletion(
        string $provider,
        array $messages,
        array $options,
        array $response,
        int $lifetime = 3600,
    ): string;

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                             $options
     *
     * @return array<string, mixed>|null
     */
    public function getCachedCompletion(string $provider, array $messages, array $options): ?array;

    /**
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     * @param array<string, mixed>      $response
     */
    public function cacheEmbeddings(
        string $provider,
        string|array $input,
        array $options,
        array $response,
        int $lifetime = 86400,
    ): string;

    /**
     * @param string|array<int, string> $input
     * @param array<string, mixed>      $options
     *
     * @return array<string, mixed>|null
     */
    public function getCachedEmbeddings(string $provider, string|array $input, array $options): ?array;
}
