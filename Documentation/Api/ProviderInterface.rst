.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-providers:

==================
Provider interface
==================

.. php:namespace:: Netresearch\NrLlm\Provider\Contract

.. php:interface:: ProviderInterface

   Contract for LLM providers.

   .. php:method:: getName(): string

      Get human-readable provider name.

   .. php:method:: getIdentifier(): string

      Get provider identifier for configuration.

   .. php:method:: configure(array $config): void

      Configure the provider with its Vault credential identifier and settings.

      :param array $config: Configuration key-value pairs

   .. php:method:: isAvailable(): bool

      Check if provider is available and configured.

   .. php:method:: supportsFeature(string|ModelCapability $feature): bool

      Check if provider supports a specific feature.

   .. php:method:: chatCompletion(array $messages, array $options = []): CompletionResponse

      Execute chat completion.

      :param array $messages: A list of typed ``ChatMessage`` values or legacy
         arrays with ``role`` and ``content``. Rich provider-specific arrays
         retain their additional fields, including tool results and multimodal
         content. Implementations normalize typed messages with ``toArray()``.

   .. php:method:: complete(string $prompt, array $options = []): CompletionResponse

      Execute simple completion from a prompt.

   .. php:method:: embeddings(string|array $input, array $options = []): EmbeddingResponse

      Generate embeddings for text.

   .. php:method:: getAvailableModels(): array

      Get list of available models.

   .. php:method:: getDefaultModel(): string

      Get the default model identifier.

   .. php:method:: testConnection(): array

      Test the connection to the provider.

      :returns: array{success, message, models?}
      :throws: ProviderConnectionException

.. php:interface:: VisionCapableInterface

   Contract for providers supporting vision/image
   analysis.

   .. php:method:: analyzeImage(array $content, array $options = []): VisionResponse

      Analyze an image.

      :param array $content: A list of typed ``VisionContent`` values.
         ``LlmServiceManager::vision()`` normalizes legacy content-part arrays
         before calling the provider; direct adapter calls use typed values.
      :param array $options: Optional configuration
      :returns: VisionResponse

   .. php:method:: supportsVision(): bool

      Check if vision is supported.

   .. php:method:: getSupportedImageFormats(): array

      Get supported image formats.

   .. php:method:: getMaxImageSize(): int

      Get maximum image size in bytes.

.. _api-provider-streaming:

.. php:interface:: StreamingCapableInterface

   Contract for providers supporting streaming.

   .. php:method:: streamChatCompletion(array $messages, array $options = []): Generator

      Stream chat completion.

   .. php:method:: supportsStreaming(): bool

      Check if streaming is supported.

.. _api-provider-tools:

.. php:interface:: ToolCapableInterface

   Contract for providers supporting tool/function
   calling.

   .. php:method:: chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse

      Chat with tool calling. Messages support multimodal content
      (string or array of content blocks).

      :param array $messages: Typed ``ChatMessage`` values or provider-specific
         legacy message arrays
      :param array $tools: A list of typed ``ToolSpec`` values.
         ``LlmServiceManager::chatWithTools()`` normalizes legacy tool arrays
         before calling the provider.

   .. php:method:: supportsTools(): bool

      Check if tool calling is supported.

.. php:interface:: DocumentCapableInterface

   Optional capability for Base64 inline document content in chat messages.
   It adds capability discovery, not a separate completion method.

   .. php:method:: supportsDocuments(): bool

      Check if inline document input is supported.

   .. php:method:: getSupportedDocumentFormats(): array

      Return supported document formats, for example ``['pdf']``.

.. php:interface:: DecisionCapableInterface

   Optional capability for providers that natively answer typed questions
   about a screened subject (ADR-211).

   .. php:method:: decide(DecisionSubject $subject, array $questions, array $options = []): DecisionResponse

      :param DecisionSubject $subject: Typed subject to evaluate
      :param array $questions: A list of ``DecisionQuestion`` values
      :param array $options: Configuration call options, including ``model``
      :returns: Typed answers with one valid answer per question
      :throws: InvalidDecisionResponseException for malformed answers;
         ProviderException for other provider failures
