<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\UseCase;

use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Governance\GovernanceProfile;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\UseCase\PackSnippet;
use Netresearch\NrLlm\Service\UseCase\PackTask;
use Netresearch\NrLlm\Service\UseCase\UseCase;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UseCasePack::class)]
final class UseCasePackTest extends TestCase
{
    private function preset(): ConfigurationPreset
    {
        return new ConfigurationPreset(
            identifier: 'nr_llm.fixture',
            name: 'Fixture',
            description: '',
            criteria: new ModelSelectionCriteria(capabilities: ['chat', 'vision']),
        );
    }

    private function task(string $identifier): PackTask
    {
        return new PackTask(
            identifier: $identifier,
            name: 'Task ' . $identifier,
            description: '',
            promptTemplate: 'Do something with {{input}}',
        );
    }

    /**
     * @param list<string> $tags
     */
    private function snippet(string $identifier, array $tags = []): PackSnippet
    {
        return new PackSnippet(
            identifier: $identifier,
            name: 'Snippet ' . $identifier,
            description: '',
            snippet: 'Write plainly.',
            tags: $tags,
        );
    }

    /**
     * @param list<PackTask>    $tasks
     * @param list<PackSnippet> $snippets
     * @param list<string>      $toolGroups
     * @param list<string>      $editorActions
     */
    private function pack(
        string $identifier = 'fixture-pack',
        array $tasks = [],
        array $snippets = [],
        array $toolGroups = [],
        array $editorActions = [],
    ): UseCasePack {
        return new UseCasePack(
            identifier: $identifier,
            useCase: UseCase::EDITORIAL,
            name: 'Fixture Pack',
            description: '',
            configurationPreset: $this->preset(),
            recommendedGovernanceProfile: GovernanceProfile::CONTROLLED_CLOUD,
            tasks: $tasks,
            snippets: $snippets,
            recommendedToolGroups: $toolGroups,
            recommendedEditorActions: $editorActions,
        );
    }

    #[Test]
    public function requiredCapabilitiesComeFromTheConfigurationPreset(): void
    {
        self::assertSame(['chat', 'vision'], $this->pack()->getRequiredCapabilities());
    }

    #[Test]
    public function snippetTagsAreDerivedFromTheDeclaredSnippetsAndDeduplicated(): void
    {
        $pack = $this->pack(snippets: [
            $this->snippet('style', ['tone_of_voice', 'audience']),
            $this->snippet('audience', ['audience']),
        ]);

        self::assertSame(['tone_of_voice', 'audience'], $pack->getSnippetTags());
    }

    #[Test]
    public function aPackWithoutSnippetsDerivesNoTags(): void
    {
        self::assertSame([], $this->pack(tasks: [$this->task('one')])->getSnippetTags());
    }

    #[Test]
    public function anInvalidIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460021);

        $this->pack('Editorial_Starter');
    }

    #[Test]
    public function aDuplicateTaskIdentifierIsRefused(): void
    {
        // Without this the second task would be skipped on install as
        // "already there" — installed by the record the first one just created.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460023);

        $this->pack(tasks: [$this->task('same'), $this->task('same')]);
    }

    #[Test]
    public function aDuplicateSnippetIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460024);

        $this->pack(snippets: [$this->snippet('same'), $this->snippet('same')]);
    }

    #[Test]
    public function aBlankRecommendedEditorActionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460026);

        $pack = $this->pack(editorActions: ['']);
        self::fail('Expected the blank editor action to be refused, got ' . implode(',', $pack->recommendedEditorActions));
    }

    #[Test]
    public function aDuplicateRecommendedEditorActionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460027);

        $pack = $this->pack(editorActions: ['set_file_alternative_text', 'set_file_alternative_text']);
        self::fail('Expected the duplicate editor action to be refused, got ' . implode(',', $pack->recommendedEditorActions));
    }

    #[Test]
    public function theBlankAndDuplicateCheckStopsAtTheNewFieldAndLeavesToolGroupsAlone(): void
    {
        // Deliberate asymmetry, not an oversight (ADR-168).
        // `recommendedEditorActions` is new, so it ships with its contract.
        // `recommendedToolGroups` is pre-existing and reached through the `@api`
        // provider interface: a third-party pack may already declare a blank or
        // repeated group, and every pack is built inside UseCasePackRegistry's
        // constructor, which catches nothing. Refusing it here would turn a
        // cosmetic defect in one foreign pack into a fatal in every backend
        // module that injects the registry. What it costs: an empty badge and a
        // row printed twice on the plan screen.
        $pack = $this->pack(toolGroups: ['content', '  ', 'content']);

        self::assertSame(['content', '  ', 'content'], $pack->recommendedToolGroups);
    }

    #[Test]
    public function anUnknownIdentifierIsDeliberatelyNotRefusedAtDeclarationTime(): void
    {
        // Both sets are OPEN: a third-party extension ships its own tools, its
        // own tool group and — through the same `@api` provider interface — its
        // own pack. Throwing here would fire inside UseCasePackRegistry's
        // constructor and take down every module that injects it, so "unknown
        // to this installation" is the install plan's answer instead, against
        // the live registry (ADR-168).
        //
        // What this therefore does NOT catch: a typo in a shipped pack. That is
        // covered by UseCasePackRenderTest, which asks the real registry.
        $pack = $this->pack(toolGroups: ['contnet'], editorActions: ['set_file_alternativ_text']);

        self::assertSame(['contnet'], $pack->recommendedToolGroups);
        self::assertSame(['set_file_alternativ_text'], $pack->recommendedEditorActions);
    }

    #[Test]
    public function derivedSnippetTagsBeyondTheColumnLimitAreRefused(): void
    {
        $snippets = [];
        for ($i = 0; $i < 30; $i++) {
            $snippets[] = $this->snippet('snippet-' . $i, ['tag-' . str_repeat((string)$i, 9)]);
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1791460025);

        $this->pack(snippets: $snippets);
    }
}
