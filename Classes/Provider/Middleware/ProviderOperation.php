<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

/**
 * Identifies which provider operation a middleware call corresponds to.
 *
 * Middleware uses this to decide whether it applies (e.g. a cache middleware
 * that caches deterministic embeddings but passes through stateful chat
 * completions) and to emit meaningful log / trace / metric labels.
 */
enum ProviderOperation: string
{
    case Chat = 'chat';
    case Completion = 'complete';
    case Embedding = 'embed';
    case Vision = 'vision';
    case Tools = 'tools';
    case Stream = 'stream';

    // Specialized-service operations (ADR-096): image, speech and translation
    // calls that reach the pipeline through a service context rather than an
    // LlmConfiguration entity. Kept in the same enum so telemetry, usage and the
    // failure classifier label every AI call from one vocabulary.
    case ImageGeneration = 'image';
    case ImageEdit = 'image_edit';
    case ImageVariation = 'image_variation';
    case Transcription = 'transcribe';
    case SpeechSynthesis = 'speech';
    case Translation = 'translate';

    // A provider metadata / status call that is not itself an AI generation —
    // DeepL usage and glossary lookups. Routed through the pipeline so it is
    // observable and guarded by the circuit breaker like any provider HTTP call,
    // but labelled honestly rather than as a translation.
    case Metadata = 'metadata';
}
