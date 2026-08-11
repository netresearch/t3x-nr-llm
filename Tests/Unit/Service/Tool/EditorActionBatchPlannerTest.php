<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionOffer;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionOfferGroup;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchEntry;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchPlan;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchPlanner;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogueInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeEditorActionTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Planning one action over several records (ADR-162).
 *
 * The subject is the loop and what it reports, never the authorisation itself:
 * every "this record is not in the batch" case is arranged by making the
 * CATALOGUE refuse, because that is where the decision lives. What this class
 * owns is that the refusal is asked per record, survives into the plan with a
 * reason, and that the estimate is measured on the requests the plan actually
 * holds.
 */
#[CoversClass(EditorActionBatchPlanner::class)]
final class EditorActionBatchPlannerTest extends AbstractUnitTestCase
{
    private const TOOL = 'fake_write';

    #[Test]
    public function asksTheCatalogueOncePerRecordAndKeepsEachAnswer(): void
    {
        $asked   = [];
        $planner = $this->planner(offering: [11, 12, 13], asked: $asked);

        $plan = $this->plan($planner, '11, 12, 13');

        self::assertSame([11, 12, 13], $asked);
        self::assertSame([11, 12, 13], $this->runnableUids($plan));
        self::assertSame([], $plan->getSkipped());
        self::assertSame(
            [11, 12, 13],
            array_map(static fn(EditorActionBatchEntry $e): int => $e->recordUid, $plan->entries),
        );
    }

    /**
     * The point of asking per record: one refusal removes one record, and says
     * so. It never removes the batch, and it is never inferred from a sibling.
     */
    #[Test]
    public function skipsOnlyTheRecordTheCatalogueRefusesAndNamesTheReason(): void
    {
        $planner = $this->planner(offering: [11, 13]);

        $plan = $this->plan($planner, '11 12 13');

        self::assertSame([11, 13], $this->runnableUids($plan));

        $skipped = $plan->getSkipped();
        self::assertCount(1, $skipped);
        self::assertSame(12, $skipped[0]->recordUid);
        self::assertSame(EditorActionBatchPlanner::REASON_NOT_OFFERED, $skipped[0]->skipReasonKey);
    }

    #[Test]
    public function plansARepeatedRecordOnceAndSaysTheSecondMentionWasSkipped(): void
    {
        $asked   = [];
        $planner = $this->planner(offering: [11], asked: $asked);

        $plan = $this->plan($planner, '11,11');

        self::assertSame([11], $asked);
        self::assertSame([11], $this->runnableUids($plan));

        $skipped = $plan->getSkipped();
        self::assertCount(1, $skipped);
        self::assertSame(EditorActionBatchPlanner::REASON_DUPLICATE, $skipped[0]->skipReasonKey);
    }

    /**
     * Over the cap the surplus is LISTED, never truncated away: an editor who
     * pasted thirty numbers has to be able to see which ten did not make it.
     */
    #[Test]
    public function listsEverythingBeyondTheCapAsSkipped(): void
    {
        $uids    = range(1, EditorActionBatchPlanner::MAX_RECORDS + 3);
        $asked   = [];
        $planner = $this->planner(offering: $uids, asked: $asked);

        $plan = $this->plan($planner, implode(',', $uids));

        self::assertCount(EditorActionBatchPlanner::MAX_RECORDS, $asked);
        self::assertCount(EditorActionBatchPlanner::MAX_RECORDS, $this->runnableUids($plan));

        $skipped = $plan->getSkipped();
        self::assertCount(3, $skipped);
        foreach ($skipped as $entry) {
            self::assertSame(EditorActionBatchPlanner::REASON_OVER_CAP, $entry->skipReasonKey);
        }
    }

    #[Test]
    public function countsEntriesThatAreNotRecordNumbersInsteadOfSwallowingThem(): void
    {
        $planner = $this->planner(offering: [11]);

        $plan = $this->plan($planner, '11, pages, -4, 0, 3.5');

        self::assertSame(4, $plan->discardedInputs);
        self::assertCount(1, $plan->entries);
    }

    #[Test]
    public function carriesTheDeclarationSoThePageCanNameTheActionToAHuman(): void
    {
        $planner = $this->planner(offering: [11]);

        $offer = $this->plan($planner, '11')->offer;

        self::assertInstanceOf(EditorActionOffer::class, $offer);
        self::assertSame(self::TOOL, $offer->toolName);
    }

    #[Test]
    public function estimatesRequestsAndTokensFromTheRequestsThePlanHolds(): void
    {
        $planner = $this->planner(offering: [11, 12]);

        $estimate = $this->plan($planner, '11,12')->estimate;

        self::assertSame(2, $estimate->records);
        // Two sends per run: the one that decides the tool call, and the one
        // after approval that turns the tool result into the answer.
        self::assertSame(4, $estimate->providerRequests);
        self::assertGreaterThan(0, $estimate->inputTokensPerRequest);
        self::assertSame($estimate->inputTokensPerRequest * 4, $estimate->inputTokensTotal);
        self::assertSame(1000, $estimate->maxOutputTokensPerRequest);
    }

    /**
     * The estimate is MEASURED, not tabulated: the same batch over a longer
     * instruction is a bigger prompt and has to quote more tokens.
     */
    #[Test]
    public function aLongerInstructionRaisesTheTokenEstimate(): void
    {
        $planner = $this->planner(offering: [11]);

        $short = $this->plan($planner, '11', 'fix it')->estimate;
        $long  = $this->plan($planner, '11', str_repeat('fix the wording of the abstract ', 20))->estimate;

        self::assertGreaterThan($short->inputTokensPerRequest, $long->inputTokensPerRequest);
    }

    #[Test]
    public function showsNoPriceRangeWhenTheModelCarriesNone(): void
    {
        $planner = $this->planner(offering: [11]);

        $estimate = $this->plan($planner, '11')->estimate;

        self::assertFalse($estimate->isPriced());
        self::assertNull($estimate->costLow);
        self::assertNull($estimate->costHigh);
    }

    #[Test]
    public function pricesTheBatchFromTheModelsOwnPerMillionRates(): void
    {
        $model = new Model();
        // Stored in cents per 1M tokens: $1.00 in, $2.00 out.
        $model->setCostInput(100);
        $model->setCostOutput(200);

        $planner = $this->planner(offering: [11], model: $model);

        $estimate = $this->plan($planner, '11')->estimate;

        self::assertTrue($estimate->isPriced());
        // Low: input only. High: input plus the output ceiling on every request.
        self::assertEqualsWithDelta(
            $estimate->inputTokensTotal / 1000000,
            (float)$estimate->costLow,
            0.000001,
        );
        self::assertEqualsWithDelta(
            ($estimate->inputTokensTotal / 1000000) + (1000 * $estimate->providerRequests * 2 / 1000000),
            (float)$estimate->costHigh,
            0.000001,
        );
        self::assertGreaterThan((float)$estimate->costLow, (float)$estimate->costHigh);
    }

    /**
     * `maxTokens = 0` on a configuration means the request goes out with NO
     * `max_tokens` at all — unbounded output, not zero output. Reporting a
     * ceiling of 0 would understate it by everything, and pricing against it
     * collapses the upper bound onto the input-only lower one.
     */
    #[Test]
    public function reportsNoCeilingAndNoRangeWhenTheConfigurationBoundsNothing(): void
    {
        $model = new Model();
        $model->setCostInput(100);
        $model->setCostOutput(200);

        $planner = $this->planner(offering: [11], model: $model, maxTokens: 0);

        $estimate = $this->plan($planner, '11')->estimate;

        self::assertFalse($estimate->hasOutputCeiling());
        self::assertNull($estimate->maxOutputTokensPerRequest);
        self::assertFalse($estimate->isPriced());
    }

    /**
     * A model priced on one side only passes `hasPricing()`, and
     * `estimateCost()` charges the missing rate as zero — so the upper bound
     * would price the whole output ceiling at 0.00. That is the "reads as free,
     * means unknown" the estimate refuses, moved into one end of the range.
     */
    #[Test]
    public function showsNoRangeWhenTheModelIsPricedOnOneSideOnly(): void
    {
        $model = new Model();
        $model->setCostInput(100);

        self::assertTrue($model->hasPricing());

        $planner = $this->planner(offering: [11], model: $model);

        $estimate = $this->plan($planner, '11')->estimate;

        self::assertFalse($estimate->isPriced());
        self::assertNull($estimate->costLow);
        self::assertNull($estimate->costHigh);
    }

    #[Test]
    public function estimatesNothingWhenNothingWouldRun(): void
    {
        $planner = $this->planner(offering: []);

        $estimate = $this->plan($planner, '11,12')->estimate;

        self::assertSame(0, $estimate->records);
        self::assertSame(0, $estimate->providerRequests);
        self::assertFalse($estimate->isPriced());
    }

    // --- helpers -----------------------------------------------------------

    /**
     * @return list<int>
     */
    private function runnableUids(EditorActionBatchPlan $plan): array
    {
        $uids = [];
        foreach ($plan->entries as $entry) {
            if ($entry->isRunnable()) {
                $uids[] = $entry->recordUid;
            }
        }

        return $uids;
    }

    private function plan(EditorActionBatchPlanner $planner, string $recordUids, string $instruction = ''): EditorActionBatchPlan
    {
        return $planner->plan(
            self::TOOL,
            'pages',
            $recordUids,
            $instruction,
            AiActorContext::backendUser(3),
            self::createStub(BackendUserAuthentication::class),
        );
    }

    /**
     * @param list<int> $offering  the record numbers the catalogue offers the action for
     * @param list<int> $asked     filled with the record numbers the catalogue was asked about
     * @param int       $maxTokens the configuration's output ceiling; 0 means unbounded
     */
    private function planner(array $offering, array &$asked = [], ?Model $model = null, int $maxTokens = 1000): EditorActionBatchPlanner
    {
        $configuration = new LlmConfiguration();
        $configuration->setMaxTokens($maxTokens);
        if ($model instanceof Model) {
            $configuration->setLlmModel($model);
        }

        $catalogue = $this->createMock(EditorActionCatalogueInterface::class);
        $catalogue->method('runRequestFor')->willReturnCallback(
            static function (
                string $toolName,
                string $recordTable,
                int $recordUid,
                string $instruction,
            ) use ($offering, $configuration, &$asked): ?AgentRunRequest {
                $asked[] = $recordUid;

                if (!in_array($recordUid, $offering, true)) {
                    return null;
                }

                return new AgentRunRequest(
                    configuration: $configuration,
                    messages: [ChatMessage::user(
                        sprintf('Call "%s" for %s #%d. %s', $toolName, $recordTable, $recordUid, $instruction),
                    )],
                    actor: AiActorContext::backendUser(3),
                    allowedToolNames: [$toolName],
                );
            },
        );

        $catalogue->method('groupsFor')->willReturn([
            new EditorActionOfferGroup('editing', null, [
                new EditorActionOffer(
                    self::TOOL,
                    new EditorAction(
                        'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.label',
                        'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.update_page_metadata.description',
                        'nrllm-editor-action-page-metadata',
                        ['pages'],
                    ),
                    'editing',
                ),
            ]),
        ]);

        return new EditorActionBatchPlanner(
            $catalogue,
            new ToolRegistry([new FakeEditorActionTool(self::TOOL)]),
            new TranscriptEstimator(),
        );
    }
}
