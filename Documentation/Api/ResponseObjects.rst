.. include:: /Includes.rst.txt

.. _api-domain-models:

================
Response objects
================

.. php:namespace:: Netresearch\NrLlm\Domain\Model

CompletionResponse
==================

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
==============

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
=================

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
=================

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
===============

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
