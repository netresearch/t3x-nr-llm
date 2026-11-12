<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use function is_array;
use function is_string;

/**
 * A finding a guardrail reports without stopping the output (ADR-288).
 *
 * A delivered {@see \Netresearch\NrLlm\Domain\Model\CompletionResponse} carries
 * its hints in `metadata[self::METADATA_KEY]`, as a list of
 * {@see self::toArray()} shapes, and a held or annotated stream returns them as
 * the generator's return value. `message` is the sentence an editor reads;
 * `rule` and `passage` are there for a surface that lays them out itself.
 *
 * @api
 */
final readonly class GuardrailHint
{
    public const METADATA_KEY = 'guardrailHints';

    /** The output breaks a rule. */
    public const KIND_VIOLATION = 'violation';

    /** The check did not run or gave no usable answer; the output was not checked. */
    public const KIND_UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $guardrail,
        public string $kind,
        public string $message,
        public string $rule = '',
        public string $passage = '',
    ) {}

    /**
     * @return array{guardrail: string, kind: string, message: string, rule: string, passage: string}
     */
    public function toArray(): array
    {
        return [
            'guardrail' => $this->guardrail,
            'kind'      => $this->kind,
            'message'   => $this->message,
            'rule'      => $this->rule,
            'passage'   => $this->passage,
        ];
    }

    /**
     * The hints a response carries, for a consumer that reads its metadata.
     * Anything that is not the documented shape is skipped.
     *
     * @param array<string, mixed>|null $metadata
     *
     * @return list<self>
     */
    public static function fromMetadata(?array $metadata): array
    {
        $entries = $metadata[self::METADATA_KEY] ?? null;
        if (!is_array($entries)) {
            return [];
        }

        $hints = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['message'] ?? null) || !is_string($entry['kind'] ?? null)) {
                continue;
            }

            $hints[] = new self(
                is_string($entry['guardrail'] ?? null) ? $entry['guardrail'] : '',
                $entry['kind'],
                $entry['message'],
                is_string($entry['rule'] ?? null) ? $entry['rule'] : '',
                is_string($entry['passage'] ?? null) ? $entry['passage'] : '',
            );
        }

        return $hints;
    }

    /**
     * The hints a drained stream returned (ADR-288): the return value of the
     * generator from {@see \Netresearch\NrLlm\Service\LlmServiceManagerInterface::streamChatWithConfiguration()}.
     * Call it only once the stream is drained.
     *
     * @return list<self>
     */
    public static function fromStreamReturn(mixed $returned): array
    {
        if (!is_array($returned)) {
            return [];
        }

        $hints = [];
        foreach ($returned as $hint) {
            if ($hint instanceof self) {
                $hints[] = $hint;
            }
        }

        return $hints;
    }
}
