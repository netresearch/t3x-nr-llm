.. include:: /Includes.rst.txt

.. _api-llm-service-manager:

=================
LlmServiceManager
=================

The central service for all LLM operations.

.. php:namespace:: Netresearch\NrLlm\Service

.. php:class:: LlmServiceManager

   Orchestrates LLM providers and provides unified API access.

   .. php:method:: chat(array $messages, ?ChatOptions $options = null): CompletionResponse

      Execute a chat completion request.

      :param array $messages: Array of message objects
         with 'role' and 'content' keys
      :param ChatOptions|null $options: Optional config
      :returns: CompletionResponse

      **Message Format:**

      .. code-block:: php
         :caption: Chat message format

         $messages = [
             ['role' => 'system', 'content' => '...'],
             ['role' => 'user', 'content' => 'Hello!'],
             ['role' => 'assistant', 'content' => 'Hi!'],
             ['role' => 'user', 'content' => 'How are you?'],
         ];

   .. php:method:: complete(string $prompt, ?ChatOptions $options = null): CompletionResponse

      Simple completion from a single prompt.

      :param string $prompt: The prompt text
      :param ChatOptions|null $options: Optional config
      :returns: CompletionResponse

   .. php:method:: embed(string|array $input, ?EmbeddingOptions $options = null): EmbeddingResponse

      Generate embeddings for text.

      :param string|array $input: Single text or array
         of texts
      :param EmbeddingOptions|null $options: Optional
         configuration
      :returns: EmbeddingResponse

   .. php:method:: vision(array $content, ?VisionOptions $options = null): VisionResponse

      Analyze an image with vision capabilities.

      :param array $content: Array of content parts
         (text and image_url entries)
      :param VisionOptions|null $options: Optional
         configuration
      :returns: VisionResponse

   .. php:method:: streamChat(array $messages, ?ChatOptions $options = null): Generator

      Stream a chat completion response.

      :param array $messages: Array of message objects
      :param ChatOptions|null $options: Optional config
      :returns: Generator yielding string chunks

   .. php:method:: chatWithTools(array $messages, array $tools, ?ToolOptions $options = null): CompletionResponse

      Chat with tool/function calling capability.

      :param array $messages: Array of message objects
      :param array $tools: Array of tool definitions
      :param ToolOptions|null $options: Optional config
      :returns: CompletionResponse with tool calls

   .. php:method:: getProvider(?string $identifier = null): ProviderInterface

      Get a specific provider by identifier.

      :param string|null $identifier: Provider identifier
         (openai, claude, gemini); null for default
      :returns: ProviderInterface
      :throws: ProviderException

   .. php:method:: getAvailableProviders(): array

      Get all configured and available providers.

      :returns: array<string, ProviderInterface>
