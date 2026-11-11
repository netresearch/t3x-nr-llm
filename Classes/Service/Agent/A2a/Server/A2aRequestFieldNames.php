<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aProtocol;

/**
 * Accepts the proto field names of a request beside the JSON names.
 *
 * A2A 1.0 serialises its proto data model as ProtoJSON (A2A §5.5, ADR-001),
 * and ProtoJSON parsers "are required to accept both the converted
 * lowerCamelCase name and the proto field name". The handlers read the
 * lowerCamelCase name; this class renames a proto field name to it before
 * they do. A field given under both names takes the value that comes last
 * in the request, ProtoJSON's "last wins" for alternate spellings.
 *
 * Only fields of the request messages below are renamed. The members of a
 * google.protobuf.Struct or Value (`metadata`, a data part's `data`) are
 * the client's own data and keep their keys, as does any member this class
 * does not know, which the server ignores (A2A-P4).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aRequestFieldNames
{
    /**
     * The proto fields of each request message (a2a.proto 1.0.1) whose
     * name differs in lowerCamelCase, and the message type of a field that
     * is itself a message (`[]` marks a repeated one).
     *
     * @var array<string, array<string, string|null>>
     */
    private const FIELDS = [
        'SendMessageRequest' => [
            'message'       => 'Message',
            'configuration' => 'SendMessageConfiguration',
        ],
        'Message' => [
            'message_id'         => null,
            'context_id'         => null,
            'task_id'            => null,
            'parts'              => 'Part[]',
            'reference_task_ids' => null,
        ],
        'Part' => [
            'media_type' => null,
        ],
        'SendMessageConfiguration' => [
            'accepted_output_modes'         => null,
            'task_push_notification_config' => 'TaskPushNotificationConfig',
            'history_length'                => null,
            'return_immediately'            => null,
        ],
        'TaskPushNotificationConfig' => [
            'task_id' => null,
        ],
        'GetTaskRequest' => [
            'history_length' => null,
        ],
        'ListTasksRequest' => [
            'context_id'             => null,
            'page_size'              => null,
            'page_token'             => null,
            'history_length'         => null,
            'status_timestamp_after' => null,
            'include_artifacts'      => null,
        ],
        'CancelTaskRequest' => [],
    ];

    private const REQUEST_OF_METHOD = [
        A2aProtocol::METHOD_SEND_MESSAGE => 'SendMessageRequest',
        A2aProtocol::METHOD_GET_TASK     => 'GetTaskRequest',
        A2aProtocol::METHOD_LIST_TASKS   => 'ListTasksRequest',
        A2aProtocol::METHOD_CANCEL_TASK  => 'CancelTaskRequest',
    ];

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function normalise(string $method, array $params): array
    {
        $type = self::REQUEST_OF_METHOD[$method] ?? null;

        return $type === null ? $params : self::message($type, $params);
    }

    /**
     * @param array<array-key, mixed> $json
     *
     * @return array<string, mixed>
     */
    private static function message(string $type, array $json): array
    {
        /** @var array<string, array{name: string, type: string|null}> $spellings */
        $spellings = [];
        foreach (self::FIELDS[$type] ?? [] as $protoName => $fieldType) {
            $field                     = ['name' => self::camel($protoName), 'type' => $fieldType];
            $spellings[$protoName]     = $field;
            $spellings[$field['name']] = $field;
        }

        $result = [];
        foreach ($json as $key => $value) {
            $key   = (string) $key;
            $field = $spellings[$key] ?? null;
            if ($field === null) {
                $result[$key] = $value;
                continue;
            }

            // Last wins: a later spelling replaces an earlier one, and the
            // key moves to where the value came from.
            unset($result[$field['name']]);
            $result[$field['name']] = self::value($field['type'], $value);
        }

        return $result;
    }

    private static function value(?string $type, mixed $value): mixed
    {
        if ($type === null || !is_array($value)) {
            return $value;
        }

        if (str_ends_with($type, '[]')) {
            $element = substr($type, 0, -2);

            return array_map(static fn (mixed $item): mixed => is_array($item) && !array_is_list($item) ? self::message($element, $item) : $item, $value);
        }

        return array_is_list($value) ? $value : self::message($type, $value);
    }

    private static function camel(string $protoName): string
    {
        return lcfirst(str_replace('_', '', ucwords($protoName, '_')));
    }
}
