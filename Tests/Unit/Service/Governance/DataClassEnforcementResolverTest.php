<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Governance;

use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The full fail-closed matrix of `tools.dataClassEnforcement` (ADR-113),
 * asserted directly on the resolver extracted from ToolCallPolicy (ADR-140).
 */
#[CoversClass(DataClassEnforcementResolver::class)]
final class DataClassEnforcementResolverTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function configurationProvider(): array
    {
        return [
            // Only a literal `observe` observes.
            'exact observe'            => [['tools' => ['dataClassEnforcement' => 'observe']], false],
            'uppercase with trailing space' => [['tools' => ['dataClassEnforcement' => 'OBSERVE ']], false],
            'leading space'            => [['tools' => ['dataClassEnforcement' => ' observe']], false],

            // Everything else enforces.
            'explicit enforce'         => [['tools' => ['dataClassEnforcement' => 'enforce']], true],
            'typo observ'              => [['tools' => ['dataClassEnforcement' => 'observ']], true],
            'empty string'             => [['tools' => ['dataClassEnforcement' => '']], true],
            'not a string (bool)'      => [['tools' => ['dataClassEnforcement' => false]], true],
            'not a string (int)'       => [['tools' => ['dataClassEnforcement' => 0]], true],
            'not a string (array)'     => [['tools' => ['dataClassEnforcement' => ['observe']]], true],
            'key missing'              => [['tools' => []], true],
            'tools not an array'       => [['tools' => 'not-an-array'], true],
            'tools section missing'    => [['privacy' => ['level' => 'full']], true],
            'empty configuration'      => [[], true],
        ];
    }

    #[Test]
    #[DataProvider('configurationProvider')]
    public function onlyALiteralObserveObserves(mixed $configuration, bool $expectedEnforcing): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuration);

        $resolver = new DataClassEnforcementResolver($extensionConfiguration);

        self::assertSame($expectedEnforcing, $resolver->enforcing());
        self::assertSame(
            $expectedEnforcing ? DataClassEnforcementResolver::MODE_ENFORCE : DataClassEnforcementResolver::MODE_OBSERVE,
            $resolver->mode(),
        );
    }

    #[Test]
    public function anUnreadableExtensionConfigurationEnforces(): void
    {
        $throwing = $this->createMock(ExtensionConfiguration::class);
        $throwing->method('get')->willThrowException(new RuntimeException('config unreadable', 1785100001));

        $resolver = new DataClassEnforcementResolver($throwing);

        self::assertTrue($resolver->enforcing());
        self::assertSame(DataClassEnforcementResolver::MODE_ENFORCE, $resolver->mode());
    }

    #[Test]
    public function noExtensionConfigurationAtAllEnforces(): void
    {
        // The pre-extraction ToolCallPolicy carried a nullable dependency and
        // fell closed when it was absent. Moved verbatim, so this stays true.
        $resolver = new DataClassEnforcementResolver();

        self::assertTrue($resolver->enforcing());
        self::assertSame(DataClassEnforcementResolver::MODE_ENFORCE, $resolver->mode());
    }
}
