.. include:: /Includes.rst.txt

.. _adr-033:

==================================================================
ADR-033: Specialized Models in the Model Registry
==================================================================

:Status: Accepted
:Date: 2026-06-11
:Authors: Netresearch DTT GmbH

.. _adr-033-context:

Context
=======

The backend Models module manages ``tx_nrllm_model`` records for the
chat/embedding pipeline, but the specialized services (image
generation, text-to-speech, transcription — :ref:`adr-030`,
:ref:`adr-032`) selected their models from hardcoded constants
(``dall-e-3``, ``tts-1``, ``whisper-1``) and never consulted the
registry. Image and speech models were therefore invisible in the
backend: administrators could not curate them, mark a preferred
default, or see usage linked to a record. Consuming extensions had no
way to ask "which image model should I use on this instance?".

.. _adr-033-decision:

Decision
========

1. **Specialized capabilities.** :php:`ModelCapability` gains
   ``IMAGE``, ``TEXT_TO_SPEECH`` and ``TRANSCRIPTION`` cases, exposed
   in the ``tx_nrllm_model`` TCA capabilities select, the BE group
   capability permissions and the model-picker capability badges.
   Image, TTS and transcription models are regular registry records.

2. **Capability-based default resolution.**
   :php:`DallEImageService`, :php:`TextToSpeechService` and
   :php:`WhisperTranscriptionService` expose
   :php:`resolveDefaultModel(string $fallback): string`: ACTIVE
   registry records carrying the service's capability are considered
   provider-agnostically; an ``is_default`` record wins, then the
   lowest ``sorting``; the record's ``model_id`` is returned.
   Fail-soft — any error, missing repository, or no matching record
   returns the fallback unchanged; the method never throws (the same
   posture as :php:`SpecializedCostCalculator`, :ref:`adr-032`).

3. **Usage linkage.** Specialized usage rows now carry the matching
   registry record's uid as ``model_uid`` (resolved fail-soft from the
   used ``model_id``), so the Analytics model breakdowns link image and
   speech spend to the curated records; ``0`` remains the value for
   models without a registry record.

4. **Configuration-based resolution for specialized services.**
   ``tx_nrllm_configuration`` records are the stable indirection layer
   for image/TTS/transcription exactly as for chat: a consumer
   references a configuration by identifier, the administrator swaps
   the assigned model (or adjusts the system prompt) on the record, and
   every consumer picks it up without re-configuring anything. The
   three services expose the consumer-facing API

   - :php:`resolveModelForConfiguration(string $configurationIdentifier, string $fallback): string`
     — resolution order: the ACTIVE configuration's ACTIVE model
     record's ``model_id`` (records with an empty ``model_id`` are
     skipped) → the capability-based registry default (decision 2) →
     the given fallback. Fail-soft, never throws.
   - :php:`getConfigurationSystemPrompt(string $configurationIdentifier): string`
     — the configuration's system prompt; the empty string when the
     configuration is unknown, inactive, or unreadable. The prompt is
     *returned to the consumer*, never injected implicitly, so the
     consumer always records the exact prompt it sent (transparency
     requirement).

   For image generation the model MUST be resolved *before* the
   options object is constructed: :php:`ImageGenerationOptions`
   validates ``size`` against the concrete model value at construction
   time.

5. **Usage attribution per configuration.** The specialized options
   DTOs (:php:`ImageGenerationOptions`, :php:`SpeechSynthesisOptions`,
   :php:`TranscriptionOptions`) carry an optional ``configuration``
   identifier — pure metadata that never reaches the upstream API and
   never alters validation. When set, the services resolve the
   configuration uid fail-soft and pass it as ``configurationUid`` to
   ``trackUsage()``, so the Analytics module aggregates specialized
   spend per configuration just like chat spend.

6. **Snippet-enforcement hook (Phase 2).** The planned prompt-snippet
   feature (pinning/enforcing prompt snippets) attaches at the
   Configuration level. ``getConfigurationSystemPrompt()`` is the
   single seam where enforced snippets will be folded into the
   returned prompt — consumers keep calling the same method and stay
   unchanged when Phase 2 lands.

.. _adr-033-consequences:

Consequences
============

- ● Image, TTS and transcription models are first-class registry
  citizens: curated, activatable, default-flagged and visible in the
  backend Models module like chat models.
- ● Consuming extensions resolve the instance-preferred specialized
  model via ``resolveDefaultModel()`` instead of hardcoding one, with
  a guaranteed-safe fallback.
- ● Configurations are the stable consumer contract for specialized
  calls too: model swaps and system-prompt changes are central,
  one-record edits — no consumer redeployment.
- ● Analytics model breakdowns link specialized spend to registry
  records via ``model_uid`` and to configurations via
  ``configuration_uid``.
- ◐ Hardcoded service defaults remain as fallbacks — instances without
  curated records keep working unchanged.
- ◑ Up to two additional fail-soft repository lookups per tracked
  specialized call (indexed single-row queries; negligible next to the
  API call).
