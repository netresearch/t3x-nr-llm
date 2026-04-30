<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;

/**
 * Public surface of the provider-adapter registry.
 *
 * Consumers (controllers, the manager, tests) should depend on this
 * interface rather than the concrete `ProviderAdapterRegistry` so the
 * implementation can be substituted without inheritance.
 *
 * The contract is **read-only**: there is no public mutator. The set
 * of adapter classes is fixed at construction time (built-in map +
 * optional constructor-injected overrides) — see audit 2026-04-23
 * REC #3 ("lock the registry, no public mutator"). Adding a new
 * built-in adapter type means adding a case to {@see \Netresearch\NrLlm\Domain\Model\AdapterType}
 * and an entry to `ProviderAdapterRegistry::ADAPTER_CLASS_MAP`.
 */
interface ProviderAdapterRegistryInterface
{
    /**
     * Get an adapter class for the given adapter type.
     *
     * Falls back to OpenAI-compatible for unknown types.
     *
     * @return class-string<AbstractProvider>
     */
    public function getAdapterClass(string $adapterType): string;

    /**
     * Check if an adapter type is supported (built-in or override).
     */
    public function hasAdapter(string $adapterType): bool;

    /**
     * Get all registered adapter types.
     *
     * @return array<string, string> Adapter type to human-readable name
     */
    public function getRegisteredAdapters(): array;

    /**
     * Create a configured adapter instance from a Provider entity.
     *
     * @param bool $useCache Whether to reuse cached instances for persisted providers
     */
    public function createAdapterFromProvider(Provider $provider, bool $useCache = true): ProviderInterface;

    /**
     * Create a configured adapter instance from a Model entity.
     *
     * Convenience wrapper that extracts the provider and overrides the
     * adapter's default model with the model's `modelId`.
     *
     * @param bool $useCache Whether to reuse cached instances for persisted providers
     *
     * @throws ProviderConfigurationException when the model has no associated provider
     */
    public function createAdapterFromModel(Model $model, bool $useCache = true): ProviderInterface;

    /**
     * Clear the adapter cache.
     *
     * Pass a `$providerUid` to evict only that provider's cached adapter,
     * or `null` to clear the entire cache.
     */
    public function clearCache(?int $providerUid = null): void;

    /**
     * Test a provider connection.
     *
     * Sanitises any secrets that might appear in upstream error messages
     * before returning them.
     *
     * @return array{success: bool, message: string, models?: array<string, string>}
     */
    public function testProviderConnection(Provider $provider): array;
}
