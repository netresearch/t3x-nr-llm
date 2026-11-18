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
        'toolArtifacts',
        // The unkeyed identity of the request an answer is to (ADR-323): a
        // hash of the content, so never stored as it is.
        'requestIdentity',
    ];

    /**
     * Set on a payload filtered at `redacted`: its content is masked text,
     * which a later `metadata` pass must not digest (ADR-323). Not shown in
     * the run timeline.
     */
    private const MASKED = 'contentMasked';

    /**
     * The keyed digests a step keeps of its content at the `metadata` level
     * when the operator opted in (ADR-323); no other level keeps them.
     */
    private const DIGEST_KEYS = [
        'messagesSentDigests',
        'contentDigest',
        'toolArgumentsDigest',
        'toolResultDigest',
        'requestDigest',
    ];

    public function __construct(
        private PrivacyPolicyInterface $policy,
        private ContentRedactor $redactor,
    ) {}

    /**
     * @param array<string, mixed> $payload as produced by RunStep::toArray()
     * @param PrivacyLevel|null    $level   the level of this run (ADR-242), null for the global one
     *
     * @return array<string, mixed>
     */
    public function filter(array $payload, ?PrivacyLevel $level = null): array
    {
        return match ($level ?? $this->policy->level()) {
            // The text itself is kept; the unkeyed request identity is not,
            // at any level (ADR-323).
            PrivacyLevel::FULL     => $this->withoutDigests($payload),
            PrivacyLevel::REDACTED => $this->withoutDigests($this->redactPayload($payload)),
            // A payload filtered at `redacted` before (the run's own level,
            // then a stricter provider ceiling, ADR-270) holds masked and
            // truncated text: a digest of it would make different inputs
            // equal, so it gets none.
            PrivacyLevel::METADATA => $this->policy->inputDigests() && ($payload[self::MASKED] ?? false) !== true
                ? $this->withDigests($payload, $this->metadataOnly($payload))
                : $this->withoutDigests($this->metadataOnly($payload)),
            PrivacyLevel::NONE => $this->withoutDigests($this->metadataOnly($payload)),
        };
    }

    /**
     * The step without content digests and without the request identity: what
     * every level but an opted-in `metadata` keeps, including a payload filtered
     * again at a stricter level after it was stored with digests.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function withoutDigests(array $payload): array
    {
        foreach ([...self::DIGEST_KEYS, 'requestIdentity'] as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    /**
     * The metadata-only step with a keyed digest of each part of the content
     * it dropped (ADR-323). A payload stored before keeps the digests it has.
     * A part without a digest (no encryption key) gets no key, never an
     * unkeyed hash.
     *
     * @param array<string, mixed> $content the step before filtering
     * @param array<string, mixed> $kept    the step as metadataOnly() keeps it
     *
     * @return array<string, mixed>
     */
    private function withDigests(array $content, array $kept): array
    {
        $messages = $content['messagesSent'] ?? null;
        if (is_array($messages)) {
            $digests = RunContentDigests::messages($messages);
            if ($digests !== null) {
                $kept['messagesSentDigests'] = $digests;
            }
        }

        $parts = [
            'contentDigest'       => is_string($content['content'] ?? null) ? $content['content'] : null,
            'toolArgumentsDigest' => is_array($content['toolArguments'] ?? null) ? $content['toolArguments'] : null,
            'toolResultDigest'    => is_string($content['toolResult'] ?? null) ? $content['toolResult'] : null,
            'requestDigest'       => is_string($content['requestIdentity'] ?? null) && $content['requestIdentity'] !== '' ? $content['requestIdentity'] : null,
        ];
        foreach ($parts as $key => $part) {
            $digest = $part === null ? null : RunContentDigests::of($part);
            if ($digest !== null) {
                $kept[$key] = $digest;
            }
        }

        return $kept;
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

        $artifacts = $payload['toolArtifacts'] ?? null;
        if (is_array($artifacts)) {
            $out['toolArtifactsCount'] = count($artifacts);
            $types                     = [];
            foreach ($artifacts as $artifact) {
                if (is_array($artifact) && is_string($artifact['type'] ?? null)) {
                    $types[] = $artifact['type'];
                }
            }

            $out['toolArtifactTypes'] = $types;
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

        $out[self::MASKED] = true;

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
