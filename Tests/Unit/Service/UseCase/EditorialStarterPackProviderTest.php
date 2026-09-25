<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\UseCase;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Enum\TaskOutputFormat;
use Netresearch\NrLlm\Service\UseCase\EditorialStarterPackProvider;
use Netresearch\NrLlm\Service\UseCase\PackSnippet;
use Netresearch\NrLlm\Service\UseCase\PackTask;
use Netresearch\NrLlm\Service\UseCase\UseCase;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shipped pack, checked for the properties that make it a real pack rather
 * than a placeholder.
 */
#[CoversClass(EditorialStarterPackProvider::class)]
final class EditorialStarterPackProviderTest extends TestCase
{
    private function pack(): UseCasePack
    {
        $packs = (new EditorialStarterPackProvider())->getPacks();
        self::assertCount(1, $packs);

        return $packs[0];
    }

    #[Test]
    public function declaresTheEditorialStarterPack(): void
    {
        $pack = $this->pack();

        self::assertSame('editorial-starter', $pack->identifier);
        self::assertSame(UseCase::EDITORIAL, $pack->useCase);
        self::assertSame('nr_llm.editorial_starter', $pack->configurationPreset->identifier);
    }

    #[Test]
    public function shipsTasksAndSnippets(): void
    {
        $pack = $this->pack();

        self::assertNotSame([], $pack->tasks);
        self::assertNotSame([], $pack->snippets);
    }

    #[Test]
    public function everyTaskPromptCarriesTheInputPlaceholder(): void
    {
        // `Task::buildPrompt()` substitutes `{{input}}` and nothing else. A
        // prompt without it silently drops the editor's text and asks the model
        // to work on nothing.
        foreach ($this->pack()->tasks as $task) {
            self::assertInstanceOf(PackTask::class, $task);
            self::assertStringContainsString(
                '{{input}}',
                $task->promptTemplate,
                $task->identifier . ' would never receive the editor input.',
            );
        }
    }

    #[Test]
    public function shipsTheEditingTasksTheDocumentationNames(): void
    {
        // The Tasks module, the cowriter dialog and UseCasePacks.rst name these;
        // an installation that already has the pack gets the new ones on the
        // next install, because the installer skips identifiers it knows.
        self::assertSame(
            [
                'editorial-starter-summarise',
                'editorial-starter-rewrite',
                'editorial-starter-proofread',
                'editorial-starter-expand',
                'editorial-starter-correct',
                'editorial-starter-plain-language',
                'editorial-starter-leichte-sprache',
                'editorial-starter-readability',
                'editorial-starter-review',
                'editorial-starter-headlines',
            ],
            array_map(static fn(PackTask $task): string => $task->identifier, $this->pack()->tasks),
        );
    }

    #[Test]
    public function theLeichteSpracheTaskKeepsOneSentencePerLine(): void
    {
        // Leichte Sprache puts every sentence on its own line. Markdown output
        // would be rendered, which joins single line breaks into a paragraph,
        // so the task returns plain text and says so in its prompt.
        $task = $this->task('editorial-starter-leichte-sprache');

        self::assertSame(TaskOutputFormat::PLAIN, $task->outputFormat);
        self::assertStringContainsString('its own line', $task->promptTemplate);
    }

    #[Test]
    public function leichteSpracheIsATaskOfItsOwnBesideEinfacheSprache(): void
    {
        // Two registers with two rule sets: Leichte Sprache always answers in
        // German, the plain-language task in the language of the text.
        $leicht = $this->task('editorial-starter-leichte-sprache');
        $einfach = $this->task('editorial-starter-plain-language');

        self::assertNotSame($einfach->promptTemplate, $leicht->promptTemplate);
        self::assertStringContainsString('Write German, whatever the language of the text', $leicht->promptTemplate);
        self::assertStringContainsString('Write in the language of the text', $einfach->promptTemplate);
    }

    private function task(string $identifier): PackTask
    {
        foreach ($this->pack()->tasks as $task) {
            if ($task->identifier === $identifier) {
                return $task;
            }
        }

        self::fail($identifier . ' is not in the pack.');
    }

    #[Test]
    public function requiresChatAndNothingElse(): void
    {
        // Every adapter provides chat, so the pack installs against a local
        // Ollama exactly as against a hosted provider.
        self::assertSame([ModelCapability::CHAT->value], $this->pack()->getRequiredCapabilities());
    }

    #[Test]
    public function theSnippetsAreReachedByTheConfigurationTheyShipWith(): void
    {
        // A configuration composes the active snippets carrying any of its tags.
        // Snippets whose tags no configuration selects would be installed and
        // then read by nothing.
        $pack = $this->pack();
        $tags = $pack->getSnippetTags();

        self::assertNotSame([], $tags);
        foreach ($pack->snippets as $snippet) {
            self::assertInstanceOf(PackSnippet::class, $snippet);
            self::assertNotSame([], $snippet->tags, $snippet->identifier . ' carries no tag.');
        }
    }

    #[Test]
    public function recommendsToolGroupsWithoutEnablingAnything(): void
    {
        // The declaration is a pointer to the Tools module, and the pack has no
        // way of acting on it: it holds names, not a switch.
        self::assertSame(['content'], $this->pack()->recommendedToolGroups);
    }
}
