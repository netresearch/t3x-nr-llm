<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRequestFieldNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProtoJSON: a request's proto field names are accepted beside the JSON
 * names; a Struct's members keep their keys.
 */
#[CoversClass(A2aRequestFieldNames::class)]
final class A2aRequestFieldNamesTest extends TestCase
{
    /**
     * The A2A TCK's JSON-RPC client sends `history_length` and the ListTasks
     * fields under their proto names.
     */
    #[Test]
    public function theTaskMethodsReadTheProtoFieldNames(): void
    {
        self::assertSame(['id' => 't', 'historyLength' => 0], A2aRequestFieldNames::normalise('GetTask', ['id' => 't', 'history_length' => 0]));
        self::assertSame(
            ['contextId' => 'c', 'pageSize' => 2, 'pageToken' => 'p', 'historyLength' => 1, 'statusTimestampAfter' => '2026-01-01T00:00:00Z', 'includeArtifacts' => true, 'status' => 'TASK_STATE_COMPLETED'],
            A2aRequestFieldNames::normalise('ListTasks', ['context_id' => 'c', 'page_size' => 2, 'page_token' => 'p', 'history_length' => 1, 'status_timestamp_after' => '2026-01-01T00:00:00Z', 'include_artifacts' => true, 'status' => 'TASK_STATE_COMPLETED']),
        );
    }

    #[Test]
    public function theNestedMessagesOfSendMessageAreRenamedToo(): void
    {
        $params = A2aRequestFieldNames::normalise('SendMessage', [
            'message' => [
                'message_id'         => 'm',
                'context_id'         => 'c',
                'task_id'            => 't',
                'role'               => 'ROLE_USER',
                'reference_task_ids' => ['r'],
                'parts'              => [['text' => 'hi', 'media_type' => 'text/plain']],
            ],
            'configuration' => [
                'accepted_output_modes'         => ['text/plain'],
                'history_length'                => 0,
                'return_immediately'            => true,
                'task_push_notification_config' => ['url' => 'https://example.org/hook', 'task_id' => 't'],
            ],
        ]);

        self::assertSame([
            'message' => [
                'messageId'        => 'm',
                'contextId'        => 'c',
                'taskId'           => 't',
                'role'             => 'ROLE_USER',
                'referenceTaskIds' => ['r'],
                'parts'            => [['text' => 'hi', 'mediaType' => 'text/plain']],
            ],
            'configuration' => [
                'acceptedOutputModes'        => ['text/plain'],
                'historyLength'              => 0,
                'returnImmediately'          => true,
                'taskPushNotificationConfig' => ['url' => 'https://example.org/hook', 'taskId' => 't'],
            ],
        ], $params);
    }

    /**
     * metadata is a google.protobuf.Struct and a data part's data a Value:
     * their members are the client's data, not proto fields.
     */
    #[Test]
    public function theMembersOfAStructKeepTheirKeys(): void
    {
        $struct = ['history_length' => 3, 'message_id' => 'kept', 'nested' => ['task_id' => 'kept']];

        $params = A2aRequestFieldNames::normalise('SendMessage', [
            'metadata' => $struct,
            'message'  => ['message_id' => 'm', 'metadata' => $struct, 'parts' => [['data' => $struct, 'metadata' => $struct]]],
        ]);

        self::assertSame($struct, $params['metadata']);
        self::assertSame(['messageId' => 'm', 'metadata' => $struct, 'parts' => [['data' => $struct, 'metadata' => $struct]]], $params['message']);
        self::assertSame(['id' => 't', 'metadata' => $struct], A2aRequestFieldNames::normalise('CancelTask', ['id' => 't', 'metadata' => $struct]));
    }

    /**
     * ProtoJSON: alternate spellings of one field are resolved "last wins".
     */
    #[Test]
    public function ofTwoSpellingsTheLaterOneWins(): void
    {
        self::assertSame(['id' => 't', 'historyLength' => 1], A2aRequestFieldNames::normalise('GetTask', ['historyLength' => 5, 'id' => 't', 'history_length' => 1]));
        self::assertSame(['id' => 't', 'historyLength' => 5], A2aRequestFieldNames::normalise('GetTask', ['history_length' => 1, 'id' => 't', 'historyLength' => 5]));
    }

    #[Test]
    public function unknownMembersAndUnknownMethodsAreLeftAlone(): void
    {
        self::assertSame(['id' => 't', 'some_extension' => 1], A2aRequestFieldNames::normalise('GetTask', ['id' => 't', 'some_extension' => 1]));
        self::assertSame(['history_length' => 1], A2aRequestFieldNames::normalise('SubscribeToTask', ['history_length' => 1]));
        self::assertSame(['message' => 'not an object'], A2aRequestFieldNames::normalise('SendMessage', ['message' => 'not an object']));
    }
}
