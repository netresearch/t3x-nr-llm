<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Privacy;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;

/**
 * Applies the central privacy level to an agent-run step payload before it is
 * persisted (ADR-064 applied to ADR-081).
 *
 * A recorded step is the richest content the extension produces: the messages
 * sent, the model's answer and reasoning, the arguments the model passed to a
 * tool, the tool's output and — when raw capture was on — the provider's whole
 * response body. Persisting all of that on every run turns the event stream into
 * a long-lived prompt archive, so the stored payload follows the configured
 * level instead of the wire format:
 *
 * - NONE / METADATA (default): structural metadata only. Timings, token counts,
 *   cost, finish reason, tool names and sizes survive; every content-bearing
 *   value is dropped. The trace stays useful for cost and failure analysis.
 * - REDACTED: content is kept but passed through {@see ContentRedactor}, which
 *   masks obvious credentials and caps length.
 * - FULL: stored verbatim. Choose it deliberately — this is the full transcript.
 *
 * The live playground display is unaffected: it renders the step from memory,
 * not from the persisted copy. The resumable {@see \Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState}
 * is likewise untouched — it is functional state, not an audit record, and the
 * repository blanks it the moment the run reaches a terminal status.
 */
final readonly class RunStepPrivacyFilter
{
    /**
     * Keys whose values are content: prompts, answers, reasoning, tool
     * arguments, tool output, raw provider bodies.
     */
    private const CONTENT_KEYS = [
        'messagesSent',
        'content',
        'thinking',
        'requestedToolCalls',
        'raw',
        'toolArguments',
        'toolResult',
    ];

    public function __construct(
        private PrivacyPolicyInterface $policy,
        private ContentRedactor $redactor,
    ) {}

    /**
     * @param array<string, mixed> $payload as produced by RunStep::toArray()
     *
     * @return array<string, mixed>
     */
    public function filter(array $payload): array
    {
        return match ($this->policy->level()) {
            PrivacyLevel::FULL => $payload,
            PrivacyLevel::REDACTED => $this->redactPayload($payload),
            PrivacyLevel::NONE, PrivacyLevel::METADATA => $this->metadataOnly($payload),
        };
    }

    /**
     * Strip every content-bearing value, replacing it with a size or a name so
     * the step stays readable as an audit record.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function metadataOnly(array $payload): array
    {
        $out = $payload;

        foreach (self::CONTENT_KEYS as $key) {
            unset($out[$key]);
        }

        $out['contentRedacted'] = true;

        if (is_array($payload['messagesSent'] ?? null)) {
            $out['messagesSentCount'] = count($payload['messagesSent']);
        }

        if (is_string($payload['content'] ?? null)) {
            $out['contentLength'] = mb_strlen($payload['content']);
        }

        if (is_string($payload['thinking'] ?? null)) {
            $out['thinkingLength'] = mb_strlen($payload['thinking']);
        }

        if (is_string($payload['toolResult'] ?? null)) {
            $out['toolResultLength'] = mb_strlen($payload['toolResult']);
        }

        $requested = $payload['requestedToolCalls'] ?? null;
        if (is_array($requested)) {
            $names = [];
            foreach ($requested as $call) {
                if (is_array($call) && is_string($call['name'] ?? null)) {
                    $names[] = $call['name'];
                }
            }

            $out['requestedToolNames'] = $names;
        }

        return $out;
    }

    /**
     * Keep the shape, redact every string leaf.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function redactPayload(array $payload): array
    {
        $out = $payload;

        foreach (self::CONTENT_KEYS as $key) {
            if (array_key_exists($key, $out)) {
                $out[$key] = $this->redactValue($out[$key]);
            }
        }

        return $out;
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->redactor->redact($value);
        }

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $redacted[$key] = $this->redactValue($item);
            }

            return $redacted;
        }

        return $value;
    }
}
