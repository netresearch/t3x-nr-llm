<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\Inbox;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WaitingRunViewFactory::class)]
final class WaitingRunViewFactoryTest extends TestCase
{
    private function factory(FakeTool ...$tools): WaitingRunViewFactory
    {
        return new WaitingRunViewFactory(new ToolRegistry($tools), new SchemaPropertyClassifier());
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
    public function turnDigestForRunRecomputesTheSameDigestTheViewCarried(): void
    {
        $state   = $this->approvalState('delete_thing', ['uid' => 42]);
        $run     = $this->makeRun('a', $state);
        $factory = $this->factory(new FakeTool('delete_thing'));

        $viewDigest = $factory->buildWaiting([$run])[0]->turnDigest;

        self::assertSame($viewDigest, $factory->turnDigestForRun($run));
    }

    #[Test]
    public function turnDigestForRunIsNullForAnInputPause(): void
    {
        $run = $this->makeRun('a', $this->inputState('ask', ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]]));

        self::assertNull($this->factory()->turnDigestForRun($run));
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
    ): AgentRun {
        return new AgentRun(
            uid: 1,
            uuid: $uuid,
            status: $status,
            configurationUid: 0,
            configurationIdentifier: $config,
            beUser: 1,
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
