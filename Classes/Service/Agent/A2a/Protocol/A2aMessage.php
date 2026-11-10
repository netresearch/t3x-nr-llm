<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Protocol;

use Netresearch\NrLlm\Exception\InvalidArgumentException;

/**
 * One `Message` (proto `Message`): REQUIRED `messageId`, `role` and at least
 * one part (A2A-P5).
 *
 * The constructor checks the id only. Whether a RECEIVED message had a part
 * is the parser's to judge, before it skips the parts of unknown kinds
 * (A2A-P4); a message built here to be SENT goes through
 * {@see self::assertSendable()}.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aMessage
{
    /**
     * @param list<A2aPart>        $parts
     * @param array<string, mixed> $metadata
     * @param list<string>         $extensions
     * @param list<string>         $referenceTaskIds
     */
    public function __construct(
        public string $messageId,
        public A2aRole $role,
        public array $parts,
        public string $contextId = '',
        public string $taskId = '',
        public array $metadata = [],
        public array $extensions = [],
        public array $referenceTaskIds = [],
    ) {
        if ($messageId === '') {
            throw new InvalidArgumentException('An A2A message needs a messageId.', 1799100001);
        }
    }

    /**
     * A message this client sends: a user message with at least one part.
     *
     * @throws InvalidArgumentException
     */
    public function assertSendable(): void
    {
        if ($this->role !== A2aRole::USER) {
            throw new InvalidArgumentException('A message sent to a remote agent has the role ROLE_USER.', 1799100003);
        }

        if ($this->parts === []) {
            throw new InvalidArgumentException('An A2A message needs at least one part.', 1799100002);
        }
    }

    /**
     * A user message with one text part.
     */
    public static function userText(string $messageId, string $text): self
    {
        return new self($messageId, A2aRole::USER, [A2aPart::text($text)]);
    }

    /**
     * The same message under another id. The send path assigns the id the
     * journal generated, so a caller may build the message before it has one.
     */
    public function withMessageId(string $messageId): self
    {
        return new self(
            $messageId,
            $this->role,
            $this->parts,
            $this->contextId,
            $this->taskId,
            $this->metadata,
            $this->extensions,
            $this->referenceTaskIds,
        );
    }

    /**
     * The text of every text part, in order, joined by a blank line.
     */
    public function text(): string
    {
        $texts = [];
        foreach ($this->parts as $part) {
            if ($part->kind === A2aPartKind::TEXT) {
                $texts[] = $part->text;
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * The ProtoJSON form, in proto field order, empty optional members left out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $json = ['messageId' => $this->messageId];
        if ($this->contextId !== '') {
            $json['contextId'] = $this->contextId;
        }

        if ($this->taskId !== '') {
            $json['taskId'] = $this->taskId;
        }

        $json['role']  = $this->role->value;
        $json['parts'] = array_map(static fn (A2aPart $part): array => $part->toArray(), $this->parts);
        if ($this->metadata !== []) {
            $json['metadata'] = $this->metadata;
        }

        if ($this->extensions !== []) {
            $json['extensions'] = $this->extensions;
        }

        if ($this->referenceTaskIds !== []) {
            $json['referenceTaskIds'] = $this->referenceTaskIds;
        }

        return $json;
    }
}
