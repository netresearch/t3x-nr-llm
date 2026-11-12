<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Integration\Autotranslate;

use B13\AiLabel\Service\AiLabelApi;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ThieleUndKlose\Autotranslate\Command\BatchTranslation;

/**
 * Registers the EXT:autotranslate integration (ADR-289) when autotranslate is
 * an active extension, and its AI Label part when b13/ai-label is one too.
 *
 * All active packages' definitions have been loaded by now. A class existing
 * in Composer does not mean its TYPO3 extension is active, so the check is a
 * service each extension registers.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class AutotranslateCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(BatchTranslation::class)) {
            return;
        }

        $container
            ->register(AutotranslateSettings::class, AutotranslateSettings::class)
            ->setAutowired(true);

        // autotranslate resolves the class named in its translatorClass
        // setting from the container, so the service must be public.
        $container
            ->register(AutotranslateTranslator::class, AutotranslateTranslator::class)
            ->setAutowired(true)
            ->setPublic(true);

        $container
            ->register(MarkAutotranslatedRecord::class, MarkAutotranslatedRecord::class)
            ->setAutowired(true)
            ->setAutoconfigured(true);

        if ($container->has(AiLabelApi::class)) {
            $container
                ->register(LabelAutotranslatedRecord::class, LabelAutotranslatedRecord::class)
                ->setAutowired(true)
                ->setAutoconfigured(true);
        }
    }
}
