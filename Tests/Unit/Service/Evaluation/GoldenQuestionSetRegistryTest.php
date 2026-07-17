<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use LogicException;
use Netresearch\NrLlm\Domain\Enum\QuestionForm;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestion;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSet;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSetProviderInterface;
use Netresearch\NrLlm\Service\Evaluation\GoldenQuestionSetRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GoldenQuestionSetRegistry::class)]
final class GoldenQuestionSetRegistryTest extends TestCase
{
    private function set(string $identifier): GoldenQuestionSet
    {
        return new GoldenQuestionSet(
            $identifier,
            'Name',
            'Description',
            [new GoldenQuestion('q1', 'Where is the office?', QuestionForm::MATCH, ['doc-1'])],
        );
    }

    private function provider(GoldenQuestionSet ...$sets): GoldenQuestionSetProviderInterface
    {
        return new class (array_values($sets)) implements GoldenQuestionSetProviderInterface {
            /**
             * @param list<GoldenQuestionSet> $sets
             */
            public function __construct(private readonly array $sets) {}

            public function getGoldenQuestionSets(): array
            {
                return $this->sets;
            }
        };
    }

    #[Test]
    public function collectsSetsFromAllProviders(): void
    {
        $registry = new GoldenQuestionSetRegistry([
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
        $this->expectExceptionCode(1794000059);
        self::assertInstanceOf(GoldenQuestionSetRegistry::class, new GoldenQuestionSetRegistry([
            $this->provider($this->set('a.one')),
            $this->provider($this->set('a.one')),
        ]));
    }
}
