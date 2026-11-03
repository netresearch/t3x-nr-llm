<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Form\ChoiceHelp;

use Netresearch\NrLlm\Form\ChoiceHelp\ChoiceSummary;
use Netresearch\NrLlm\Form\ChoiceHelp\ChoiceSummaryProvider;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * What the text under a record select says about each option (ADR-255):
 * the record's own description plus the facts an editor cannot see in the
 * option label.
 */
#[CoversClass(ChoiceSummaryProvider::class)]
#[CoversClass(ChoiceSummary::class)]
final class ChoiceSummaryProviderTest extends AbstractFunctionalTestCase
{
    private ChoiceSummaryProvider $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/ChoiceSummaries.csv');
        $this->subject = new ChoiceSummaryProvider($this->get(ConnectionPool::class));
    }

    /**
     * @param array<int, ChoiceSummary> $summaries
     *
     * @return array<int, array{description: string, facts: list<string>}>
     */
    private function plain(array $summaries): array
    {
        $plain = [];
        foreach ($summaries as $uid => $summary) {
            $plain[$uid] = ['description' => $summary->description, 'facts' => $summary->facts];
        }

        return $plain;
    }

    #[Test]
    public function aModelNamesItsProviderAndItsModelId(): void
    {
        self::assertSame(
            [
                11 => ['description' => 'Fast and cheap, good for short texts', 'facts' => ['Provider: OpenAI', 'Model ID: gpt-5-mini']],
                12 => ['description' => '', 'facts' => ['Provider: Local Ollama', 'Model ID: qwen3:4b']],
            ],
            $this->plain($this->subject->summarize('tx_nrllm_model', [11, 12])),
        );
    }

    #[Test]
    public function aConfigurationNamesTheModelItRunsOn(): void
    {
        self::assertSame(
            [
                21 => ['description' => 'Writes teasers and meta descriptions', 'facts' => ['Model: GPT mini (OpenAI)']],
                22 => ['description' => '', 'facts' => ['Picks its model by criteria when it runs']],
                23 => ['description' => '', 'facts' => ['No model selected yet']],
            ],
            $this->plain($this->subject->summarize('tx_nrllm_configuration', [21, 22, 23])),
        );
    }

    #[Test]
    public function aTaskNamesTheConfigurationItRunsOn(): void
    {
        self::assertSame(
            [
                31 => ['description' => 'Writes a two-sentence teaser for the page', 'facts' => ['Configuration: Short texts']],
                32 => ['description' => '', 'facts' => ['Configuration: the default configuration']],
            ],
            $this->plain($this->subject->summarize('tx_nrllm_task', [31, 32])),
        );
    }

    #[Test]
    public function aProviderNamesItsAdapterAndASkillItsIdentifier(): void
    {
        self::assertSame(
            [1 => ['description' => 'The company account for production', 'facts' => ['Adapter: openai']]],
            $this->plain($this->subject->summarize('tx_nrllm_provider', [1])),
        );
        self::assertSame(
            [
                41 => ['description' => 'Applies the house writing style', 'facts' => ['Identifier: house-style']],
                42 => ['description' => '', 'facts' => ['Identifier: bare']],
            ],
            $this->plain($this->subject->summarize('tx_nrllm_skill', [41, 42])),
        );
    }

    #[Test]
    public function onlyTheRequestedRecordsAreRead(): void
    {
        // The wizard asks only for the options FormEngine already offers, so a
        // record the editor cannot choose never reaches the page.
        self::assertSame([21], array_keys($this->subject->summarize('tx_nrllm_configuration', [21])));
        self::assertSame([], $this->subject->summarize('tx_nrllm_configuration', []));
        self::assertSame([], $this->subject->summarize('tx_nrllm_configuration', [999]));
    }

    #[Test]
    public function theDescriptionIsReturnedAsStoredForTheRendererToEscape(): void
    {
        self::assertSame(
            '<b>bold</b> & <script>x</script>',
            $this->subject->summarize('tx_nrllm_configuration', [24])[24]->description,
        );
    }

    #[Test]
    public function aTableThatIsNotAnLlmRecordYieldsNothing(): void
    {
        // be_groups has a description column; the provider still describes
        // only the records whose meaning it knows.
        self::assertSame([], $this->subject->summarize('be_groups', [1]));
        self::assertSame([], $this->subject->summarize('no_such_table', [1]));
    }
}
