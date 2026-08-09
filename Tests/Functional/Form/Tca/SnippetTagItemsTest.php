<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Form\Tca;

use Netresearch\NrLlm\Form\Tca\SnippetTagItems;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The `snippet_tags` select lists the tags actually carried by snippet records
 * (#638) — the vocabulary is consumer-owned (ADR-031), so it cannot come from
 * an enum.
 *
 * Constructed the way FormEngine constructs it: no arguments. The class must
 * stay constructor-less, because `GeneralUtility::callUserFunction()` resolves
 * an itemsProcFunc through `makeInstance()` without arguments and the class is
 * a private DI service.
 */
#[CoversClass(SnippetTagItems::class)]
final class SnippetTagItemsTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('PromptSnippets.csv');
    }

    #[Test]
    public function everyTagCarriedByASnippetIsOfferedOnceAndNormalized(): void
    {
        self::assertSame(
            ['audience', 'image', 'layout', 'lifestyle', 'persona', 'style', 'tone_of_voice'],
            $this->values(['items' => []]),
        );
    }

    /**
     * A stored tag no longer carried by any snippet stays selectable, so an
     * editor sees (and can remove) it instead of losing it on the next save.
     */
    #[Test]
    public function aStoredButUnusedTagStaysSelectable(): void
    {
        $values = $this->values(['items' => [], 'row' => ['snippet_tags' => 'Retired_Tag']]);

        self::assertContains('retired_tag', $values);
    }

    /**
     * A stored tag that IS in use must not be offered twice.
     */
    #[Test]
    public function aStoredTagInUseIsNotDuplicated(): void
    {
        $values = $this->values(['items' => [], 'row' => ['snippet_tags' => 'persona']]);

        self::assertCount(1, array_filter($values, static fn(string $v): bool => $v === 'persona'));
    }

    /**
     * @param array{items: array<int, array{label: string, value: string}>, row?: array<string, mixed>} $params
     *
     * @return list<string>
     */
    private function values(array $params): array
    {
        (new SnippetTagItems())->addItems($params);

        return array_values(array_map(
            static fn(array $item): string => $item['value'],
            $params['items'],
        ));
    }
}
