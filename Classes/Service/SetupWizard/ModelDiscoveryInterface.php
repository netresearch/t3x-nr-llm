<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;

/**
 * Interface for model discovery from LLM providers.
 */
interface ModelDiscoveryInterface
{
    /**
     * Test connection to provider.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(DetectedProvider $provider, string $apiKey): array;

    /**
     * Discover models from provider.
     *
     * @return array<DiscoveredModel>
     */
    public function discover(DetectedProvider $provider, string $apiKey): array;
}
