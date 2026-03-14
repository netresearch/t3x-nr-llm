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

   .. php:method:: isConfigured(): bool

      Check if provider has required configuration.

   .. php:method:: chatCompletion(array $messages, array $options = []): CompletionResponse

      Execute chat completion.

   .. php:method:: getAvailableModels(): array

      Get list of available models.

.. php:interface:: EmbeddingCapableInterface

   Contract for providers supporting embeddings.

   .. php:method:: embeddings(string|array $input, array $options = []): EmbeddingResponse

      Generate embeddings.

.. php:interface:: VisionCapableInterface

   Contract for providers supporting vision/image analysis.

   .. php:method:: analyzeImage(string $imageUrl, string $prompt, array $options = []): CompletionResponse

      Analyze an image.

.. php:interface:: StreamingCapableInterface

   Contract for providers supporting streaming.

   .. php:method:: streamChatCompletion(array $messages, array $options = []): Generator

      Stream chat completion.

.. php:interface:: ToolCapableInterface

   Contract for providers supporting tool/function calling.

   .. php:method:: chatWithTools(array $messages, array $tools, array $options = []): CompletionResponse

      Chat with tool calling.
