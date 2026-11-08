<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\AiLabel;

use B13\AiLabel\Service\AiLabelApi;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class AiLabelCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // All active packages' definitions have been loaded by now. A class
        // existing in Composer does not mean its TYPO3 extension is active.
        if (!$container->has(AiLabelApi::class)) {
            return;
        }

        $container
            ->register(RecordAiLabelWrite::class, RecordAiLabelWrite::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);
    }
}
