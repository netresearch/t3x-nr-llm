<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrLlm\DependencyInjection\ProviderCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    $containerBuilder->addCompilerPass(new ProviderCompilerPass());

    // Dashboard widgets ship only when typo3/cms-dashboard is installed.
    // Guarding here keeps TYPO3 installs without dashboard from blowing up
    // on unresolvable class references during container compile.
    if (class_exists(WidgetInterface::class)) {
        $containerConfigurator->import('Services.Dashboard.yaml');
    }
};
