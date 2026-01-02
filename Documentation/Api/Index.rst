.. include:: /Includes.rst.txt

.. _api-reference:

=============
API Reference
=============

Complete API reference for the TYPO3 LLM Extension.

.. contents::
   :local:
   :depth: 2

.. _api-llm-service-manager:

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

.. _api-feature-services:

Feature Services
================

CompletionService
-----------------

.. php:namespace:: Netresearch\NrLlm\Service\Feature

.. php:class:: CompletionService

   High-level text completion with format control.

   .. php:method:: complete(string $prompt, array $options = []): CompletionResponse

      Standard text completion.

      :param string $prompt: The prompt text
      :param array $options: Optional configuration
      :returns: CompletionResponse

   .. php:method:: completeJson(string $prompt, array $options = []): array

      Completion with JSON output parsing.

      :param string $prompt: The prompt text
      :param array $options: Optional configuration
      :returns: array Parsed JSON data

   .. php:method:: completeMarkdown(string $prompt, array $options = []): string

      Completion with markdown formatting.

      :param string $prompt: The prompt text
      :param array $options: Optional configuration
      :returns: string Markdown formatted text

   .. php:method:: completeFactual(string $prompt, array $options = []): CompletionResponse

      Low-creativity completion for factual responses.

      :param string $prompt: The prompt text
      :param array $options: Optional configuration (temperature defaults to 0.1)
      :returns: CompletionResponse

   .. php:method:: completeCreative(string $prompt, array $options = []): CompletionResponse

      High-creativity completion for creative content.

      :param string $prompt: The prompt text
      :param array $options: Optional configuration (temperature defaults to 1.2)
      :returns: CompletionResponse

EmbeddingService
----------------

.. php:class:: EmbeddingService

   Text-to-vector conversion with caching and similarity operations.

   .. php:method:: embed(string $text): array

      Generate embedding vector for text (cached).

      :param string $text: The text to embed
      :returns: array<float> Vector representation

   .. php:method:: embedFull(string $text): EmbeddingResponse

      Generate embedding with full response metadata.

      :param string $text: The text to embed
      :returns: EmbeddingResponse

   .. php:method:: embedBatch(array $texts): array

      Generate embeddings for multiple texts.

      :param array $texts: Array of texts
      :returns: array<array<float>> Array of vectors

   .. php:method:: cosineSimilarity(array $a, array $b): float

      Calculate cosine similarity between two vectors.

      :param array $a: First vector
      :param array $b: Second vector
      :returns: float Similarity score (-1 to 1)

   .. php:method:: findMostSimilar(array $queryVector, array $candidates, int $topK = 5): array

      Find most similar vectors from candidates.

      :param array $queryVector: The query vector
      :param array $candidates: Array of candidate vectors
      :param int $topK: Number of results to return
      :returns: array Sorted by similarity (highest first)

   .. php:method:: normalize(array $vector): array

      Normalize a vector to unit length.

      :param array $vector: The vector to normalize
      :returns: array Normalized vector

VisionService
-------------

.. php:class:: VisionService

   Image analysis with specialized prompts.

   .. php:method:: generateAltText(string $imageUrl): string

      Generate WCAG-compliant alt text.

      :param string $imageUrl: URL or local path to image
      :returns: string Accessibility-optimized alt text

   .. php:method:: generateTitle(string $imageUrl): string

      Generate SEO-optimized image title.

      :param string $imageUrl: URL or local path to image
      :returns: string SEO-friendly title

   .. php:method:: generateDescription(string $imageUrl): string

      Generate detailed image description.

      :param string $imageUrl: URL or local path to image
      :returns: string Detailed description

   .. php:method:: analyzeImage(string $imageUrl, string $prompt): string

      Custom image analysis with specific prompt.

      :param string $imageUrl: URL or local path to image
      :param string $prompt: Analysis prompt
      :returns: string Analysis result

TranslationService
------------------

.. php:class:: TranslationService

   Language translation with quality control.

   .. php:method:: translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): TranslationResult

      Translate text to target language.

      :param string $text: Text to translate
      :param string $targetLanguage: Target language code (e.g., 'de', 'fr')
      :param string|null $sourceLanguage: Source language code (auto-detected if null)
      :param array $options: Translation options
      :returns: TranslationResult

      **Options:**

      - ``formality``: 'formal', 'informal', 'default'
      - ``domain``: 'technical', 'legal', 'medical', 'general'
      - ``glossary``: array of term translations
      - ``preserve_formatting``: bool

   .. php:method:: translateBatch(array $texts, string $targetLanguage, array $options = []): array

      Translate multiple texts.

      :param array $texts: Array of texts
      :param string $targetLanguage: Target language code
      :param array $options: Translation options
      :returns: array<TranslationResult>

   .. php:method:: detectLanguage(string $text): string

      Detect the language of text.

      :param string $text: Text to analyze
      :returns: string Language code

   .. php:method:: scoreTranslationQuality(string $source, string $translation, string $targetLanguage): float

      Score translation quality.

      :param string $source: Original text
      :param string $translation: Translated text
      :param string $targetLanguage: Target language code
      :returns: float Quality score (0.0 to 1.0)

.. _api-domain-models:

Domain Models
=============

CompletionResponse
------------------

.. php:namespace:: Netresearch\NrLlm\Domain\Model

.. php:class:: CompletionResponse

   Response from chat/completion operations.

   .. php:attr:: content
      :type: string

      The generated text content.

   .. php:attr:: model
      :type: string

      The model used for generation.

   .. php:attr:: usage
      :type: UsageStatistics

      Token usage statistics.

   .. php:attr:: finishReason
      :type: string

      Why generation stopped: 'stop', 'length', 'content_filter', 'tool_calls'

   .. php:attr:: provider
      :type: string

      The provider identifier.

   .. php:attr:: toolCalls
      :type: array|null

      Tool calls if any were made.

   .. php:method:: isComplete(): bool

      Check if response finished normally.

   .. php:method:: wasTruncated(): bool

      Check if response hit max_tokens limit.

   .. php:method:: wasFiltered(): bool

      Check if content was filtered.

   .. php:method:: hasToolCalls(): bool

      Check if response contains tool calls.

   .. php:method:: getText(): string

      Alias for content property.

EmbeddingResponse
-----------------

.. php:class:: EmbeddingResponse

   Response from embedding operations.

   .. php:attr:: embeddings
      :type: array

      Array of embedding vectors.

   .. php:attr:: model
      :type: string

      The model used for embedding.

   .. php:attr:: usage
      :type: UsageStatistics

      Token usage statistics.

   .. php:attr:: provider
      :type: string

      The provider identifier.

   .. php:method:: getVector(): array

      Get the first embedding vector.

   .. php:staticmethod:: cosineSimilarity(array $a, array $b)

      Calculate cosine similarity between vectors.

      :returns: float

TranslationResult
-----------------

.. php:class:: TranslationResult

   Response from translation operations.

   .. php:attr:: translation
      :type: string

      The translated text.

   .. php:attr:: sourceLanguage
      :type: string

      Detected or provided source language.

   .. php:attr:: targetLanguage
      :type: string

      The target language.

   .. php:attr:: confidence
      :type: float

      Confidence score (0.0 to 1.0).

UsageStatistics
---------------

.. php:class:: UsageStatistics

   Token usage and cost tracking.

   .. php:attr:: promptTokens
      :type: int

      Tokens in the prompt/input.

   .. php:attr:: completionTokens
      :type: int

      Tokens in the completion/output.

   .. php:attr:: totalTokens
      :type: int

      Total tokens used.

   .. php:attr:: estimatedCost
      :type: float|null

      Estimated cost in USD (if available).

.. _api-options:

Option Classes
==============

ChatOptions
-----------

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

   .. php:method:: withProvider(string $provider): self

      Set provider (openai, claude, gemini).

   .. php:method:: withModel(string $model): self

      Set specific model.

   .. php:method:: toArray(): array

      Convert to array format.

.. _api-providers:

Provider Interface
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

.. _api-exceptions:

Exceptions
==========

.. php:namespace:: Netresearch\NrLlm\Provider\Exception

.. php:class:: ProviderException

   Base exception for provider errors.

   .. php:method:: getProvider(): string

      Get the provider that threw the exception.

.. php:class:: AuthenticationException

   Thrown when API authentication fails.

   Extends :php:class:`Netresearch\\NrLlm\\Provider\\Exception\\ProviderException`

.. php:class:: RateLimitException

   Thrown when rate limits are exceeded.

   Extends :php:class:`Netresearch\\NrLlm\\Provider\\Exception\\ProviderException`

   .. php:method:: getRetryAfter(): int

      Get seconds to wait before retry.

.. php:namespace:: Netresearch\NrLlm\Exception

.. php:class:: InvalidArgumentException

   Thrown for invalid method arguments.

.. php:class:: ConfigurationNotFoundException

   Thrown when a named configuration is not found.

.. _api-events:

Events
======

.. php:namespace:: Netresearch\NrLlm\Event

.. php:class:: BeforeRequestEvent

   Dispatched before sending request to provider.

   .. php:method:: getMessages(): array

      Get the messages being sent.

   .. php:method:: getOptions(): array

      Get the request options.

   .. php:method:: setOptions(array $options): void

      Modify request options.

   .. php:method:: getProvider(): string

      Get the target provider.

.. php:class:: AfterResponseEvent

   Dispatched after receiving response from provider.

   .. php:method:: getResponse()

      Get the response object.

      :returns: CompletionResponse or EmbeddingResponse

   .. php:method:: getProvider(): string

      Get the provider that responded.

   .. php:method:: getDuration(): float

      Get request duration in seconds.
