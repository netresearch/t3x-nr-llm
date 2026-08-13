<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Context;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Service\Context\InputContextClassification;
use Netresearch\NrLlm\Service\Context\InputContextClassifier;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Prompt\PromptSnippetComposer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The per-source view the ADR-151 readout needs, and the property that makes it
 * safe to show: {@see InputContextClassifier::classify()} is the fold over
 * exactly the list {@see InputContextClassifier::sources()} returns, so the
 * panel and the ADR-144 gate cannot disagree.
 */
#[CoversClass(InputContextClassifier::class)]
#[CoversClass(InputContextClassification::class)]
final class InputContextClassifierTest extends TestCase
{
    #[Test]
    public function everySourceIsListedWithTheClassItDeclared(): void
    {
        $configuration = $this->configuration([
            $this->skill('house-style', ToolDataClass::EDITOR_CONTENT->value),
        ]);

        $sources = $this->classifier([
            $this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value),
            $this->snippet('tone-of-voice', ToolDataClass::PUBLIC_CONTENT->value),
        ])->sources($configuration);

        self::assertSame(
            [
                ['snippet "legal-policy"', ToolDataClass::SECRET_ADJACENT],
                ['snippet "tone-of-voice"', ToolDataClass::PUBLIC_CONTENT],
                ['skill "house-style"', ToolDataClass::EDITOR_CONTENT],
            ],
            array_map(
                static fn(InputContextClassification $c): array => [$c->source, $c->effective],
                $sources,
            ),
        );
    }

    #[Test]
    public function anUnclassifiedSourceIsListedRatherThanOmitted(): void
    {
        // The gate only ever needed the strictest declaration, so a snippet
        // nobody classified was invisible to it. A readout that dropped it
        // would tell an operator "nothing is classified" for a prompt that
        // carries two snippets, one of which simply has no declaration.
        $sources = $this->classifier([
            $this->snippet('undeclared-fragment', ''),
            $this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value),
        ])->sources($this->configuration([]));

        self::assertCount(2, $sources);
        self::assertSame('snippet "undeclared-fragment"', $sources[0]->source);
        self::assertNull($sources[0]->effective);
        self::assertFalse($sources[0]->isDeclared());
    }

    #[Test]
    public function theEffectiveClassIsTheFoldOverTheListedSources(): void
    {
        $classifier    = $this->classifier([
            $this->snippet('tone-of-voice', ToolDataClass::PUBLIC_CONTENT->value),
            $this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value),
            $this->snippet('undeclared-fragment', ''),
        ]);
        $configuration = $this->configuration([
            $this->skill('house-style', ToolDataClass::EDITOR_CONTENT->value),
        ]);

        $folded = InputContextClassification::undeclared();
        foreach ($classifier->sources($configuration) as $source) {
            $folded = $folded->withStricter($source);
        }

        $effective = $classifier->classify($configuration);

        self::assertSame($folded->effective, $effective->effective);
        self::assertSame($folded->source, $effective->source);
        self::assertSame(ToolDataClass::SECRET_ADJACENT, $effective->effective);
        self::assertSame('snippet "legal-policy"', $effective->source);
    }

    #[Test]
    public function theForcedSetOfARunIsListedToo(): void
    {
        // The defect this pins: the readout was derived from the configuration
        // alone, so a run force-injecting a SECRET_ADJACENT snippet rendered
        // "injects nothing" in a panel whose whole job is data classification.
        $classifier = $this->classifier([]);

        $sources = $classifier->sources(
            new LlmConfiguration(),
            [$this->snippet('incident-report', ToolDataClass::SECRET_ADJACENT->value)],
            [$this->skill('forced-style', ToolDataClass::EDITOR_CONTENT->value)],
        );

        self::assertSame(
            [
                ['snippet "incident-report"', ToolDataClass::SECRET_ADJACENT],
                ['skill "forced-style"', ToolDataClass::EDITOR_CONTENT],
            ],
            array_map(
                static fn(InputContextClassification $c): array => [$c->source, $c->effective],
                $sources,
            ),
        );
        self::assertSame(ToolDataClass::SECRET_ADJACENT, InputContextClassifier::strictest($sources)->effective);
    }

    #[Test]
    public function aForcedSourceTheConfigurationAlreadyCarriesIsListedOnce(): void
    {
        // The loop's own assembly drops the duplicate text, so listing it twice
        // would describe an injection that does not happen.
        $snippet = $this->snippet('legal-policy', ToolDataClass::SECRET_ADJACENT->value);
        $skill   = $this->skill('house-style', ToolDataClass::EDITOR_CONTENT->value);

        $sources = $this->classifier([$snippet])->sources($this->configuration([$skill]), [$snippet], [$skill]);

        self::assertSame(
            ['snippet "legal-policy"', 'skill "house-style"'],
            array_map(static fn(InputContextClassification $c): string => $c->source, $sources),
        );
    }

    #[Test]
    public function theGateStillAnswersForTheConfigurationAlone(): void
    {
        // classify() keeps its configuration-only meaning. Since ADR-164 the
        // gate no longer asks it — it folds sources() with the run's forced set
        // — but classify() is still the answer to "what does this configuration
        // carry", independent of any one run, and must not start folding a
        // forced set into that.
        $classifier    = $this->classifier([]);
        $configuration = new LlmConfiguration();

        self::assertFalse($classifier->classify($configuration)->isDeclared());
        self::assertCount(
            1,
            $classifier->sources($configuration, [$this->snippet('incident-report', ToolDataClass::SECRET_ADJACENT->value)]),
        );
    }

    #[Test]
    public function aConfigurationThatInjectsNothingListsNothing(): void
    {
        $configuration = new LlmConfiguration();

        self::assertSame([], $this->classifier([])->sources($configuration));
        self::assertFalse($this->classifier([])->classify($configuration)->isDeclared());
    }

    /**
     * @param list<PromptSnippet> $snippets
     */
    private function classifier(array $snippets): InputContextClassifier
    {
        // The REAL resolver over a repository double, as in the gate's own test:
        // its selection logic is what the readout must agree with.
        $repository = self::createStub(PromptSnippetRepository::class);
        $repository->method('findActiveByTag')->willReturn($snippets);

        return new InputContextClassifier(new ConfigurationSnippetResolver($repository, new PromptSnippetComposer()));
    }

    /**
     * @param list<Skill> $skills
     */
    private function configuration(array $skills): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('classified');
        $configuration->setSnippetTags('policy');
        foreach ($skills as $skill) {
            $configuration->addSkill($skill);
        }

        return $configuration;
    }

    private function snippet(string $identifier, string $dataClass): PromptSnippet
    {
        $snippet = new PromptSnippet();
        $snippet->setIdentifier($identifier);
        $snippet->setName($identifier);
        $snippet->setDataClass($dataClass);

        return $snippet;
    }

    private function skill(string $name, string $dataClass): Skill
    {
        $skill = new Skill();
        $skill->setName($name);
        $skill->setDataClass($dataClass);

        return $skill;
    }
}
