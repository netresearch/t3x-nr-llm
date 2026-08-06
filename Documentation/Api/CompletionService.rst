.. include:: /Includes.rst.txt

.. _api-completion-service:

=================
CompletionService
=================

.. _api-feature-services:

.. php:namespace:: Netresearch\NrLlm\Service\Feature

.. php:class:: CompletionService

   High-level text completion with format control.

   .. php:method:: complete(string $prompt, ?ChatOptions $options = null): CompletionResponse

      Standard text completion.

      :param string $prompt: The prompt text
      :param ?ChatOptions $options: Optional configuration
      :returns: CompletionResponse

   .. php:method:: completeJson(string $prompt, ?ChatOptions $options = null): array

      Completion with JSON output parsing.

      :param string $prompt: The prompt text
      :param ?ChatOptions $options: Optional configuration
      :returns: array Parsed JSON data

   .. php:method:: completeMarkdown(string $prompt, ?ChatOptions $options = null): string

      Completion with markdown formatting.

      :param string $prompt: The prompt text
      :param ?ChatOptions $options: Optional configuration
      :returns: string Markdown formatted text

   .. php:method:: completeFactual(string $prompt, ?ChatOptions $options = null): CompletionResponse

      Low-creativity completion for factual responses.

      :param string $prompt: The prompt text
      :param ?ChatOptions $options: Optional configuration (temperature defaults to 0.1)
      :returns: CompletionResponse

   .. php:method:: completeCreative(string $prompt, ?ChatOptions $options = null): CompletionResponse

      High-creativity completion for creative content.

      :param string $prompt: The prompt text
      :param ?ChatOptions $options: Optional configuration (temperature defaults to 1.2)
      :returns: CompletionResponse

   .. php:method:: completeStructured(string $prompt, array $schema, ?ChatOptions $options = null): array

      Completion validated against a JSON schema from the strict named
      subset (:ref:`ADR-126 <adr-126>`): ``type``, ``enum``, ``const``,
      ``pattern``, lengths, numeric bounds, ``items``, ``properties``/
      ``required``/``additionalProperties`` and the combinators —
      ``$ref`` is deliberately out. The schema is pre-flighted BEFORE
      the first provider call (an out-of-subset schema throws code
      ``1784500003`` instead of costing paid requests), enforced
      provider-natively where the provider can (:ref:`ADR-128
      <adr-128>`), validated strictly on the response, and repaired with
      one controlled round-trip on a mismatch.

      :param string $prompt: The prompt text
      :param array $schema: JSON schema inside the strict subset
      :param ?ChatOptions $options: Optional configuration
      :returns: array The decoded, schema-valid JSON payload
      :throws: InvalidArgumentException on an out-of-subset schema
         (``1784500003``) or when the response still fails the schema
         after one repair attempt (``1784500001``)

   Every method above also exists as a ``*ForConfiguration()`` variant
   taking a persisted :php:`LlmConfiguration` as its second argument —
   the call then runs with that configuration's provider, model,
   options and skills instead of the system default.
