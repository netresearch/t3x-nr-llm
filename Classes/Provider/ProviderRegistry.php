<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider;

use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;

final readonly class ProviderRegistry
{
    public function __construct(
        private LlmServiceManagerInterface $serviceManager,
    ) {}

    public function register(ProviderInterface $provider): void
    {
        $this->serviceManager->registerProvider($provider);
    }
}
