<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Integration\Autotranslate;

use B13\AiLabel\Service\AiLabelApi;
use Netresearch\NrLlm\Integration\Autotranslate\AutotranslateCompilerPass;
use Netresearch\NrLlm\Integration\Autotranslate\AutotranslateSettings;
use Netresearch\NrLlm\Integration\Autotranslate\AutotranslateTranslator;
use Netresearch\NrLlm\Integration\Autotranslate\LabelAutotranslatedRecord;
use Netresearch\NrLlm\Integration\Autotranslate\MarkAutotranslatedRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ThieleUndKlose\Autotranslate\Command\BatchTranslation;

#[CoversClass(AutotranslateCompilerPass::class)]
final class AutotranslateCompilerPassTest extends TestCase
{
    #[Test]
    public function installedClassesAloneDoNotActivateTheIntegration(): void
    {
        self::assertTrue(class_exists(BatchTranslation::class), 'Development dependency must be installed.');
        $container = new ContainerBuilder();

        (new AutotranslateCompilerPass())->process($container);

        self::assertFalse($container->has(AutotranslateTranslator::class));
        self::assertFalse($container->has(MarkAutotranslatedRecord::class));
        self::assertFalse($container->has(AutotranslateSettings::class));
    }

    #[Test]
    public function anActiveAutotranslateRegistersAPublicTranslatorAndTheMarker(): void
    {
        $container = new ContainerBuilder();
        $container->register(BatchTranslation::class, BatchTranslation::class);

        (new AutotranslateCompilerPass())->process($container);

        $translator = $container->getDefinition(AutotranslateTranslator::class);
        self::assertTrue($translator->isPublic(), 'autotranslate resolves the class from the container');
        self::assertTrue($translator->isAutowired());
        self::assertTrue($container->getDefinition(AutotranslateSettings::class)->isAutowired());
        $marker = $container->getDefinition(MarkAutotranslatedRecord::class);
        self::assertTrue($marker->isAutowired());
        self::assertTrue($marker->isAutoconfigured(), 'The listener attribute is read through autoconfiguration');
        self::assertFalse($container->has(LabelAutotranslatedRecord::class), 'No AI Label without AI Label');
    }

    #[Test]
    public function anActiveAiLabelAddsTheLabel(): void
    {
        $container = new ContainerBuilder();
        $container->register(BatchTranslation::class, BatchTranslation::class);
        $container->register(AiLabelApi::class, AiLabelApi::class);

        (new AutotranslateCompilerPass())->process($container);

        $label = $container->getDefinition(LabelAutotranslatedRecord::class);
        self::assertTrue($label->isAutowired());
        self::assertTrue($label->isAutoconfigured());
    }

    #[Test]
    public function aiLabelAloneRegistersNothing(): void
    {
        $container = new ContainerBuilder();
        $container->register(AiLabelApi::class, AiLabelApi::class);

        (new AutotranslateCompilerPass())->process($container);

        self::assertFalse($container->has(LabelAutotranslatedRecord::class));
    }
}
