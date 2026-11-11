<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Protocol;

use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aUnknownPayloadException;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aAgentCard;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aAgentInterface;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aJsonRpcCodec;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aPart;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aPartKind;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRole;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The response parser: the recorded a2a-python 1.3.0 bodies, forward
 * compatibility (A2A-P4), REQUIRED fields (A2A-P5), timestamps (A2A-P3) and
 * the client-relevant drift lines (A2A-D1, A2A-D4).
 */
#[CoversClass(A2aResponseParser::class)]
#[CoversClass(A2aTaskStatus::class)]
#[CoversClass(A2aTaskState::class)]
#[CoversClass(A2aAgentCard::class)]
#[CoversClass(A2aAgentInterface::class)]
final class A2aResponseParserTest extends TestCase
{
    private RecordingLogger $logger;

    private A2aResponseParser $parser;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->parser = new A2aResponseParser($this->logger);
    }

    #[Test]
    public function aBlockingSendThatCompletedIsATaskWithItsArtifact(): void
    {
        $result = $this->parser->parseSendMessageResponse($this->decodedResult('send-blocking-completed'));

        self::assertTrue($result->isTask());
        self::assertSame(A2aTaskState::COMPLETED, $result->task->status->state);
        self::assertTrue($result->task->status->isTerminal());
        self::assertSame('Hello World! Nice to meet you!', $result->task->artifactText());
        self::assertNotNull($result->task->status->timestamp, 'microsecond timestamps are read');
    }

    #[Test]
    public function aSubmittedStatusWithoutTimestampIsValid(): void
    {
        // a2a-python 1.3.0 sends SUBMITTED without `timestamp` (A2A-P4).
        $result = $this->parser->parseSendMessageResponse($this->decodedResult('send-return-immediately-submitted'));

        self::assertTrue($result->isTask());
        self::assertSame(A2aTaskState::SUBMITTED, $result->task->status->state);
        self::assertNull($result->task->status->timestamp);
        self::assertFalse($result->task->status->isTerminal());
        self::assertSame('f70f3c3f-db58-429b-b7f6-a856d33df55f', $result->task->contextId);
    }

    #[Test]
    public function aWorkingTaskCarriesItsStatusMessage(): void
    {
        $task = $this->parser->parseTask($this->decodedResult('get-task-working'));

        self::assertSame(A2aTaskState::WORKING, $task->status->state);
        self::assertSame(A2aRole::AGENT, $task->status->message?->role);
        self::assertSame('Processing your question...', $task->status->message->text());
        self::assertCount(1, $task->history);
    }

    #[Test]
    public function aCanceledTaskIsTerminal(): void
    {
        $task = $this->parser->parseTask($this->decodedResult('cancel-task-canceled'));

        self::assertSame(A2aTaskState::CANCELED, $task->status->state);
        self::assertTrue($task->status->isTerminal());
    }

    #[Test]
    public function aTaskListKeepsPaginationAndIgnoresEmptyDefaultsOfParts(): void
    {
        $list = $this->parser->parseTaskList($this->decodedResult('list-tasks'));

        self::assertNotSame([], $list->tasks);
        self::assertContainsOnlyInstancesOf(A2aTask::class, $list->tasks);
        self::assertGreaterThanOrEqual(count($list->tasks), $list->totalSize);
    }

    #[Test]
    public function anEmptyTaskListMayOmitEveryDefaultValuedMember(): void
    {
        // ProtoJSON leaves out "", 0 and an empty repeated field.
        $list = $this->parser->parseTaskList([]);

        self::assertSame([], $list->tasks);
        self::assertSame('', $list->nextPageToken);
        self::assertSame(0, $list->pageSize);
        self::assertSame(0, $list->totalSize);
    }

    #[Test]
    public function fieldsOfALaterVersionAreIgnored(): void
    {
        // `generation` and `status.progressHint` are v1.1; the SDK the spike
        // measured rejected this very body, a2a-python read it (A2A-P4).
        $result = $this->parser->parseSendMessageResponse($this->decodedResult('send-v11-fields'));

        self::assertTrue($result->isTask());
        self::assertSame(A2aTaskState::COMPLETED, $result->task->status->state);
        self::assertSame('echo: future', $result->task->artifactText());
    }

    #[Test]
    public function fieldsTheProsePromisesButTheProtoLacksAreUnrecognisedAndIgnored(): void
    {
        // A2A-D4: whats-new lists Task.createdAt and Task.lastModified; the
        // proto has neither, so they are unknown fields like any other.
        $task = $this->parser->parseTask([
            'id'           => 't-1',
            'status'       => ['state' => 'TASK_STATE_WORKING'],
            'createdAt'    => '2026-11-10T21:54:29.148Z',
            'lastModified' => '2026-11-10T21:54:29.148Z',
        ]);

        self::assertSame('t-1', $task->id);
        self::assertSame([], $this->logger->records, 'an unknown field is not even worth a log line');
    }

    #[Test]
    public function anUnknownStateIsNeitherTerminalNorInterruptedAndIsLoggedOnce(): void
    {
        $json = ['id' => 't-1', 'status' => ['state' => 'TASK_STATE_PAUSED']];

        $first  = $this->parser->parseTask($json);
        $second = $this->parser->parseTask($json);

        self::assertNull($first->status->state);
        self::assertSame('TASK_STATE_PAUSED', $first->status->rawState);
        self::assertFalse($first->status->isTerminal());
        self::assertFalse($first->status->isInterrupted());
        self::assertFalse($first->status->isKnown());
        self::assertNotSame(A2aTaskState::COMPLETED, $second->status->state);
        self::assertCount(1, $this->logger->records, 'logged once per kind, not per occurrence');
    }

    #[Test]
    public function aPartOfAnUnknownKindIsSkippedNotReadAsEmpty(): void
    {
        $message = $this->parser->parseMessage([
            'messageId' => 'm-1',
            'role'      => 'ROLE_AGENT',
            'parts'     => [
                ['text' => 'before'],
                ['hologram' => ['frames' => 3], 'mediaType' => 'x/holo'],
                ['text' => 'after'],
            ],
        ]);

        self::assertCount(2, $message->parts);
        self::assertSame("before\n\nafter", $message->text());
        self::assertCount(1, $this->logger->records);
    }

    #[Test]
    public function aMessageWhosePartsAreAllOfUnknownKindsIsValidAndEmpty(): void
    {
        $message = $this->parser->parseMessage([
            'messageId' => 'm-1',
            'role'      => 'ROLE_AGENT',
            'parts'     => [['hologram' => true]],
        ]);

        self::assertSame([], $message->parts);
    }

    #[Test]
    public function aPartWithTwoContentMembersIsInvalid(): void
    {
        $this->expectException(A2aInvalidAgentResponseException::class);

        $this->parser->parseMessage([
            'messageId' => 'm-1',
            'role'      => 'ROLE_AGENT',
            'parts'     => [['text' => 'a', 'url' => 'https://example.org']],
        ]);
    }

    #[Test]
    public function aRawPartIsDecodedFromBase64(): void
    {
        $png     = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $message = $this->parser->parseMessage([
            'messageId' => 'm-1',
            'role'      => 'ROLE_USER',
            'parts'     => [['raw' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'filename' => 'dot.png', 'mediaType' => 'image/png']],
        ]);

        self::assertSame(A2aPartKind::RAW, $message->parts[0]->kind);
        self::assertSame($png, $message->parts[0]->raw);
        self::assertSame('image/png', $message->parts[0]->mediaType);
        self::assertSame('dot.png', $message->parts[0]->filename);
    }

    #[Test]
    public function aRawPartThatIsNotBase64IsInvalid(): void
    {
        $this->expectException(A2aInvalidAgentResponseException::class);

        $this->parser->parseMessage(['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['raw' => '***']]]);
    }

    #[Test]
    public function aDataPartKeepsItsJsonValue(): void
    {
        $message = $this->parser->parseMessage([
            'messageId' => 'm',
            'role'      => 'ROLE_AGENT',
            'parts'     => [['data' => ['score' => 0.5, 'tags' => ['a']]]],
        ]);

        self::assertSame(A2aPartKind::DATA, $message->parts[0]->kind);
        self::assertSame(['score' => 0.5, 'tags' => ['a']], $message->parts[0]->data);
    }

    #[Test]
    public function textAndDataPartsKeepTheirFilename(): void
    {
        $parts = $this->parser->parseMessage([
            'messageId' => 'm',
            'role'      => 'ROLE_AGENT',
            'parts'     => [
                ['text' => 'report', 'filename' => 'report.txt'],
                ['data' => ['a' => 1], 'filename' => 'report.json'],
            ],
        ])->parts;

        self::assertSame('report.txt', $parts[0]->filename);
        self::assertSame('report.json', $parts[1]->filename);
        self::assertSame(['text' => 'report', 'filename' => 'report.txt'], $parts[0]->toArray());
        self::assertSame(['data' => ['a' => 1], 'filename' => 'report.json'], $parts[1]->toArray());
    }

    #[Test]
    public function anEmptyTextPartIsATextPart(): void
    {
        $sent    = A2aPart::text('')->toArray();
        $message = $this->parser->parseMessage(['messageId' => 'm', 'role' => 'ROLE_AGENT', 'parts' => [$sent]]);

        self::assertSame(['text' => ''], $sent);
        self::assertSame(A2aPartKind::TEXT, $message->parts[0]->kind);
        self::assertSame('', $message->parts[0]->text);
    }

    #[Test]
    public function aNullOptionalFieldIsReadAsAbsent(): void
    {
        $task = $this->parser->parseTask([
            'id'        => 't',
            'contextId' => null,
            'status'    => ['state' => 'TASK_STATE_WORKING', 'message' => null, 'timestamp' => null],
            'artifacts' => null,
            'history'   => [['messageId' => 'm', 'role' => 'ROLE_AGENT', 'parts' => [['text' => 'x']], 'extensions' => null, 'referenceTaskIds' => null]],
            'metadata'  => null,
        ]);

        self::assertSame('', $task->contextId);
        self::assertNull($task->status->message);
        self::assertNull($task->status->timestamp);
        self::assertSame([], $task->artifacts);
        self::assertSame([], $task->history[0]->extensions);

        $card                 = $this->card([['url' => 'https://agent.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']]);
        $card['capabilities'] = ['streaming' => null, 'pushNotifications' => true];

        self::assertNull($this->parser->parseAgentCard($card)->capabilities->streaming);
    }

    #[Test]
    public function aNullRequiredFieldIsStillInvalid(): void
    {
        $this->expectException(A2aInvalidAgentResponseException::class);

        $this->parser->parseTask(['id' => 't', 'status' => null]);
    }

    #[Test]
    public function anUnknownSendResponseMemberAloneIsAnUnknownPayloadNotAnError(): void
    {
        $this->expectException(A2aUnknownPayloadException::class);

        try {
            $this->parser->parseSendMessageResponse(['liveSession' => ['id' => 'x']]);
        } finally {
            self::assertCount(1, $this->logger->records);
        }
    }

    #[Test]
    public function aSendResponseWithBothTaskAndMessageIsInvalid(): void
    {
        $this->expectException(A2aInvalidAgentResponseException::class);

        $this->parser->parseSendMessageResponse([
            'task'    => ['id' => 't', 'status' => ['state' => 'TASK_STATE_COMPLETED']],
            'message' => ['messageId' => 'm', 'role' => 'ROLE_AGENT', 'parts' => [['text' => 'x']]],
        ]);
    }

    #[Test]
    public function aMessageAnswerIsAMessageNotATask(): void
    {
        $result = $this->parser->parseSendMessageResponse([
            'message' => ['messageId' => 'm', 'contextId' => 'c', 'role' => 'ROLE_AGENT', 'parts' => [['text' => 'direct answer']]],
        ]);

        self::assertFalse($result->isTask());
        self::assertSame('direct answer', $result->message->text());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function invalidTasks(): iterable
    {
        $status = ['state' => 'TASK_STATE_WORKING'];
        $msg    = ['messageId' => 'm', 'role' => 'ROLE_AGENT', 'parts' => [['text' => 'x']]];

        yield 'Task.id missing' => ['task', ['status' => $status]];
        yield 'Task.id empty' => ['task', ['id' => '', 'status' => $status]];
        yield 'Task.status missing' => ['task', ['id' => 't']];
        yield 'TaskStatus.state missing' => ['task', ['id' => 't', 'status' => []]];
        yield 'TaskStatus.timestamp with an offset' => ['task', ['id' => 't', 'status' => ['state' => 'TASK_STATE_WORKING', 'timestamp' => '2026-11-10T21:54:29.148+00:00']]];
        yield 'TaskStatus.timestamp without zone' => ['task', ['id' => 't', 'status' => ['state' => 'TASK_STATE_WORKING', 'timestamp' => '2026-11-10T21:54:29']]];
        yield 'Message.messageId missing' => ['message', ['role' => 'ROLE_AGENT', 'parts' => [['text' => 'x']]]];
        yield 'Message.role missing' => ['message', ['messageId' => 'm', 'parts' => [['text' => 'x']]]];
        yield 'Message.parts missing' => ['message', ['messageId' => 'm', 'role' => 'ROLE_AGENT']];
        yield 'Message.parts empty' => ['message', ['messageId' => 'm', 'role' => 'ROLE_AGENT', 'parts' => []]];
        yield 'Artifact.artifactId missing' => ['task', ['id' => 't', 'status' => $status, 'artifacts' => [['parts' => [['text' => 'x']]]]]];
        yield 'Artifact.parts empty' => ['task', ['id' => 't', 'status' => $status, 'artifacts' => [['artifactId' => 'a', 'parts' => []]]]];
        yield 'history entry without role' => ['task', ['id' => 't', 'status' => $status, 'history' => [['messageId' => 'm', 'parts' => [['text' => 'x']]]]]];
        yield 'status message without parts' => ['task', ['id' => 't', 'status' => ['state' => 'TASK_STATE_WORKING', 'message' => ['messageId' => 'm', 'role' => 'ROLE_AGENT']]]];
        yield 'metadata that is not an object' => ['task', ['id' => 't', 'status' => $status, 'metadata' => 'x']];
        yield 'contextId that is not a string' => ['task', ['id' => 't', 'contextId' => 5, 'status' => $status]];
        yield 'a valid message for reference' => ['messageOk', $msg];
    }

    /**
     * @param array<string, mixed> $json
     */
    #[Test]
    #[DataProvider('invalidTasks')]
    public function aMissingOrMalformedRequiredFieldMakesTheResponseInvalid(string $kind, array $json): void
    {
        if ($kind === 'messageOk') {
            // The control: the same shape with every REQUIRED field present parses.
            self::assertSame('m', $this->parser->parseMessage($json)->messageId);

            return;
        }

        $this->expectException(A2aInvalidAgentResponseException::class);

        if ($kind === 'task') {
            $this->parser->parseTask($json);

            return;
        }

        $this->parser->parseMessage($json);
    }

    #[Test]
    public function theRecordedCardSelectsTheFirstJsonRpc10Interface(): void
    {
        $card = $this->parser->parseAgentCard(WireFixture::load('agent-card-a2a-python')->decodedBody());

        $interface = $card->selectInterface();
        self::assertInstanceOf(A2aAgentInterface::class, $interface);
        self::assertSame('http://a2a-spike-pyserver:18110/a2a/jsonrpc', $interface->url);
        self::assertSame('', $interface->tenant);
        self::assertTrue($card->capabilities->streaming);
        self::assertFalse($card->capabilities->pushNotifications);
        self::assertNull($card->capabilities->extendedAgentCard);
        self::assertSame('sample_agent', $card->skills[0]->id);
    }

    #[Test]
    public function aPatchLevelOf10IsUsableAndTheTenantIsKept(): void
    {
        $card = $this->parser->parseAgentCard($this->card([
            ['url' => 'https://a.example/rest', 'protocolBinding' => 'HTTP+JSON', 'protocolVersion' => '1.0'],
            ['url' => 'https://a.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0.3', 'tenant' => 'acme'],
        ]));

        self::assertSame('https://a.example/rpc', $card->selectInterface()?->url);
        self::assertSame('acme', $card->selectInterface()->tenant);
    }

    #[Test]
    public function aCardWithoutAJsonRpc10InterfaceSelectsNoneAndSaysWhatItOffers(): void
    {
        $card = $this->parser->parseAgentCard($this->card([
            ['url' => 'https://a.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '0.3'],
            ['url' => 'https://a.example/grpc', 'protocolBinding' => 'GRPC', 'protocolVersion' => '1.0'],
            ['url' => 'https://a.example/rpc10', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '10.0'],
        ]));

        self::assertNull($card->selectInterface());
        self::assertSame('JSONRPC 0.3, GRPC 1.0, JSONRPC 10.0', $card->describeInterfaces());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredCardMembers(): iterable
    {
        foreach (['name', 'description', 'version', 'supportedInterfaces', 'capabilities', 'defaultInputModes', 'defaultOutputModes', 'skills'] as $member) {
            yield $member => [$member];
        }
    }

    #[Test]
    #[DataProvider('requiredCardMembers')]
    public function aCardWithoutARequiredMemberIsInvalid(string $member): void
    {
        $card = $this->card([['url' => 'https://a.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']]);
        unset($card[$member]);

        $this->expectException(A2aInvalidAgentResponseException::class);

        $this->parser->parseAgentCard($card);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyRepeatedCardMembers(): iterable
    {
        foreach (['supportedInterfaces', 'defaultInputModes', 'defaultOutputModes', 'skills'] as $member) {
            yield $member => [$member];
        }
    }

    #[Test]
    #[DataProvider('emptyRepeatedCardMembers')]
    public function aRequiredRepeatedCardMemberMustHoldAnElement(string $member): void
    {
        $card          = $this->card([['url' => 'https://a.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']]);
        $card[$member] = [];

        $this->expectException(A2aInvalidAgentResponseException::class);

        $this->parser->parseAgentCard($card);
    }

    #[Test]
    public function aSkillWithoutTagsIsInvalid(): void
    {
        $card                      = $this->card([['url' => 'https://a.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']]);
        $card['skills'][0]['tags'] = [];

        $this->expectException(A2aInvalidAgentResponseException::class);

        $this->parser->parseAgentCard($card);
    }

    #[Test]
    public function anInterfaceWithoutAUrlIsInvalid(): void
    {
        $this->expectException(A2aInvalidAgentResponseException::class);

        $this->parser->parseAgentCard($this->card([['protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']]));
    }

    /**
     * @param list<array<string, string>> $interfaces
     *
     * @return array<string, mixed>
     */
    private function card(array $interfaces): array
    {
        return [
            'name'                 => 'Agent',
            'description'          => 'Does things',
            'version'              => '1.0.0',
            'supportedInterfaces'  => $interfaces,
            'capabilities'         => [],
            'defaultInputModes'    => ['text/plain'],
            'defaultOutputModes'   => ['text/plain'],
            'skills'               => [['id' => 's', 'name' => 'S', 'description' => 'd', 'tags' => ['t']]],
            'securityRequirements' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedResult(string $fixture): array
    {
        $wire     = WireFixture::load($fixture);
        $response = A2aJsonRpcCodec::decodeResponse($wire->body, $wire->requestId);
        self::assertFalse($response->isError());

        return $response->result;
    }
}
