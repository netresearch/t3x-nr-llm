<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aInvalidAgentResponseException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aUnknownPayloadException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reads the `result` of an A2A response into nr-llm value objects.
 *
 * Forward compatible, as A2A-P4 and §5.7 require:
 *
 * - an unrecognised member is ignored;
 * - a `Part` with no content member this client knows is skipped and logged,
 *   never read as an empty part;
 * - a `TaskState` the pinned proto does not name is kept raw, logged, and is
 *   neither terminal nor interrupted, so it never reads as COMPLETED;
 * - a `SendMessageResponse` whose only member is unknown raises
 *   {@see A2aUnknownPayloadException}, which the send path records as an
 *   unknown outcome;
 * - an optional field is never required (`TaskStatus.timestamp` may be absent),
 *   and an optional field that is `null` is read as absent, as ProtoJSON
 *   reads it;
 * - a text part may be empty: a oneof member that is present may hold its
 *   default value.
 *
 * A field the proto marks REQUIRED that is absent or empty makes the response
 * invalid ({@see A2aInvalidAgentResponseException}, A2A-P5); so does a
 * REQUIRED repeated field without an element where A2A-P5 lists it. A REQUIRED
 * scalar that a ProtoJSON serialiser leaves out because it holds its default
 * (`ListTasksResponse.nextPageToken` `""`, `pageSize` and `totalSize` `0`) is
 * read as that default: proto3 JSON cannot tell "absent" from "default" for
 * a scalar.
 *
 * Every unknown kind is logged once per parser instance, not once per
 * occurrence.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aResponseParser
{
    private const PART_CONTENT = ['text', 'raw', 'url', 'data'];

    /** @var array<string, true> */
    private array $logged = [];

    private readonly LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $result
     *
     * @throws A2aInvalidAgentResponseException
     * @throws A2aUnknownPayloadException
     */
    public function parseSendMessageResponse(array $result): A2aSendMessageResult
    {
        $hasTask    = array_key_exists('task', $result);
        $hasMessage = array_key_exists('message', $result);
        if ($hasTask && $hasMessage) {
            throw A2aInvalidAgentResponseException::invalidField('result', 'carries both "task" and "message"');
        }

        if ($hasTask) {
            return A2aSendMessageResult::ofTask($this->parseTask($this->object($result['task'], 'result.task'), 'result.task'));
        }

        if ($hasMessage) {
            return A2aSendMessageResult::ofMessage($this->parseMessage($this->object($result['message'], 'result.message'), 'result.message'));
        }

        if ($result === []) {
            throw A2aInvalidAgentResponseException::missingField('result.task|result.message');
        }

        $members = array_map(strval(...), array_keys($result));
        foreach ($members as $member) {
            $this->logOnce('payload:' . $member, 'A2A SendMessageResponse member "{member}" is not part of protocol 1.0; skipped', ['member' => $member]);
        }

        throw A2aUnknownPayloadException::onlyUnknownMembers('SendMessageResponse', $members);
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    public function parseTask(array $json, string $path = 'task'): A2aTask
    {
        $id = $this->requiredString($json, 'id', $path);
        if (!array_key_exists('status', $json)) {
            throw A2aInvalidAgentResponseException::missingField($path . '.status');
        }

        $artifacts = [];
        foreach ($this->list($json['artifacts'] ?? [], $path . '.artifacts') as $index => $artifact) {
            $itemPath    = sprintf('%s.artifacts[%d]', $path, $index);
            $artifacts[] = $this->parseArtifact($this->object($artifact, $itemPath), $itemPath);
        }

        $history = [];
        foreach ($this->list($json['history'] ?? [], $path . '.history') as $index => $message) {
            $itemPath  = sprintf('%s.history[%d]', $path, $index);
            $history[] = $this->parseMessage($this->object($message, $itemPath), $itemPath);
        }

        return new A2aTask(
            $id,
            $this->optionalString($json, 'contextId', $path),
            $this->parseStatus($this->object($json['status'], $path . '.status'), $path . '.status'),
            $artifacts,
            $history,
            $this->struct($json, 'metadata', $path),
        );
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    public function parseMessage(array $json, string $path = 'message'): A2aMessage
    {
        $messageId = $this->requiredString($json, 'messageId', $path);

        return new A2aMessage(
            $messageId,
            $this->parseRole($json, $path),
            $this->parseParts($json, $path),
            $this->optionalString($json, 'contextId', $path),
            $this->optionalString($json, 'taskId', $path),
            $this->struct($json, 'metadata', $path),
            $this->stringList($json, 'extensions', $path),
            $this->stringList($json, 'referenceTaskIds', $path),
        );
    }

    /**
     * @param array<string, mixed> $result
     *
     * @throws A2aInvalidAgentResponseException
     */
    public function parseTaskList(array $result): A2aTaskList
    {
        $tasks = [];
        foreach ($this->list($result['tasks'] ?? [], 'result.tasks') as $index => $task) {
            $itemPath = sprintf('result.tasks[%d]', $index);
            $tasks[]  = $this->parseTask($this->object($task, $itemPath), $itemPath);
        }

        return new A2aTaskList(
            $tasks,
            $this->optionalString($result, 'nextPageToken', 'result'),
            $this->optionalInt($result, 'pageSize', 'result'),
            $this->optionalInt($result, 'totalSize', 'result'),
        );
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    public function parseAgentCard(array $json): A2aAgentCard
    {
        $path = 'card';

        $interfaces = [];
        foreach ($this->requiredList($json, 'supportedInterfaces', $path) as $index => $entry) {
            $itemPath     = sprintf('%s.supportedInterfaces[%d]', $path, $index);
            $interface    = $this->object($entry, $itemPath);
            $interfaces[] = new A2aAgentInterface(
                $this->requiredString($interface, 'url', $itemPath),
                $this->requiredString($interface, 'protocolBinding', $itemPath),
                $this->requiredString($interface, 'protocolVersion', $itemPath),
                $this->optionalString($interface, 'tenant', $itemPath),
            );
        }

        if (!array_key_exists('capabilities', $json)) {
            throw A2aInvalidAgentResponseException::missingField($path . '.capabilities');
        }

        $capabilities = $this->object($json['capabilities'], $path . '.capabilities');

        $skills = [];
        foreach ($this->requiredList($json, 'skills', $path) as $index => $entry) {
            $itemPath = sprintf('%s.skills[%d]', $path, $index);
            $skill    = $this->object($entry, $itemPath);
            $skills[] = new A2aAgentSkill(
                $this->requiredString($skill, 'id', $itemPath),
                $this->requiredString($skill, 'name', $itemPath),
                $this->requiredString($skill, 'description', $itemPath),
                $this->requiredStringList($skill, 'tags', $itemPath),
            );
        }

        return new A2aAgentCard(
            $this->requiredString($json, 'name', $path),
            $this->requiredString($json, 'description', $path),
            $this->requiredString($json, 'version', $path),
            $interfaces,
            new A2aAgentCapabilities(
                $this->optionalBool($capabilities, 'streaming', $path . '.capabilities'),
                $this->optionalBool($capabilities, 'pushNotifications', $path . '.capabilities'),
                $this->optionalBool($capabilities, 'extendedAgentCard', $path . '.capabilities'),
            ),
            $this->requiredStringList($json, 'defaultInputModes', $path),
            $this->requiredStringList($json, 'defaultOutputModes', $path),
            $skills,
        );
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function parseStatus(array $json, string $path): A2aTaskStatus
    {
        $raw   = $this->requiredString($json, 'state', $path);
        $state = A2aTaskState::tryFrom($raw);
        if (!$state instanceof A2aTaskState) {
            $this->logOnce('state:' . $raw, 'A2A task state "{state}" is not part of protocol 1.0; treated as not terminal', ['state' => $raw]);
        }

        $message = null;
        if (($json['message'] ?? null) !== null) {
            $message = $this->parseMessage($this->object($json['message'], $path . '.message'), $path . '.message');
        }

        $timestamp = null;
        if (($json['timestamp'] ?? null) !== null) {
            $value = $json['timestamp'];
            if (!is_string($value)) {
                throw A2aInvalidAgentResponseException::invalidField($path . '.timestamp', 'not a string');
            }

            $timestamp = A2aTimestamp::parse($value);
            if (!$timestamp instanceof DateTimeImmutable) {
                throw A2aInvalidAgentResponseException::invalidField($path . '.timestamp', 'not an ISO 8601 UTC timestamp ending in Z');
            }
        }

        return new A2aTaskStatus($state, $raw, $message, $timestamp);
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function parseArtifact(array $json, string $path): A2aArtifact
    {
        return new A2aArtifact(
            $this->requiredString($json, 'artifactId', $path),
            $this->parseParts($json, $path),
            $this->optionalString($json, 'name', $path),
            $this->optionalString($json, 'description', $path),
            $this->struct($json, 'metadata', $path),
            $this->stringList($json, 'extensions', $path),
        );
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function parseRole(array $json, string $path): A2aRole
    {
        $raw  = $this->requiredString($json, 'role', $path);
        $role = A2aRole::tryFrom($raw);
        if ($role instanceof A2aRole) {
            return $role;
        }

        $this->logOnce('role:' . $raw, 'A2A role "{role}" is not part of protocol 1.0; read as ROLE_UNSPECIFIED', ['role' => $raw]);

        return A2aRole::UNSPECIFIED;
    }

    /**
     * The parts of a message or artifact: at least one must be present on the
     * wire (A2A-P5); the ones of an unknown kind are then skipped (A2A-P4).
     *
     * @param array<string, mixed> $json
     *
     * @return list<A2aPart>
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function parseParts(array $json, string $path): array
    {
        $parts = [];
        foreach ($this->requiredList($json, 'parts', $path) as $index => $entry) {
            $itemPath = sprintf('%s.parts[%d]', $path, $index);
            $part     = $this->parsePart($this->object($entry, $itemPath), $itemPath);
            if ($part instanceof A2aPart) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function parsePart(array $json, string $path): ?A2aPart
    {
        $present = array_values(array_intersect(self::PART_CONTENT, array_keys($json)));
        if (count($present) > 1) {
            throw A2aInvalidAgentResponseException::invalidField($path, sprintf('carries more than one content member (%s)', implode(', ', $present)));
        }

        if ($present === []) {
            $members = implode(',', array_map(strval(...), array_keys($json)));
            $this->logOnce('part:' . $members, 'A2A part without a content member of protocol 1.0 (members: {members}); skipped', ['members' => $members]);

            return null;
        }

        $metadata  = $this->struct($json, 'metadata', $path);
        $filename  = $this->optionalString($json, 'filename', $path);
        $mediaType = $this->optionalString($json, 'mediaType', $path);

        return match ($present[0]) {
            'text'  => A2aPart::text($this->stringValue($json['text'], $path . '.text'), $mediaType, $metadata, $filename),
            'raw'   => A2aPart::raw($this->base64($json['raw'], $path . '.raw'), $mediaType, $filename, $metadata),
            'url'   => A2aPart::url($this->requiredStringValue($json['url'], $path . '.url'), $mediaType, $filename, $metadata),
            default => A2aPart::data($json['data'], $mediaType, $metadata, $filename),
        };
    }

    /**
     * @throws A2aInvalidAgentResponseException
     */
    private function base64(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw A2aInvalidAgentResponseException::invalidField($path, 'not a string');
        }

        // ProtoJSON accepts the URL-safe alphabet and missing padding on input.
        $normalised = strtr($value, '-_', '+/');
        $decoded    = base64_decode($normalised, true);
        if ($decoded === false) {
            throw A2aInvalidAgentResponseException::invalidField($path, 'not base64');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function object(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value) && $value !== []) {
            throw A2aInvalidAgentResponseException::invalidField($path, 'not an object');
        }

        $object = [];
        foreach ($value as $key => $entry) {
            $object[(string) $key] = $entry;
        }

        return $object;
    }

    /**
     * @return list<mixed>
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function list(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw A2aInvalidAgentResponseException::invalidField($path, 'not an array');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return list<mixed>
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function requiredList(array $json, string $key, string $path): array
    {
        if (!array_key_exists($key, $json)) {
            throw A2aInvalidAgentResponseException::missingField($path . '.' . $key);
        }

        $list = $this->list($json[$key], $path . '.' . $key);
        if ($list === []) {
            throw A2aInvalidAgentResponseException::invalidField($path . '.' . $key, 'must hold at least one element');
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return list<string>
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function requiredStringList(array $json, string $key, string $path): array
    {
        $strings = [];
        foreach ($this->requiredList($json, $key, $path) as $index => $value) {
            $strings[] = $this->requiredStringValue($value, sprintf('%s.%s[%d]', $path, $key, $index));
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return list<string>
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function stringList(array $json, string $key, string $path): array
    {
        if (($json[$key] ?? null) === null) {
            return [];
        }

        $strings = [];
        foreach ($this->list($json[$key], $path . '.' . $key) as $index => $value) {
            if (!is_string($value)) {
                throw A2aInvalidAgentResponseException::invalidField(sprintf('%s.%s[%d]', $path, $key, $index), 'not a string');
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function requiredString(array $json, string $key, string $path): string
    {
        if (!array_key_exists($key, $json)) {
            throw A2aInvalidAgentResponseException::missingField($path . '.' . $key);
        }

        return $this->requiredStringValue($json[$key], $path . '.' . $key);
    }

    /**
     * @throws A2aInvalidAgentResponseException
     */
    private function stringValue(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw A2aInvalidAgentResponseException::invalidField($path, 'not a string');
        }

        return $value;
    }

    /**
     * @throws A2aInvalidAgentResponseException
     */
    private function requiredStringValue(mixed $value, string $path): string
    {
        $value = $this->stringValue($value, $path);
        if ($value === '') {
            throw A2aInvalidAgentResponseException::missingField($path);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function optionalString(array $json, string $key, string $path): string
    {
        $value = $json[$key] ?? '';
        if (!is_string($value)) {
            throw A2aInvalidAgentResponseException::invalidField($path . '.' . $key, 'not a string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function optionalInt(array $json, string $key, string $path): int
    {
        $value = $json[$key] ?? 0;
        if (!is_int($value)) {
            throw A2aInvalidAgentResponseException::invalidField($path . '.' . $key, 'not an integer');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function optionalBool(array $json, string $key, string $path): ?bool
    {
        if (($json[$key] ?? null) === null) {
            return null;
        }

        if (!is_bool($json[$key])) {
            throw A2aInvalidAgentResponseException::invalidField($path . '.' . $key, 'not a boolean');
        }

        return $json[$key];
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     *
     * @throws A2aInvalidAgentResponseException
     */
    private function struct(array $json, string $key, string $path): array
    {
        if (!array_key_exists($key, $json) || $json[$key] === null) {
            return [];
        }

        return $this->object($json[$key], $path . '.' . $key);
    }

    /**
     * @param array<string, string> $context
     */
    private function logOnce(string $kind, string $message, array $context): void
    {
        if (isset($this->logged[$kind])) {
            return;
        }

        $this->logged[$kind] = true;
        $this->logger->notice($message, $context);
    }
}
