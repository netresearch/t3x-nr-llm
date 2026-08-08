<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;

/**
 * Which model capability an operation requires (ADR-138).
 *
 * The two vocabularies were never connected: `ProviderOperation` labels the
 * call the pipeline is running, `ModelCapability` labels what a `Model` record
 * declares it can do. Criteria-mode selection matched only the stored criteria,
 * so a configuration whose criteria never mention tools could resolve a model
 * that cannot do tools, and the mismatch surfaced as a provider error.
 *
 * Pure and stateless — a static lookup, no state, no dependencies.
 *
 * **Null means "requires nothing", and the map is deliberately narrower than
 * the vocabulary allows.** A requirement is only worth enforcing when the
 * records it reads are actually written. Three capabilities pass that test:
 * every model discoverer writes `chat`, and writes `vision` / `tools` when the
 * model has them, so a record lacking one of those is a statement, not a gap.
 *
 * The rest do not, and enforcing them would refuse working models:
 *
 * - `completion` and `embeddings` are written by NO discoverer. The seven
 *   wizard discoverers write `chat[,vision,tools,streaming,reasoning]` and
 *   nothing else; `completion` appears only in hand-written records such as the
 *   ddev seed. Requiring `completion` would break the primary completion path
 *   for every wizard-created corpus, and requiring `embeddings` would break
 *   every criteria-mode embedding configuration — for a fact nobody stated.
 * - `streaming` is written by four of the seven discoverers (OpenAI, Anthropic,
 *   Gemini, Mistral) and by none of Ollama, Groq or OpenRouter, whose models
 *   stream perfectly well. Enforcing it would refuse a correct model because
 *   its own discoverer never filled the field.
 * - The image / speech / transcription capabilities describe specialized
 *   services that do not reach criteria-mode selection at all (ADR-138 states
 *   that boundary), so a mapping here would be inert.
 * - `Translation` is served either by a chat/completion model — which arrives
 *   here as `Chat` / `Completion` — or by DeepL, which is not a `Model` record.
 *   There is no `ModelCapability` for it.
 * - `Metadata` is a provider status/usage lookup, deliberately not an AI
 *   generation (see the enum's own note), so it constrains nothing.
 *
 * Widen the map when the producers catch up, not before.
 */
final class OperationCapabilityMap
{
    /**
     * The capability a model must declare to serve this operation, or null
     * when the operation requires none.
     */
    public static function capabilityFor(ProviderOperation $operation): ?ModelCapability
    {
        return match ($operation) {
            ProviderOperation::Chat   => ModelCapability::CHAT,
            ProviderOperation::Vision => ModelCapability::VISION,
            ProviderOperation::Tools  => ModelCapability::TOOLS,

            // Not enforced — see the class docblock for the producer gap behind
            // each one. Listed explicitly rather than swept into a default arm
            // so adding a ProviderOperation case is a compile-time decision.
            ProviderOperation::Completion,
            ProviderOperation::Embedding,
            ProviderOperation::Stream,
            ProviderOperation::ImageGeneration,
            ProviderOperation::ImageEdit,
            ProviderOperation::ImageVariation,
            ProviderOperation::Transcription,
            ProviderOperation::SpeechSynthesis,
            ProviderOperation::Translation,
            ProviderOperation::Metadata => null,
        };
    }
}
