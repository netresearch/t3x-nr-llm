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

      Configure the provider with API key and settings.

      :param array $config: Configuration key-value pairs

   .. php:method:: isAvailable(): bool

      Check if provider is available and configured.

   .. php:method:: supportsFeature(string|ModelCapability $feature): bool

      Check if provider supports a specific feature.

   .. php:method:: chatCompletion(array $messages, array $options = []): CompletionResponse

      Execute chat completion.

      :param array $messages: Messages with ``role`` and ``content``.
         Content can be a string (plain text) or an array of content
         blocks for multimodal input (text, image_url, document).

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

      :param array $content: Array of content parts
         (text and image_url entries)
      :param array $options: Optional configuration
      :returns: VisionResponse

   .. php:method:: supportsVision(): bool

      Check if vision is supported.

   .. php:method:: getSupportedImageFormats(): array

      Get supported image formats.

   .. php:method:: getMaxImageSize(): int

      Get maximum image size in bytes.

.. php:interface:: StreamingCapableInterface

   Contract for providers supporting streaming.

   .. php:method:: streamChatCompletion(array $messages, array $options = []): Generator

      Stream chat completion.

   .. php:method:: supportsStreaming(): bool

      Check if streaming is supported.

.. php:interface:: ToolCapableInterface

   Contract for providers supporting tool/function
   calling.

   .. php:method:: chatCompletionWithTools(array $messages, array $tools, array $options = []): CompletionResponse

      Chat with tool calling. Messages support multimodal content
      (string or array of content blocks).

   .. php:method:: supportsTools(): bool

      Check if tool calling is supported.
