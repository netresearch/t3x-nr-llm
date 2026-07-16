<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Overview;

use Netresearch\NrLlm\Service\Overview\ProviderReachabilityService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * The token-free reachability probe behind the overview's provider dots.
 *
 * Uses only providers whose adapter fails fast without any network I/O
 * (an api-key adapter whose vault key cannot be resolved reports
 * "API key may be missing" from isAvailable()), so the "down" verdict is
 * deterministic and the test never leaves the machine. The 60s result
 * cache must short-circuit the second call.
 */
#[CoversClass(ProviderReachabilityService::class)]
final class ProviderReachabilityServiceTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The 60s result cache may outlive a sibling test's run depending on
        // the configured backend — start every test from a cold cache.
        $this->getService(CacheManager::class)->getCache('nrllm_reachability')->flush();
    }

    #[Test]
    public function noConfiguredProvidersYieldsEmptyListAndCachesIt(): void
    {
        $service = $this->getService(ProviderReachabilityService::class);

        self::assertSame(['providers' => []], $service->check());

        // Second call is served from the nrllm_reachability cache.
        $cached = $this->getService(CacheManager::class)
            ->getCache('nrllm_reachability')
            ->get('status');
        self::assertSame(['providers' => []], $cached);
        self::assertSame(['providers' => []], $service->check());
    }

    #[Test]
    public function unresolvableApiKeyProviderReportsDownWithoutNetworkIo(): void
    {
        // openai-test carries a vault UUID that no vault record backs, so the
        // adapter's isAvailable() fails fast — no HTTP request is attempted.
        $this->importFixture('Providers.csv');
        $this->markProviderInactive('ollama-local');

        $service = $this->getService(ProviderReachabilityService::class);
        $result = $service->check();

        self::assertNotSame([], $result['providers']);
        foreach ($result['providers'] as $provider) {
            self::assertSame('down', $provider['status'], $provider['identifier']);
            self::assertNotSame('', $provider['name']);
        }
    }

    /**
     * Deactivate a fixture provider whose adapter would attempt real
     * network I/O (the Ollama adapter needs no API key).
     */
    private function markProviderInactive(string $identifier): void
    {
        $this->getConnection()->update(
            'tx_nrllm_provider',
            ['is_active' => 0],
            ['identifier' => $identifier],
        );
    }
}
