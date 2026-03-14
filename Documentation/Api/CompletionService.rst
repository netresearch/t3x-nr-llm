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
