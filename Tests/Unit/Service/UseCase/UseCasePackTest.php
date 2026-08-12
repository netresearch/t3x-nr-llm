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
     */
    private function pack(string $identifier = 'fixture-pack', array $tasks = [], array $snippets = []): UseCasePack
    {
        return new UseCasePack(
            identifier: $identifier,
            useCase: UseCase::EDITORIAL,
            name: 'Fixture Pack',
            description: '',
            configurationPreset: $this->preset(),
            recommendedGovernanceProfile: GovernanceProfile::CONTROLLED_CLOUD,
            tasks: $tasks,
            snippets: $snippets,
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
