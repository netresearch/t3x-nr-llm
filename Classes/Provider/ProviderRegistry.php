<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;

final class ProviderRegistry
{
    public function __construct(
        private readonly LlmServiceManager $serviceManager,
    ) {}

    public function register(ProviderInterface $provider): void
    {
        $this->serviceManager->registerProvider($provider);
    }
}
