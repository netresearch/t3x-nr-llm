<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * What a model record CLAIMS it can do.
 *
 * **This is a vocabulary, not an inventory.** A case existing here does not
 * mean any model on an installation declares it: discovery writes the subset
 * each provider's own response substantiates, an administrator may tick any
 * box by hand, and two cases are written by nobody at all. The consequence
 * is the reason this docblock exists — a configuration whose criteria REQUIRE
 * a capability nothing writes matches no model, silently, because
 * :php:`EligibilityEvaluator::matchesCapabilities()` demands every listed one
 * and reports no candidates rather than an unsatisfiable requirement (#913).
 *
 * So: require in criteria only what producers actually write. Which those are
 * is measured, not assumed — `CapabilitySeedTest` runs every discoverer and
 * fails the build when a case outside its documented exception list is
 * written by none of them. `OperationCapabilityMap` records the same
 * distinction from the enforcement side, and states per capability why it is
 * or is not enforced for an operation.
 *
 * Per case, as measured:
 *
 * - CHAT — the only token every producer writes. Enforced for the Chat
 *   operation when `routing.operationCapabilityEnforcement` is on.
 * - COMPLETION — a synonym of CHAT here, and deliberately not seeded for that
 *   reason. `CompletionService::complete()` delegates to
 *   `LlmServiceManager::chat()`, and every adapter posts to
 *   `chat/completions`; no legacy completions endpoint is called anywhere.
 *   Seeding it would give chat models a second token with the same meaning,
 *   so two criteria sets differing only in which one they name would select
 *   differently for no reason. Ollama makes that judgement already: it reads
 *   a `completion` token from its own listing and writes CHAT. Reaches
 *   records only by hand (the ddev seed).
 * - EMBEDDINGS — written by Gemini and Ollama, by nobody else.
 * - VISION — written by the providers that report it per model and by the
 *   curated tables. Enforced for the Vision operation.
 * - STREAMING — written by Gemini and the curated entries; models from
 *   Ollama, Groq and OpenRouter stream perfectly well and carry no token, so
 *   its absence is a gap, not a statement.
 * - TOOLS — written by the providers that report it and by the curated
 *   tables. Enforced for the Tools operation.
 * - JSON_MODE — a REQUEST OPTION rather than something a model can be asked
 *   to do, and the one case written by no discoverer. `ChatOptions` carries
 *   `responseFormat` to the provider unconditionally and no call path
 *   consults the capability, so as a criterion it can only subtract models.
 *   Populating it would assert per model that the provider honours
 *   `response_format`, which fails at CALL time when it does not — worse than
 *   leaving it empty. Removing the case belongs to the 1.0 API freeze (#895).
 * - AUDIO — a chat-completions modality: `gpt-4o-audio-preview` takes and
 *   returns speech inside an ordinary chat call, which is neither
 *   TEXT_TO_SPEECH nor TRANSCRIPTION — those name the dedicated TTS and
 *   Whisper endpoints, with their own request shape and credential. Written
 *   by the OpenAI discoverer, seeded from the model id because
 *   `GET /v1/models` describes no capabilities. Nothing on the call path
 *   branches on it, so a wrong assignment widens selection rather than
 *   breaking a call — and no adapter can carry audio yet: `ChatMessage` takes
 *   a `string $content` and has no content parts.
 * - IMAGE, TEXT_TO_SPEECH, TRANSCRIPTION — written by the OpenAI discoverer
 *   alone, for its specialized entries. They describe services outside
 *   criteria-mode selection (ADR-138), so no operation enforces them.
 *
 * @api
 */
enum ModelCapability: string
{
    case CHAT = 'chat';
    case COMPLETION = 'completion';
    case EMBEDDINGS = 'embeddings';
    case VISION = 'vision';
    case STREAMING = 'streaming';
    case TOOLS = 'tools';
    case JSON_MODE = 'json_mode';
    case AUDIO = 'audio';
    case IMAGE = 'image';
    case TEXT_TO_SPEECH = 'text_to_speech';
    case TRANSCRIPTION = 'transcription';

    /**
     * Get all capability values as an array.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn(self $case): string => $case->value,
            self::cases(),
        );
    }

    /**
     * Check if a given string is a valid capability.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * Try to create from string, returns null if invalid.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
