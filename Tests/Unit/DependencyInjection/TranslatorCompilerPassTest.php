<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\DependencyInjection;

use Netresearch\NrLlm\Attribute\AsTranslator;
use Netresearch\NrLlm\DependencyInjection\TranslatorCompilerPass;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\DependencyInjection\Fixture\AttributeTaggedTranslator;
use Netresearch\NrLlm\Tests\Unit\DependencyInjection\Fixture\UntaggedService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(TranslatorCompilerPass::class)]
class TranslatorCompilerPassTest extends AbstractUnitTestCase
{
    /**
     * Tests use a scan prefix that matches the test fixture namespace,
     * since real translators sit under `Netresearch\NrLlm\Specialized\Translation\`
     * and PSR-4 forbids placing test fixtures under that production prefix.
     */
    private const TEST_SCAN_PREFIX = 'Netresearch\\NrLlm\\Tests\\Unit\\DependencyInjection\\Fixture\\';

    #[Test]
    public function tagsAttributeMarkedTranslator(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(AttributeTaggedTranslator::class);
        $container->setDefinition('translator.attribute', $definition);

        (new TranslatorCompilerPass(self::TEST_SCAN_PREFIX))->process($container);

        self::assertTrue(
            $definition->hasTag(AsTranslator::TAG_NAME),
            'Attribute-marked translator should be auto-tagged',
        );
    }

    #[Test]
    public function leavesAlreadyTaggedTranslatorAlone(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(AttributeTaggedTranslator::class);
        $definition->addTag(AsTranslator::TAG_NAME);
        $container->setDefinition('translator.preexisting', $definition);

        (new TranslatorCompilerPass(self::TEST_SCAN_PREFIX))->process($container);

        // The pass guards on `hasTag()` and skips early; the tag list
        // should still contain exactly one entry, not be doubled up.
        self::assertCount(
            1,
            $definition->getTag(AsTranslator::TAG_NAME),
            'Pre-existing tag must not be duplicated',
        );
    }

    #[Test]
    public function ignoresServicesOutsideScanNamespace(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(UntaggedService::class);
        $container->setDefinition('out.of.scope', $definition);

        // Use the default prefix so the test fixture class falls outside scope.
        (new TranslatorCompilerPass())->process($container);

        self::assertFalse(
            $definition->hasTag(AsTranslator::TAG_NAME),
            'Service outside scan prefix must not be tagged',
        );
    }

    #[Test]
    public function ignoresInScopeServicesWithoutTheAttribute(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(UntaggedService::class);
        $container->setDefinition('translator.unmarked', $definition);

        (new TranslatorCompilerPass(self::TEST_SCAN_PREFIX))->process($container);

        self::assertFalse(
            $definition->hasTag(AsTranslator::TAG_NAME),
            'In-scope service without #[AsTranslator] must stay untagged',
        );
    }

    #[Test]
    public function leavesServiceVisibilityUnchanged(): void
    {
        $container = new ContainerBuilder();
        $definition = new Definition(AttributeTaggedTranslator::class);
        $definition->setPublic(false);
        $container->setDefinition('translator.private', $definition);

        (new TranslatorCompilerPass(self::TEST_SCAN_PREFIX))->process($container);

        // The pass deliberately does NOT call setPublic() — TranslatorRegistry
        // consumes the tagged iterator and never resolves translators by
        // class name, so attribute-marked services stay private.
        self::assertFalse(
            $definition->isPublic(),
            'Compiler pass must not flip visibility on attribute-marked services',
        );
    }
}
