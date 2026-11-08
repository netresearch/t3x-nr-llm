<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\DependencyInjection;

use B13\AiLabel\Service\AiLabelApi;
use Netresearch\NrLlm\Service\Tool\AiLabel\AiLabelCompilerPass;
use Netresearch\NrLlm\Service\Tool\AiLabel\RecordAiLabelWrite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(AiLabelCompilerPass::class)]
final class AiLabelCompilerPassTest extends TestCase
{
    #[Test]
    public function installedClassesAloneDoNotActivateTheBridge(): void
    {
        self::assertTrue(
            class_exists(AiLabelApi::class),
            'Development dependency must be installed.',
        );
        $container = new ContainerBuilder();
        (new AiLabelCompilerPass())->process($container);
        self::assertFalse($container->has(RecordAiLabelWrite::class));
    }

    #[Test]
    public function anActiveApiRegistersAnAutowiredListener(): void
    {
        $container = new ContainerBuilder();
        $container->register(AiLabelApi::class, AiLabelApi::class);
        (new AiLabelCompilerPass())->process($container);
        $listener = $container->getDefinition(RecordAiLabelWrite::class);
        self::assertTrue($listener->isAutowired());
        self::assertTrue($listener->isAutoconfigured());
    }
}
