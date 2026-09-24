.. include:: /Includes.rst.txt

.. _api-options:

==============
Option classes
==============

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

      Set maximum output tokens.

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
      ``json`` activates the provider's native JSON mode on every
      adapter (:ref:`ADR-128 <adr-128>`).

   .. php:method:: withResponseSchema(array $schema): self

      Attach a strict-subset JSON schema (:ref:`ADR-126 <adr-126>`) the
      provider should enforce natively where it can. Set automatically
      by ``completeStructured()``; the local strict validation remains
      authoritative either way.

   .. php:method:: withStopSequences(array $sequences): self

      Set stop sequences the model must not generate past.

   .. php:method:: withProvider(string $provider): self

      Set provider (openai, claude, gemini).

   .. php:method:: withModel(string $model): self

      Set specific model.

   .. php:method:: withReasoningEffort(ReasoningEffort $effort): self

      Ask a reasoning model for an amount of thinking: one of the
      :php:`ReasoningEffort` cases ``None``, ``Minimal``, ``Low``,
      ``Medium``, ``High``, ``XHigh`` and ``Max``
      (:ref:`ADR-204 <adr-204>`). It wins over the coarse ``think``
      switch. An effort the model does not allow is moved onto its own
      scale before the request is sent — ``None`` becomes ``Low`` on
      GPT-6 Astra — and the applied value is written to the response
      metadata under ``nrllm_reasoning_effort``. Only OpenAI's GPT-6
      models have an effort scale today; every other model and provider
      ignores the option and writes no such key.

   .. php:method:: toArray(): array

      Convert to array format.
