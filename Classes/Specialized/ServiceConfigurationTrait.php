<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized;

/**
 * Shared, fail-soft parsing of the user-editable extension configuration
 * tree for the specialised services. The documented config shape is not a
 * runtime guarantee, so every hop is type-guarded instead of trusted.
 */
trait ServiceConfigurationTrait
{
    /**
     * Walk a nested array path safely. Returns the leaf value or null
     * if any intermediate hop is missing / not an array.
     *
     * @param array<string, mixed> $config
     * @param list<string>         $path
     */
    protected function resolveScalarConfig(array $config, array $path): mixed
    {
        $current = $config;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    /**
     * Standard configuration loader for OpenAI-backed services: vault key
     * identifier from `providers.openai.apiKeyIdentifier`, `baseUrl` and
     * `timeout` from the given service path (e.g. `['speech', 'tts']`).
     *
     * An empty ext_conf baseUrl means "use the provider default" (per the
     * field label), NOT "send requests to an empty URL" — see
     * `nonEmptyStringOrDefault()`.
     *
     * @param array<string, mixed> $config
     * @param list<string>         $servicePath
     */
    protected function loadOpenAiServiceConfiguration(array $config, array $servicePath): void
    {
        $apiKeyIdentifier = $this->resolveScalarConfig($config, ['providers', 'openai', 'apiKeyIdentifier']);
        $baseUrl = $this->resolveScalarConfig($config, [...$servicePath, 'baseUrl']);
        $timeout = $this->resolveScalarConfig($config, [...$servicePath, 'timeout']);

        $this->apiKeyIdentifier = is_string($apiKeyIdentifier) ? $apiKeyIdentifier : '';
        $this->baseUrl = $this->nonEmptyStringOrDefault($baseUrl, $this->getDefaultBaseUrl());
        $this->timeout = is_numeric($timeout) ? (int)$timeout : $this->getDefaultTimeout();
    }
}
