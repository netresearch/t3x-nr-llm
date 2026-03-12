.. include:: /Includes.rst.txt

.. _api-reference:

=============
API reference
=============

Complete API reference for the TYPO3 LLM extension.

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

.. _api-feature-services:

Feature services
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

   .. php:method:: pairwiseSimilarities(array $vectors): array

      Calculate pairwise similarities between all vectors.

      Returns a 2D matrix where each cell ``[i][j]`` contains the cosine
      similarity between vectors ``i`` and ``j``. Diagonal values are always 1.0.

      :param array $vectors: Array of embedding vectors
      :returns: array 2D array of similarity scores

   .. php:method:: normalize(array $vector): array

      Normalize a vector to unit length.

      :param array $vector: The vector to normalize
      :returns: array Normalized vector

VisionService
-------------

.. php:class:: VisionService

   Image analysis with specialized prompts.

   .. php:method:: generateAltText($imageUrl, $options = null)

      Generate WCAG-compliant alt text.

      Optimized for screen readers and WCAG 2.1 Level AA compliance.
      Output is concise (under 125 characters) and focuses on essential information.

      :param string|array $imageUrl: URL, local path, or array of URLs for batch processing
      :param VisionOptions|null $options: Vision options (defaults: maxTokens=100, temperature=0.5)
      :returns: string|array Alt text or array of alt texts for batch input

   .. php:method:: generateTitle($imageUrl, $options = null)

      Generate SEO-optimized image title.

      Creates compelling, keyword-rich titles under 60 characters
      for improved search rankings.

      :param string|array $imageUrl: URL, local path, or array of URLs for batch processing
      :param VisionOptions|null $options: Vision options (defaults: maxTokens=50, temperature=0.7)
      :returns: string|array Title or array of titles for batch input

   .. php:method:: generateDescription($imageUrl, $options = null)

      Generate detailed image description.

      Provides comprehensive analysis including subjects, setting,
      colors, mood, composition, and notable details.

      :param string|array $imageUrl: URL, local path, or array of URLs for batch processing
      :param VisionOptions|null $options: Vision options (defaults: maxTokens=500, temperature=0.7)
      :returns: string|array Description or array of descriptions for batch input

   .. php:method:: analyzeImage($imageUrl, $customPrompt, $options = null)

      Custom image analysis with specific prompt.

      :param string|array $imageUrl: URL, local path, or array of URLs for batch processing
      :param string $customPrompt: Custom analysis prompt
      :param VisionOptions|null $options: Vision options
      :returns: string|array Analysis result or array of results for batch input

   .. php:method:: analyzeImageFull(string $imageUrl, string $prompt, ?VisionOptions $options = null): VisionResponse

      Full image analysis returning complete response with usage statistics.

      Returns a :php:class:`VisionResponse` with metadata and usage data,
      unlike the other methods which return plain text.

      :param string $imageUrl: Image URL or base64 data URI
      :param string $prompt: Analysis prompt
      :param VisionOptions|null $options: Vision options
      :returns: VisionResponse Complete response with usage data
      :throws: InvalidArgumentException If image URL is invalid

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

Domain models
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

   .. php:attr:: metadata
      :type: array|null

      Provider-specific metadata. Structure varies by provider.

   .. php:attr:: thinking
      :type: string|null

      Thinking/reasoning content from models that support extended thinking
      (e.g., Claude with thinking enabled).

   .. php:method:: isComplete(): bool

      Check if response finished normally.

   .. php:method:: wasTruncated(): bool

      Check if response hit max_tokens limit.

   .. php:method:: wasFiltered(): bool

      Check if content was filtered.

   .. php:method:: hasToolCalls(): bool

      Check if response contains tool calls.

   .. php:method:: hasThinking(): bool

      Check if response contains thinking/reasoning content.

   .. php:method:: getText(): string

      Alias for content property.

VisionResponse
--------------

.. php:class:: VisionResponse

   Response from vision/image analysis operations.

   .. php:attr:: description
      :type: string

      The generated image analysis text.

   .. php:attr:: model
      :type: string

      The model used for analysis.

   .. php:attr:: usage
      :type: UsageStatistics

      Token usage statistics.

   .. php:attr:: provider
      :type: string

      The provider identifier.

   .. php:attr:: confidence
      :type: float|null

      Confidence score for the analysis (if available).

   .. php:attr:: detectedObjects
      :type: array|null

      Detected objects in the image (if available).

   .. php:attr:: metadata
      :type: array|null

      Provider-specific metadata.

   .. php:method:: getText(): string

      Get the analysis text. Alias for ``description`` property.

   .. php:method:: getDescription(): string

      Alias for ``description`` property.

   .. php:method:: meetsConfidence(float $threshold): bool

      Check if confidence score meets or exceeds a threshold.

      :param float $threshold: Minimum confidence value
      :returns: bool True if confidence is not null and meets threshold

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

Option classes
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

.. _api-exceptions:

Exceptions
==========

.. php:namespace:: Netresearch\NrLlm\Provider\Exception

.. php:class:: ProviderException

   Base exception for provider errors.

   .. php:method:: getProvider(): string

      Get the provider that threw the exception.

.. php:class:: ProviderConfigurationException

   Thrown when a provider is incorrectly
   configured.

   Extends
   :php:class:`Netresearch\\NrLlm\\Provider\\Exception\\ProviderException`

.. php:class:: ProviderConnectionException

   Thrown when a connection to the provider
   fails.

   Extends
   :php:class:`Netresearch\\NrLlm\\Provider\\Exception\\ProviderException`

.. php:class:: ProviderResponseException

   Thrown when the provider returns an
   unexpected or error response.

   Extends
   :php:class:`Netresearch\\NrLlm\\Provider\\Exception\\ProviderException`

.. php:class:: UnsupportedFeatureException

   Thrown when a requested feature is not
   supported by the provider.

   Extends
   :php:class:`Netresearch\\NrLlm\\Provider\\Exception\\ProviderException`

.. php:namespace:: Netresearch\NrLlm\Exception

.. php:class:: InvalidArgumentException

   Thrown for invalid method arguments.

.. php:class:: ConfigurationNotFoundException

   Thrown when a named configuration is not found.

.. _api-events:

Events
======

.. note::

   PSR-14 events (``BeforeRequestEvent``, ``AfterResponseEvent``) are planned
   for a future release. The event classes do not exist yet in the current
   codebase.
