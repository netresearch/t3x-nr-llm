.. include:: /Includes.rst.txt

.. _api-llm-service-manager:

=================
LlmServiceManager
=================

The central service for all LLM operations.

.. php:namespace:: Netresearch\NrLlm\Service

.. php:class:: LlmServiceManager

   Orchestrates LLM providers and provides unified API access.

   .. php:method:: chat(array $messages, array|ChatOptions $options = []): CompletionResponse

      Execute a chat completion request.

      :param array $messages: Array of message objects with 'role' and 'content' keys
      :param array|ChatOptions $options: Optional configuration
      :returns: CompletionResponse

      **Message Format:**

      .. code-block:: php
         :caption: Chat message format

         $messages = [
             ['role' => 'system', 'content' => 'You are a helpful assistant.'],
             ['role' => 'user', 'content' => 'Hello!'],
             ['role' => 'assistant', 'content' => 'Hi there!'],
             ['role' => 'user', 'content' => 'How are you?'],
         ];

   .. php:method:: complete(string $prompt, array|ChatOptions $options = []): CompletionResponse

      Simple completion from a single prompt.

      :param string $prompt: The prompt text
      :param array|ChatOptions $options: Optional configuration
      :returns: CompletionResponse

   .. php:method:: embed(string|array $text, array $options = []): EmbeddingResponse

      Generate embeddings for text.

      :param string|array $text: Single text or array of texts
      :param array $options: Optional configuration
      :returns: EmbeddingResponse

   .. php:method:: streamChat(array $messages, array $options = []): Generator

      Stream a chat completion response.

      :param array $messages: Array of message objects
      :param array $options: Optional configuration
      :returns: Generator yielding string chunks

   .. php:method:: chatWithTools(array $messages, array $tools, array $options = []): CompletionResponse

      Chat with tool/function calling capability.

      :param array $messages: Array of message objects
      :param array $tools: Array of tool definitions
      :param array $options: Optional configuration
      :returns: CompletionResponse with potential tool calls

   .. php:method:: getProvider(string $identifier): ProviderInterface

      Get a specific provider by identifier.

      :param string $identifier: Provider identifier (openai, claude, gemini)
      :returns: ProviderInterface
      :throws: ProviderNotFoundException

   .. php:method:: getAvailableProviders(): array

      Get all configured and available providers.

      :returns: array<string, ProviderInterface>
