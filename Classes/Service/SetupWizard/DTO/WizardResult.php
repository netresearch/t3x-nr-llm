<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\DTO;

/**
 * Complete result from the setup wizard.
 */
final readonly class WizardResult
{
    /**
     * @param DetectedProvider              $provider             Detected/configured provider
     * @param array<DiscoveredModel>        $models               Discovered or suggested models
     * @param array<SuggestedConfiguration> $configurations       Suggested configurations
     * @param bool                          $connectionSuccessful Whether connection test passed
     * @param string                        $connectionMessage    Message from connection test
     */
    public function __construct(
        public DetectedProvider $provider,
        public array $models = [],
        public array $configurations = [],
        public bool $connectionSuccessful = false,
        public string $connectionMessage = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->toArray(),
            'models' => array_map(fn(DiscoveredModel $m) => $m->toArray(), $this->models),
            'configurations' => array_map(fn(SuggestedConfiguration $c) => $c->toArray(), $this->configurations),
            'connectionSuccessful' => $this->connectionSuccessful,
            'connectionMessage' => $this->connectionMessage,
        ];
    }
}
