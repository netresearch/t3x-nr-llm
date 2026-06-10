.. include:: /Includes.rst.txt

.. _adr-032:

==================================================================
ADR-032: Specialized Usage Tracking and Pricing Catalog
==================================================================

:Status: Accepted
:Date: 2026-06-10
:Authors: Netresearch DTT GmbH

.. _adr-032-context:

Context
=======

The chat/embedding path records complete usage rows: the middleware
pipeline (:ref:`adr-026`) tracks tokens and derives a cost from the
admin-curated ``tx_nrllm_model`` pricing via :php:`Model::estimateCost()`.

The specialised services bypass that pipeline by design — but they
recorded almost nothing. The image services passed metric keys
(``size``, ``quality``, ``count``) that
:php:`UsageTrackerService::trackUsage()` does not map, so only
``request_count = 1`` landed in ``tx_nrllm_service_usage``: no cost, no
tokens, no ``images_generated``, no ``model_id``. TTS recorded
characters but no cost; Whisper recorded nothing but the request.
Consequently the Analytics module, the MonthlyCost widget and
BudgetService systematically excluded all image and speech spend —
defeating the requirement that nr_llm can monitor *total* AI spend.

Two structural problems compounded this:

- the specialised services have no access to model pricing (their
  models — ``gpt-image-2``, ``tts-1``, ``whisper-1`` — usually have no
  ``tx_nrllm_model`` row), and
- ``gpt-image-*`` responses carry a ``usage`` token object (DALL·E
  responses do not), which was discarded.

.. _adr-032-decision:

Decision
========

1. **Real units in the callers.** The services pass the metric keys the
   tracker actually maps: ``images`` (→ ``images_generated``),
   ``characters``, ``audioSeconds`` (→ ``audio_seconds_used``, from the
   ``verbose_json`` Whisper duration), token keys when the response
   reports them, and the model identifier as ``modelId`` (→
   ``model_id``). Provider strings drop the ad-hoc ``provider:model``
   suffixes (``dall-e:dall-e-3`` → provider ``dall-e`` + ``model_id``).

2. **Token usage parsing.** :php:`DallEImageService` parses the
   ``usage`` object of ``gpt-image-*`` responses (``input_tokens``,
   ``output_tokens``, ``total_tokens``, ``input_tokens_details``) so
   token aggregates include image calls; DALL·E responses without
   ``usage`` gracefully omit token metrics.

3. **Static price catalog with a DB override.**
   :php:`Specialized\Pricing\OpenAiPriceCatalog` encodes the published
   OpenAI list prices (each constant documents source URL and
   verification date): gpt-image-* token prices and per-image fallback
   estimates, DALL·E per-image prices by quality/size, ``tts-1`` /
   ``tts-1-hd`` per 1M characters, ``whisper-1`` per minute.
   :php:`SpecializedCostCalculator` (injected into
   :php:`AbstractSpecializedService`) resolves in order: admin-curated
   ``tx_nrllm_model`` row matching the model identifier (reusing
   :php:`Model::estimateCost()`, so negotiated prices win) → catalog
   token prices → catalog per-image price → ``0.0``. **Unknown models
   never get a guessed cost** — a zero cost signals "no price data"
   instead of fabricating numbers.

4. **No double counting.** :php:`LlmTranslator` no longer repeats the
   token count on its translation row (the pipeline already records
   tokens and cost on the underlying chat row); it keeps the
   translation-level request/characters view.
   :php:`WhisperTranscriptionService::translateToEnglish()` loses its
   second ``trackUsage()`` call — the dispatch path records the request
   exactly once.

.. _adr-032-consequences:

Consequences
============

- ● Image, TTS and Whisper spend appears in the Analytics module, the
  MonthlyCost widget and BudgetService aggregates — total spend
  monitoring covers all service types.
- ● Costs follow published list prices and can be overridden per model
  by creating a ``tx_nrllm_model`` row with token pricing.
- ◑ The catalog requires manual maintenance when OpenAI changes list
  prices; constants carry source URLs and verification dates to make
  the review mechanical.
- ◑ Analytics grouped by ``service_provider`` now shows ``dall-e`` /
  ``fal`` / ``tts`` / ``whisper`` instead of suffixed variants
  (``dall-e:dall-e-3``); historic rows keep their old strings, the
  model dimension moved to ``model_id``.
- ◑ FAL calls record images but cost ``0.0`` — FAL publishes no static
  list prices for its hosted models.
