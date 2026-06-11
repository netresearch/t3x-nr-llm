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

4. **Configurations stay chat-scoped by design.** The third tier of
   :ref:`adr-013` (Configuration bundles: system prompt, temperature,
   token limits, budgets) only makes sense for conversational models.
   Image/TTS/transcription use Model records plus capability-based
   default resolution — no Configuration records are created for them.

.. _adr-033-consequences:

Consequences
============

- ● Image, TTS and transcription models are first-class registry
  citizens: curated, activatable, default-flagged and visible in the
  backend Models module like chat models.
- ● Consuming extensions resolve the instance-preferred specialized
  model via ``resolveDefaultModel()`` instead of hardcoding one, with
  a guaranteed-safe fallback.
- ● Analytics model breakdowns link specialized spend to registry
  records via ``model_uid``.
- ◐ Hardcoded service defaults remain as fallbacks — instances without
  curated records keep working unchanged.
- ◑ One additional fail-soft repository lookup per tracked specialized
  call (indexed single-row query; negligible next to the API call).
