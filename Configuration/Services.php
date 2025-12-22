<?php

declare(strict_types=1);

use Netresearch\NrLlm\DependencyInjection\ProviderCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    $containerBuilder->addCompilerPass(new ProviderCompilerPass());
};
