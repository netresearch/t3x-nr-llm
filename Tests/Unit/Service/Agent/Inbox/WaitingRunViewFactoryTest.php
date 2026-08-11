<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\Inbox;

use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\PreviewingApprovalTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(WaitingRunViewFactory::class)]
final class WaitingRunViewFactoryTest extends TestCase
{
    private function factory(ToolInterface ...$tools): WaitingRunViewFactory
    {
        return new WaitingRunViewFactory(new ToolRegistry($tools), new SchemaPropertyClassifier(), new PendingTurnDigest());
    }

    /**
     * The person the card is rendered for. Which records they may read is
     * answered by the TOOL, so the double here needs no configuration.
     */
    private function viewer(): BackendUserAuthentication
    {
        return self::createStub(BackendUserAuthentication::class);
    }

    #[Test]
    public function nullSuspendedStateIsUnreadable(): void
    {
        $view = $this->factory()->buildWaiting([$this->makeRun('a', null)])[0];

        self::assertSame(WaitingRunView::MODE_UNREADABLE, $view->mode);
        self::assertSame('state-unreadable', $view->unreadableReason);
    }

    #[Test]
    public function nonArrayJsonIsUnreadable(): void
    {
        $view = $this->factory()->buildWaiting([$this->makeRun('a', '"scalar"')])[0];

        self::assertSame(WaitingRunView::MODE_UNREADABLE, $view->mode);
    }

    #[Test]
    public function approvalViewCarriesPendingCallsAndADigest(): void
    {
        $state = $this->approvalState('delete_thing', ['uid' => 42]);
        $view  = $this->factory(new FakeTool('delete_thing'))->buildWaiting([$this->makeRun('a', $state)])[0];

        self::assertSame(WaitingRunView::MODE_APPROVAL, $view->mode);
        self::assertCount(1, $view->pendingCalls);
        self::assertSame('delete_thing', $view->pendingCalls[0]->name);
        self::assertTrue($view->pendingCalls[0]->toolStillRegistered);
        self::assertNotNull($view->turnDigest);
        self::assertStringContainsString('42', $view->pendingCalls[0]->argumentsJson);
    }

    #[Test]
    public function anUnregisteredToolIsFlagged(): void
    {
        $state = $this->approvalState('gone_tool', []);
        $view  = $this->factory()->buildWaiting([$this->makeRun('a', $state)])[0];

        self::assertSame(WaitingRunView::MODE_APPROVAL, $view->mode);
        self::assertFalse($view->pendingCalls[0]->toolStillRegistered);
    }

    #[Test]
    public function oneCorruptCallIsSkippedNotFatal(): void
    {
        $good  = ToolCall::function('c1', 'keep', [])->toArray();
        $state = json_encode((new SuspendedRunState([], [$good, ['not' => 'a call']], 1, 0, 0))->toArray(), JSON_THROW_ON_ERROR);

        $view = $this->factory(new FakeTool('keep'))->buildWaiting([$this->makeRun('a', $state)])[0];

        self::assertSame(WaitingRunView::MODE_APPROVAL, $view->mode);
        self::assertCount(1, $view->pendingCalls);
        self::assertSame('keep', $view->pendingCalls[0]->name);
    }

    #[Test]
    public function allCorruptCallsAreUnreadable(): void
    {
        $state = json_encode((new SuspendedRunState([], [['bad' => 1]], 1, 0, 0))->toArray(), JSON_THROW_ON_ERROR);

        $view = $this->factory()->buildWaiting([$this->makeRun('a', $state)])[0];

        self::assertSame(WaitingRunView::MODE_UNREADABLE, $view->mode);
        self::assertSame('no-pending-calls', $view->unreadableReason);
    }

    #[Test]
    public function aScalarInputSchemaIsUnreadableNeverAnEmptyForm(): void
    {
        $state = $this->inputState('ask', ['type' => 'string']);
        $view  = $this->factory()->buildWaiting([$this->makeRun('a', $state)])[0];

        self::assertSame(WaitingRunView::MODE_UNREADABLE, $view->mode);
        self::assertSame('schema-not-renderable', $view->unreadableReason);
    }

    #[Test]
    public function anObjectSchemaWithNoPropertiesIsUnreadable(): void
    {
        $state = $this->inputState('ask', ['type' => 'object', 'properties' => []]);
        $view  = $this->factory()->buildWaiting([$this->makeRun('a', $state)])[0];

        self::assertSame(WaitingRunView::MODE_UNREADABLE, $view->mode);
    }

    #[Test]
    public function aUsableObjectSchemaBecomesInputFields(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'reason' => ['type' => 'string', 'title' => 'Reason', 'description' => 'why'],
                'count'  => ['type' => 'integer'],
                'agree'  => ['type' => 'boolean'],
            ],
            'required' => ['reason'],
        ];
        $view = $this->factory()->buildWaiting([$this->makeRun('a', $this->inputState('ask', $schema))])[0];

        self::assertSame(WaitingRunView::MODE_INPUT, $view->mode);
        self::assertCount(3, $view->inputFields);
        self::assertSame('reason', $view->inputFields[0]->name);
        self::assertSame('Reason', $view->inputFields[0]->label);
        self::assertSame('text', $view->inputFields[0]->controlType);
        self::assertTrue($view->inputFields[0]->required);
        self::assertSame('why', $view->inputFields[0]->description);
        self::assertSame('integer', $view->inputFields[1]->controlType);
        self::assertSame('checkbox', $view->inputFields[2]->controlType);
        self::assertFalse($view->inputFields[1]->required);

        // Textual html-input attributes are precomputed so the template stays
        // logic-free: a string is a text field, an integer a stepped number.
        self::assertSame('text', $view->inputFields[0]->htmlType);
        self::assertSame('number', $view->inputFields[1]->htmlType);
        self::assertSame('1', $view->inputFields[1]->step);
        self::assertSame('numeric', $view->inputFields[1]->inputMode);
    }

    #[Test]
    public function numberFieldEnumOptionsAndLabelFallbackAreDerivedFromTheSchema(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                // No title -> the label falls back to the humanised property name.
                'max_temperature' => ['type' => 'number'],
                // An enum becomes selectable options, every value string-coerced.
                'severity'        => ['type' => 'string', 'enum' => ['low', 'high', 3]],
            ],
        ];
        $view = $this->factory()->buildWaiting([$this->makeRun('a', $this->inputState('ask', $schema))])[0];

        self::assertSame(WaitingRunView::MODE_INPUT, $view->mode);

        $number = $view->inputFields[0];
        self::assertSame('number', $number->controlType);
        // fieldLabel() fallback: ucfirst(str_replace('_', ' ', $name)).
        self::assertSame('Max temperature', $number->label);
        self::assertSame('number', $number->htmlType);
        self::assertSame('any', $number->step);
        self::assertSame('decimal', $number->inputMode);
        self::assertSame([], $number->options);

        $enum = $view->inputFields[1];
        self::assertSame(['low', 'high', '3'], $enum->options);
    }

    #[Test]
    public function theCardCarriesTheSharedDigestOfItsPendingTurn(): void
    {
        // The card is the render side of the ADR-132 binding; ResumeCoordinator
        // is the verify side. Asserted against PendingTurnDigest itself — the
        // ONE definition both use — and across the JSON boundary the card's
        // value crosses: the factory decodes the stored row, the digest must
        // survive that round trip or the verify side would never match.
        $stateJson = $this->approvalState('delete_thing', ['uid' => 42]);
        $run       = $this->makeRun('a', $stateJson);

        $view = $this->factory(new FakeTool('delete_thing'))->buildWaiting([$run])[0];

        // Decoded here on purpose: this is the step the verify side performs on
        // the stored row, so the expectation is built the way it is built there.
        $decoded = json_decode($stateJson, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        self::assertSame(
            (new PendingTurnDigest())->forState(SuspendedRunState::fromArray($decoded)),
            $view->turnDigest,
        );
    }

    #[Test]
    public function anInputCardCarriesTheInputDigestOfItsPause(): void
    {
        // REVERSED by ADR-150. Until then an input card carried no digest,
        // because the input path had nothing to bind a submission to; that was
        // the open half of #690, and the submission is now bound exactly as a
        // decision is. The value is the INPUT digest — over the pending calls,
        // the target tool and the schema these fields were built from — not the
        // approval one, which would never match on the verify side.
        $stateJson = $this->inputState('ask', ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]);
        $run       = $this->makeRun('a', $stateJson);

        $decoded = json_decode($stateJson, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $state  = SuspendedRunState::fromArray($decoded);
        $digest = new PendingTurnDigest();

        $view = $this->factory()->buildWaiting([$run])[0];

        self::assertSame($digest->forInputState($state), $view->turnDigest);
        self::assertNotSame($digest->forState($state), $view->turnDigest, 'the two pauses are bound by different digests');
    }

    #[Test]
    public function inputSchemaForRunReturnsTheCurrentSchemaOrNull(): void
    {
        $schema  = ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
        $factory = $this->factory();

        self::assertSame($schema, $factory->inputSchemaForRun($this->makeRun('a', $this->inputState('ask', $schema))));
        self::assertNull($factory->inputSchemaForRun($this->makeRun('b', $this->approvalState('t', []))));
        self::assertNull($factory->inputSchemaForRun($this->makeRun('c', $this->inputState('ask', ['type' => 'string']))));
    }

    #[Test]
    public function terminalViewCarriesNoSuspendedStateAndFormatsCost(): void
    {
        $run   = $this->makeRun('a', 'ignored', status: 'completed', crdate: 10, cost: 0.1234, finishedAt: 20);
        $views = $this->factory()->buildTerminal([$run]);

        self::assertCount(1, $views);
        self::assertSame('completed', $views[0]->status);
        self::assertSame(20, $views[0]->finishedAt);
        self::assertSame('0.1234', $views[0]->formattedCost);
    }

    #[Test]
    public function terminalViewOmitsZeroCost(): void
    {
        $run = $this->makeRun('a', null, status: 'failed', cost: 0.0);

        self::assertNull($this->factory()->buildTerminal([$run])[0]->formattedCost);
    }

    #[Test]
    public function theDetailLinkIsOfferedOnlyOnRunsTheViewerMayRead(): void
    {
        // ADR-153: the list is deliberately wider than the read. An approval
        // grant holder sees every user's terminal run, but AGENT_READ has no
        // grant equivalent — so the foreign row must carry no Timeline link,
        // which would only redirect back with "not found".
        $views = $this->factory()->buildTerminal(
            [
                $this->makeRun('a', null, status: 'completed', beUser: 7),
                $this->makeRun('b', null, status: 'completed', beUser: 8),
            ],
            AiActorContext::backendUser(7, grants: [BackendUserGrant::AGENT_APPROVE]),
        );

        self::assertTrue($views[0]->openableByViewer);
        self::assertFalse($views[1]->openableByViewer);
    }

    #[Test]
    public function anAdminMayOpenEveryTerminalRunAndAnAbsentActorNone(): void
    {
        $foreign = $this->makeRun('b', null, status: 'completed', beUser: 8);

        self::assertTrue($this->factory()->buildTerminal([$foreign], AiActorContext::backendUser(9, isAdmin: true))[0]->openableByViewer);
        // Fail-closed: no actor established means no link at all.
        self::assertFalse($this->factory()->buildTerminal([$foreign])[0]->openableByViewer);
    }

    /**
     * ADR-136: the card shows what the call WOULD do, not only its arguments.
     */
    #[Test]
    public function anApprovalCallCarriesItsCapturedPreview(): void
    {
        $call  = ToolCall::function('c1', 'update_page_metadata', ['uid' => 7])->toArray();
        $state = json_encode(
            (new SuspendedRunState([], [$call], 1, 0, 0, null, [], null, [], [
                ['index' => 0, 'tool' => 'update_page_metadata', 'lines' => ['Page [7] "Home" — 1 field(s):', 'title: "Home" → "New"'], 'failed' => false],
            ]))->toArray(),
            JSON_THROW_ON_ERROR,
        );

        $view = $this->factory(new PreviewingApprovalTool('update_page_metadata'))
            ->buildWaiting([$this->makeRun('a', $state)], $this->viewer())[0];

        self::assertSame(WaitingRunView::MODE_APPROVAL, $view->mode);
        self::assertSame(['Page [7] "Home" — 1 field(s):', 'title: "Home" → "New"'], $view->pendingCalls[0]->previewLines);
        self::assertFalse($view->pendingCalls[0]->previewFailed);
    }

    /**
     * ADR-136: producing a preview and showing it are two authorisations. The
     * `agent_approve` grant is tool-level, so an approver whose remit does not
     * include this page must not read its current metadata off the card.
     */
    #[Test]
    public function aPreviewIsWithheldFromAViewerWithoutPermissionOnItsRecord(): void
    {
        $view = $this->factory(new PreviewingApprovalTool('update_page_metadata', viewerMayRead: false))
            ->buildWaiting([$this->makeRun('a', $this->previewState())], $this->viewer())[0];

        self::assertSame(
            ['The preview is not shown: you hold no permission on the record it describes.'],
            $view->pendingCalls[0]->previewLines,
        );
        // Flagged, so the card says the decision is being made without a
        // preview instead of rendering an empty section.
        self::assertTrue($view->pendingCalls[0]->previewFailed);
        // The card itself still works — withholding the preview never blocks
        // the approval.
        self::assertSame(WaitingRunView::MODE_APPROVAL, $view->mode);
    }

    #[Test]
    public function aPreviewIsWithheldWhenNoViewerCanBeEstablished(): void
    {
        // No viewer argument at all, which is the caller that cannot establish
        // one -- the default is null and Rector rejects spelling it out.
        $view = $this->factory(new PreviewingApprovalTool('update_page_metadata'))
            ->buildWaiting([$this->makeRun('a', $this->previewState())])[0];

        self::assertTrue($view->pendingCalls[0]->previewFailed);
        self::assertSame(
            ['The preview is not shown: you hold no permission on the record it describes.'],
            $view->pendingCalls[0]->previewLines,
        );
    }

    /**
     * The persisted preview outlives the registration that produced it. A tool
     * that is gone — or one under that name that offers no preview contract —
     * cannot be asked whether this viewer may see the lines, so it is not shown.
     */
    #[Test]
    public function aPreviewIsWithheldWhenTheToolCanNoLongerBeAsked(): void
    {
        $view = $this->factory(new FakeTool('update_page_metadata'))
            ->buildWaiting([$this->makeRun('a', $this->previewState())], $this->viewer())[0];

        self::assertTrue($view->pendingCalls[0]->previewFailed);
        self::assertSame(
            ['The preview is not shown: you hold no permission on the record it describes.'],
            $view->pendingCalls[0]->previewLines,
        );
    }

    #[Test]
    public function aFailedPreviewRendersItsReasonAndIsFlaggedAsSuch(): void
    {
        $call  = ToolCall::function('c1', 'write_thing', [])->toArray();
        $state = json_encode(
            (new SuspendedRunState([], [$call], 1, 0, 0, null, [], null, [], [
                ['index' => 0, 'tool' => 'write_thing', 'lines' => ['The preview for this call failed (RuntimeException).'], 'failed' => true],
            ]))->toArray(),
            JSON_THROW_ON_ERROR,
        );

        $view = $this->factory(new PreviewingApprovalTool('write_thing'))
            ->buildWaiting([$this->makeRun('a', $state)], $this->viewer())[0];

        // The card renders a line and says it is a failure, so the approver
        // knows they are deciding blind instead of seeing an empty section.
        self::assertTrue($view->pendingCalls[0]->previewFailed);
        self::assertSame(['The preview for this call failed (RuntimeException).'], $view->pendingCalls[0]->previewLines);
    }

    /**
     * A corrupt call is skipped, so the surviving calls shift position. The
     * preview must follow the call it was captured for, never the one that
     * happens to land at its index afterwards.
     */
    #[Test]
    public function aPreviewStaysWithItsCallEvenWhenAnEarlierCallIsCorrupt(): void
    {
        $good  = ToolCall::function('c2', 'write_thing', [])->toArray();
        $state = json_encode(
            (new SuspendedRunState([], [['not' => 'a call'], $good], 1, 0, 0, null, [], null, [], [
                ['index' => 1, 'tool' => 'write_thing', 'lines' => ['would write'], 'failed' => false],
            ]))->toArray(),
            JSON_THROW_ON_ERROR,
        );

        $view = $this->factory(new PreviewingApprovalTool('write_thing'))
            ->buildWaiting([$this->makeRun('a', $state)], $this->viewer())[0];

        self::assertCount(1, $view->pendingCalls);
        self::assertSame(['would write'], $view->pendingCalls[0]->previewLines);
    }

    #[Test]
    public function aPreviewNamingADifferentToolIsDropped(): void
    {
        $call  = ToolCall::function('c1', 'write_thing', [])->toArray();
        $state = json_encode(
            (new SuspendedRunState([], [$call], 1, 0, 0, null, [], null, [], [
                ['index' => 0, 'tool' => 'some_other_tool', 'lines' => ['would write something else'], 'failed' => false],
            ]))->toArray(),
            JSON_THROW_ON_ERROR,
        );

        $view = $this->factory(new FakeTool('write_thing'))->buildWaiting([$this->makeRun('a', $state)])[0];

        // Showing a claim about the wrong call is worse than showing none.
        self::assertSame([], $view->pendingCalls[0]->previewLines);
    }

    /**
     * A row suspended before ADR-136 has no `callPreviews` key at all. It must
     * still render — a running installation is full of such rows.
     */
    #[Test]
    public function aStateWithoutThePreviewFieldStillRendersTheCard(): void
    {
        $call    = ToolCall::function('c1', 'write_thing', [])->toArray();
        $legacy  = (new SuspendedRunState([], [$call], 1, 0, 0))->toArray();
        unset($legacy['callPreviews']);
        $encoded = json_encode($legacy, JSON_THROW_ON_ERROR);

        $view = $this->factory(new FakeTool('write_thing'))->buildWaiting([$this->makeRun('a', $encoded)])[0];

        self::assertSame(WaitingRunView::MODE_APPROVAL, $view->mode);
        self::assertSame([], $view->pendingCalls[0]->previewLines);
        self::assertFalse($view->pendingCalls[0]->previewFailed);
    }

    /**
     * One `update_page_metadata` call carrying a captured preview.
     */
    private function previewState(): string
    {
        $call = ToolCall::function('c1', 'update_page_metadata', ['uid' => 7])->toArray();

        return json_encode(
            (new SuspendedRunState([], [$call], 1, 0, 0, null, [], null, [], [
                ['index' => 0, 'tool' => 'update_page_metadata', 'lines' => ['Page [7] "Home" — 1 field(s):'], 'failed' => false],
            ]))->toArray(),
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function approvalState(string $toolName, array $arguments): string
    {
        $call  = ToolCall::function('c1', $toolName, $arguments)->toArray();
        $state = new SuspendedRunState([], [$call], 1, 0, 0);

        return json_encode($state->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function inputState(string $inputToolName, array $schema): string
    {
        $call  = ToolCall::function('c1', $inputToolName, [])->toArray();
        $state = new SuspendedRunState([], [$call], 1, 0, 0, null, [], $inputToolName, $schema);

        return json_encode($state->toArray(), JSON_THROW_ON_ERROR);
    }

    private function makeRun(
        string $uuid,
        ?string $suspendedState,
        string $status = 'waiting_for_approval',
        string $config = 'cfg',
        int $crdate = 100,
        float $cost = 0.0,
        int $finishedAt = 0,
        int $beUser = 1,
    ): AgentRun {
        return new AgentRun(
            uid: 1,
            uuid: $uuid,
            status: $status,
            configurationUid: 0,
            configurationIdentifier: $config,
            beUser: $beUser,
            iterations: 1,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: $cost,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: $finishedAt,
            crdate: $crdate,
            suspendedState: $suspendedState,
        );
    }
}
