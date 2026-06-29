<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for PromptSnippetRepository.
 *
 * Verifies the tag-token query contract consumed by other extensions
 * (e.g. nr_repurpose): exact case-insensitive token matching and
 * order-preserving uid lookups.
 */
#[CoversClass(PromptSnippetRepository::class)]
final class PromptSnippetRepositoryTest extends AbstractFunctionalTestCase
{
    private PromptSnippetRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('PromptSnippets.csv');

        $repository = $this->get(PromptSnippetRepository::class);
        self::assertInstanceOf(PromptSnippetRepository::class, $repository);
        $this->repository = $repository;
    }

    /**
     * @param list<PromptSnippet> $snippets
     *
     * @return list<string>
     */
    private function identifiersOf(array $snippets): array
    {
        return array_map(
            static fn(PromptSnippet $snippet): string => $snippet->getIdentifier(),
            $snippets,
        );
    }

    // =========================================================================
    // findActiveByTag(): token matching
    // =========================================================================

    #[Test]
    public function findActiveByTagReturnsOnlyActiveSnippetsWithExactTag(): void
    {
        $snippets = $this->repository->findActiveByTag('tone_of_voice');

        // Inactive (uid 7) and deleted (uid 8) snippets must be excluded
        self::assertSame(
            ['tone-formal', 'tone-casual'],
            $this->identifiersOf($snippets),
        );
    }

    #[Test]
    public function findActiveByTagDoesNotMatchSubstrings(): void
    {
        // 'style' must NOT match the 'lifestyle' tag of layout-lifestyle
        $snippets = $this->repository->findActiveByTag('style');

        self::assertSame(['style-minimalist'], $this->identifiersOf($snippets));
    }

    #[Test]
    public function findActiveByTagDoesNotMatchPartialTokens(): void
    {
        // 'life' is a substring of 'lifestyle' but no exact token
        self::assertSame([], $this->repository->findActiveByTag('life'));
    }

    #[Test]
    public function findActiveByTagIsCaseInsensitive(): void
    {
        $snippets = $this->repository->findActiveByTag('STYLE');

        // Stored tag is ' Style ' (mixed case, padded) — still matches
        self::assertSame(['style-minimalist'], $this->identifiersOf($snippets));
    }

    #[Test]
    public function findActiveByTagTrimsTheRequestedTag(): void
    {
        $snippets = $this->repository->findActiveByTag('  layout  ');

        self::assertSame(['layout-lifestyle'], $this->identifiersOf($snippets));
    }

    #[Test]
    public function findActiveByTagReturnsEmptyListForUnknownTag(): void
    {
        self::assertSame([], $this->repository->findActiveByTag('unknown-tag'));
    }

    #[Test]
    public function findActiveByTagReturnsEmptyListForEmptyTag(): void
    {
        self::assertSame([], $this->repository->findActiveByTag(''));
        self::assertSame([], $this->repository->findActiveByTag('   '));
    }

    // =========================================================================
    // findActiveByTag(): ordering
    // =========================================================================

    #[Test]
    public function findActiveByTagOrdersBySortingFirst(): void
    {
        $snippets = $this->repository->findActiveByTag('tone_of_voice');

        // tone-formal (sorting 10) comes before tone-casual (sorting 20),
        // although by name 'Casual tone' sorts before 'Formal tone' — proving
        // the query's `sorting ASC, name ASC` ordering puts sorting first.
        // Assert the resulting identifier order (the repository's contract);
        // `sorting` is a TCA `ctrl.sortby` field which Extbase uses for the
        // query ordering but does not hydrate back onto the domain property.
        self::assertSame(
            ['tone-formal', 'tone-casual'],
            $this->identifiersOf($snippets),
        );
    }

    #[Test]
    public function findActiveByTagOrdersByNameWhenSortingIsEqual(): void
    {
        $snippets = $this->repository->findActiveByTag('audience');

        // Both have sorting 30 — 'Developers' before 'Marketers'
        self::assertSame(
            ['audience-developers', 'audience-marketers'],
            $this->identifiersOf($snippets),
        );
    }

    // =========================================================================
    // findByUids()
    // =========================================================================

    #[Test]
    public function findByUidsPreservesInputOrder(): void
    {
        $snippets = $this->repository->findByUids([5, 2, 3]);

        self::assertSame(
            ['style-minimalist', 'tone-formal', 'audience-developers'],
            $this->identifiersOf($snippets),
        );
    }

    #[Test]
    public function findByUidsSilentlySkipsUnknownUids(): void
    {
        $snippets = $this->repository->findByUids([999, 2, 12345]);

        self::assertSame(['tone-formal'], $this->identifiersOf($snippets));
    }

    #[Test]
    public function findByUidsSilentlySkipsInactiveAndDeletedUids(): void
    {
        // uid 7 is inactive, uid 8 is deleted
        $snippets = $this->repository->findByUids([7, 8, 1]);

        self::assertSame(['tone-casual'], $this->identifiersOf($snippets));
    }

    #[Test]
    public function findByUidsReturnsEmptyListForEmptyInput(): void
    {
        self::assertSame([], $this->repository->findByUids([]));
    }

    // =========================================================================
    // Persisted properties
    // =========================================================================

    #[Test]
    public function persistedMetadataDecodesToArray(): void
    {
        $snippets = $this->repository->findByUids([9]);

        self::assertCount(1, $snippets);
        self::assertSame('persona-nova', $snippets[0]->getIdentifier());
        self::assertSame(['voice' => 'nova'], $snippets[0]->getMetadataArray());
    }

    #[Test]
    public function persistedTagsDecodeToNormalizedTagList(): void
    {
        $snippets = $this->repository->findByUids([5]);

        self::assertCount(1, $snippets);
        self::assertSame(['style', 'image'], $snippets[0]->getTagList());
    }
}
