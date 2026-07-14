<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use LogicException;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetProviderInterface;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSetRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GoldenPromptSetRegistry::class)]
final class GoldenPromptSetRegistryTest extends TestCase
{
    private function set(string $identifier): GoldenPromptSet
    {
        return new GoldenPromptSet(
            $identifier,
            'Name',
            'Description',
            [new GoldenPrompt('p1', 'Say hi.', [Assertion::contains('hi')])],
        );
    }

    private function provider(GoldenPromptSet ...$sets): GoldenPromptSetProviderInterface
    {
        return new class (array_values($sets)) implements GoldenPromptSetProviderInterface {
            /**
             * @param list<GoldenPromptSet> $sets
             */
            public function __construct(private readonly array $sets) {}

            public function getGoldenPromptSets(): array
            {
                return $this->sets;
            }
        };
    }

    #[Test]
    public function collectsSetsFromAllProviders(): void
    {
        $registry = new GoldenPromptSetRegistry([
            $this->provider($this->set('a.one')),
            $this->provider($this->set('b.two')),
        ]);

        self::assertCount(2, $registry->all());
        self::assertSame(['a.one', 'b.two'], $registry->identifiers());
        self::assertNotNull($registry->findByIdentifier('a.one'));
        self::assertNull($registry->findByIdentifier('missing'));
    }

    #[Test]
    public function duplicateIdentifierFailsFast(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionCode(1794000030);
        new GoldenPromptSetRegistry([
            $this->provider($this->set('a.one')),
            $this->provider($this->set('a.one')),
        ]);
    }
}
