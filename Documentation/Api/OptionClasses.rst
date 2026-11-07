.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-options:

==============
Option classes
==============

Option objects are immutable: each ``with…()`` method returns a copy. Keep
that return value. Message arrays are still supported on relevant paths;
option arrays are not a substitute for a typed options object.

.. code-block:: php
   :caption: Keep the returned options

   $options = (new ChatOptions())->withMaxTokens(2000);
   $options = $options->withCallerSource('my_extension', 'summarize');

Shared call controls
====================

.. list-table:: Metadata and policy controls
   :header-rows: 1

   * - Method
     - Default and effect
   * - ``withIdempotencyKey($key)``
     - Unset by default; identifies a retry, separate from response-cache TTL.
   * - ``withCorrelationId($id)``
     - Unset; a non-empty caller ID links the call to telemetry/outcomes.
   * - ``withCallerSource($extension, $operation = '')``
     - Unset; attribution metadata, not authorization or a provider field.
   * - ``withSite($site)``
     - Unset; supplies site context to supported routing/translation paths.
   * - ``withBeUserUid($uid)``
     - Unset; attributes/checks a budget on budget-aware options. It does not
       authenticate a worker or grant group/record access.
   * - ``withPlannedCost($cost)``
     - Unset; non-negative estimated cost for the preflight check, not an
       atomic reservation or provider invoice.

Provider-specific option support still depends on the adapter and operation.
The service forwards call metadata separately; ``toArray()`` is not a complete
serialization of all policy and actor context.

ChatOptions
===========

.. php:namespace:: Netresearch\NrLlm\Service\Option

.. php:class:: ChatOptions

   Typed options for chat operations.

   .. php:staticmethod:: factual()

      Create options optimized for factual responses (temperature: 0.1).

      :returns: ChatOptions

   .. php:staticmethod:: creative()

      Create options for creative content (temperature: 1.2).

      :returns: ChatOptions

   .. php:staticmethod:: balanced()

      Create balanced options (temperature: 0.7).

      :returns: ChatOptions

   .. php:staticmethod:: json()

      Create options for JSON output format.

      :returns: ChatOptions

   .. php:staticmethod:: code()

      Create options optimized for code generation.

      :returns: ChatOptions

   .. php:method:: withTemperature(float $temperature): self

      Set temperature (0.0 - 2.0).

   .. php:method:: withMaxTokens(int $maxTokens): self

      Request a maximum output-token budget. For recognized OpenAI reasoning
      models, the adapter raises values below 16,000 to 16,000 unless the
      applied reasoning effort is ``None``. Thinking consumes that budget
      too. This option is therefore not a universal hard output or cost cap.

   .. php:method:: withTopP(float $topP): self

      Set nucleus sampling parameter.

   .. php:method:: withFrequencyPenalty(float $penalty): self

      Set frequency penalty (-2.0 to 2.0).

   .. php:method:: withPresencePenalty(float $penalty): self

      Set presence penalty (-2.0 to 2.0).

   .. php:method:: withSystemPrompt(string $prompt): self

      Set system prompt.

   .. php:method:: withResponseFormat(string $format): self

      Request an output format: ``text``, ``json`` or ``markdown``.
      Adapter support differs. The current Claude adapter does not implement
      bare ``response_format=json``. Its object-schema path uses a forced
      tool instead. For validated structured output, use
      ``completeStructured()``; local schema validation remains authoritative
      (:ref:`ADR-128 <adr-128>`).

   .. php:method:: withResponseSchema(array $schema): self

      Attach a strict-subset JSON schema (:ref:`ADR-126 <adr-126>`) the
      provider should enforce natively where it can. Set automatically
      by ``completeStructured()``; the local strict validation remains
      authoritative either way.

   .. php:method:: withStopSequences(array $sequences): self

      Set stop sequences the model must not generate past.

   .. php:method:: withProvider(string $provider): self

      Set an explicit registered provider key on the unbound call path. This is
      not a substitute for a database configuration identifier.

   .. php:method:: withModel(string $model): self

      Set specific model.

   .. php:method:: withReasoningEffort(ReasoningEffort $effort): self

      Ask a reasoning model for an amount of thinking: one of the
      :php:`ReasoningEffort` cases ``None``, ``Minimal``, ``Low``,
      ``Medium``, ``High``, ``XHigh`` and ``Max``
      (:ref:`ADR-204 <adr-204>`). It wins over the coarse ``think``
      switch. The adapter maps it to a bundled model profile, not a live
      provider capability query. Profiles do not cover every current model
      correctly: in this revision do not request ``None`` or ``think=false``
      for GPT-6.1 Sol. Its official scale is low/medium/high/xhigh/max;
      the bundled generic GPT-6 profile still admits ``None``. See the
      `OpenAI model reference
      <https://developers.openai.com/api/docs/models/gpt-6.1-sol>`__
      (checked 2026-11-07). GPT-6 Astra's local mapping of ``None`` to ``Low``
      is a separate supported rule.

      ``nrllm_reasoning_effort`` records an applied effort when available.
      Its absence does not prove the provider has no effort setting: the
      model may have no local profile or may use the provider default.

   .. php:method:: toArray(): array

      Convert to array format.

ToolOptions
===========

Extends ``ChatOptions``. ``toolChoice`` and ``parallelToolCalls`` default to
``null`` (no explicit override); ``captureRaw`` defaults to false.
``ToolOptions::auto()`` selects automatic tool choice, but does not choose a
default provider. Use the configuration-bound service or set an explicit
registered provider key. ``withTaskUid()`` supplies task attribution;
``withTextSink()`` attaches a live text callback on supported paths.

EmbeddingOptions
================

``model``, ``provider`` and ``dimensions`` default to ``null``. ``cacheTtl``
defaults to 86400 seconds. Use ``withCacheTtl()`` or ``noCache()`` to change
caching, ``withDimensions()`` to request a supported vector size, and the
``compact()`` / ``highPrecision()`` factories for the offered presets.
Model limits and adapter support still apply; see :ref:`api-embedding-service`.

VisionOptions
=============

``detailLevel``, ``maxTokens``, ``temperature``, ``provider`` and ``model``
default to ``null``. ``withDetailLevel()`` asks for the supported image-detail
mode; it does not make unsupported image input available on another adapter.
Budget attribution uses the shared controls. See :ref:`api-vision-service`.

TranslationOptions
==================

``preserveFormatting`` defaults to true. Provider/model/configuration,
languages' glossary/context controls, ``tagHandling`` and ``cacheTtl`` are
unset by default; polishing is opt-in. ``withConfiguration()`` selects the
translator configuration on the specialized path, not automatically the
polisher's configuration. See :ref:`api-translation-options` for the full
method-specific options and :ref:`api-translation-paths` for result types.
