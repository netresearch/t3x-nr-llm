.. include:: /Includes.rst.txt

.. _adr:
.. _architecture-decision-records:

==============================
Architecture Decision Records
==============================

This section documents significant architectural decisions made during the
development of the TYPO3 LLM Extension.

.. contents::
   :local:
   :depth: 1

.. _adr-001:

ADR-001: Provider Abstraction Layer
===================================

Status
------
**Accepted** (2024-01)

Context
-------
We needed to support multiple LLM providers (OpenAI, Anthropic Claude, Google Gemini)
while maintaining a consistent API for consumers. Each provider has different:

- API endpoints and authentication methods
- Request/response formats
- Model naming conventions
- Capability sets (vision, embeddings, streaming, tools)

Decision
--------
Implement a **provider abstraction layer** with:

1. ``ProviderInterface`` as the core contract
2. Capability interfaces for optional features:
   - ``EmbeddingCapableInterface``
   - ``VisionCapableInterface``
   - ``StreamingCapableInterface``
   - ``ToolCapableInterface``
3. ``AbstractProvider`` base class with shared functionality
4. ``LlmServiceManager`` as the unified entry point

Consequences
------------
**Positive:**

- Consumers use single API regardless of provider
- Easy to add new providers
- Capability checking via interface detection
- Provider switching requires no code changes

**Negative:**

- Lowest common denominator for shared features
- Provider-specific features require direct provider access
- Additional abstraction layer complexity

Alternatives Considered
-----------------------
1. **Single monolithic class**: Rejected due to maintenance complexity
2. **Strategy pattern only**: Insufficient for capability detection
3. **Factory pattern**: Used in combination with interfaces

.. _adr-002:

ADR-002: Feature Services Architecture
======================================

Status
------
**Accepted** (2024-02)

Context
-------
Common LLM tasks (translation, image analysis, embeddings) require:

- Specialized prompts and configurations
- Pre/post-processing logic
- Caching strategies
- Quality control measures

Decision
--------
Create **dedicated Feature Services** for high-level operations:

- ``CompletionService``: Text generation with format control
- ``EmbeddingService``: Vector operations with caching
- ``VisionService``: Image analysis with specialized prompts
- ``TranslationService``: Language translation with quality scoring

Each service:

- Uses ``LlmServiceManager`` internally
- Provides domain-specific methods
- Handles caching and optimization
- Returns typed response objects

Consequences
------------
**Positive:**

- Clear separation of concerns
- Reusable, tested implementations
- Consistent behavior across use cases
- Built-in best practices (caching, prompts)

**Negative:**

- Additional classes to maintain
- Potential duplication with manager methods
- Learning curve for service selection

.. _adr-003:

ADR-003: Typed Response Objects
===============================

Status
------
**Accepted** (2024-01)

Context
-------
Provider APIs return different response structures. We needed to:

- Provide consistent response format to consumers
- Enable IDE autocompletion and type checking
- Include relevant metadata (usage, model, finish reason)

Decision
--------
Use **immutable value objects** for responses:

.. code-block:: php

   final class CompletionResponse
   {
       public function __construct(
           public readonly string $content,
           public readonly string $model,
           public readonly UsageStatistics $usage,
           public readonly string $finishReason,
           public readonly string $provider,
           public readonly ?array $toolCalls = null,
       ) {}
   }

Key characteristics:

- ``final`` classes prevent inheritance issues
- ``readonly`` properties ensure immutability
- Constructor promotion for concise definition
- Nullable for optional data

Consequences
------------
**Positive:**

- Strong typing with IDE support
- Immutable objects are thread-safe
- Clear API contract
- Easy testing and mocking

**Negative:**

- Cannot extend responses
- Breaking changes require new properties
- Slight memory overhead vs arrays

.. _adr-004:

ADR-004: PSR-14 Event System
============================

Status
------
**Accepted** (2024-02)

Context
-------
Consumers need extension points for:

- Logging and monitoring
- Request modification
- Response processing
- Cost tracking and rate limiting

Decision
--------
Use **TYPO3's PSR-14 event system** with events:

- ``BeforeRequestEvent``: Modify requests before sending
- ``AfterResponseEvent``: Process responses after receiving

Events are dispatched by ``LlmServiceManager`` and provide:

- Full context (messages, options, provider)
- Mutable options (before request)
- Response data (after response)
- Timing information

Consequences
------------
**Positive:**

- Follows TYPO3 conventions
- Decoupled extension mechanism
- Multiple listeners without modification
- Testable event handlers

**Negative:**

- Event overhead on every request
- Listener ordering considerations
- Debugging event flow complexity

.. _adr-005:

ADR-005: TYPO3 Caching Framework Integration
============================================

Status
------
**Accepted** (2024-03)

Context
-------
LLM API calls are:

- Expensive (cost per token)
- Relatively slow (network latency)
- Often deterministic (embeddings, some completions)

Decision
--------
Integrate with **TYPO3's caching framework**:

- Cache identifier: ``nrllm_responses``
- Configurable backend (default: database)
- Cache keys based on: provider + model + input hash
- TTL: 3600s default (configurable)

Caching strategy:

- **Always cache**: Embeddings (deterministic)
- **Optional cache**: Completions with temperature=0
- **Never cache**: Streaming, tool calls, high temperature

Consequences
------------
**Positive:**

- Reduced API costs
- Faster responses for cached content
- Follows TYPO3 patterns
- Configurable per deployment

**Negative:**

- Cache invalidation complexity
- Storage requirements
- Stale responses if TTL too long

.. _adr-006:

ADR-006: Option Objects vs Arrays
=================================

Status
------
**Accepted** (2024-04)

Context
-------
Method signatures like ``chat(array $messages, array $options)`` lack:

- Type safety and validation
- IDE autocompletion
- Documentation of available options
- Factory methods for common configurations

Decision
--------
Introduce **Option Objects** with array backwards compatibility:

.. code-block:: php

   // New: Option objects
   $options = ChatOptions::creative()
       ->withMaxTokens(2000)
       ->withSystemPrompt('Be creative');

   // Still works: Array syntax
   $options = ['temperature' => 1.2, 'max_tokens' => 2000];

   // Both accepted
   $response = $llmManager->chat($messages, $options);

Implementation:

- Union type signatures: ``ChatOptions|array``
- Internal resolution to arrays
- Factory presets: ``factual()``, ``creative()``, ``json()``
- Fluent builder pattern

Consequences
------------
**Positive:**

- IDE autocompletion for options
- Built-in validation
- Convenient factory presets
- Full backwards compatibility

**Negative:**

- Two ways to do same thing
- Option class maintenance
- Slight complexity increase

.. _adr-007:

ADR-007: Multi-Provider Strategy
================================

Status
------
**Accepted** (2024-01)

Context
-------
Supporting multiple providers requires:

- Dynamic provider registration
- Priority-based selection
- Configuration per provider
- Fallback mechanisms

Decision
--------
Use **tagged service collection** with priority:

.. code-block:: yaml

   # Services.yaml
   Netresearch\NrLlm\Provider\OpenAiProvider:
     tags:
       - name: nr_llm.provider
         priority: 100

   Netresearch\NrLlm\Provider\ClaudeProvider:
     tags:
       - name: nr_llm.provider
         priority: 90

Provider selection hierarchy:

1. Explicit provider in options
2. Default provider from configuration
3. First configured provider by priority
4. Throw exception if none available

Consequences
------------
**Positive:**

- Easy provider registration
- Clear priority system
- Supports custom providers
- Automatic fallback

**Negative:**

- Priority conflicts possible
- All providers instantiated
- Configuration complexity

.. _adr-008:

ADR-008: Error Handling Strategy
================================

Status
------
**Accepted** (2024-02)

Context
-------
LLM operations can fail due to:

- Authentication issues
- Rate limiting
- Network errors
- Content filtering
- Invalid inputs

Decision
--------
Implement **hierarchical exception system**:

.. code-block:: text

   Exception
   └── ProviderException (base for provider errors)
       ├── AuthenticationException (invalid API key)
       ├── RateLimitException (quota exceeded)
       └── ContentFilteredException (blocked content)
   └── InvalidArgumentException (bad inputs)
   └── ConfigurationNotFoundException (missing config)

Key features:

- All provider errors extend ``ProviderException``
- ``RateLimitException`` includes ``getRetryAfter()``
- Exceptions include provider context
- HTTP status code mapping

Consequences
------------
**Positive:**

- Granular error handling
- Provider-specific recovery strategies
- Clear exception hierarchy
- Actionable error information

**Negative:**

- Many exception classes
- Exception handling complexity
- Breaking changes in new versions

.. _adr-009:

ADR-009: Streaming Implementation
=================================

Status
------
**Accepted** (2024-03)

Context
-------
Streaming responses provide:

- Better UX for long responses
- Lower time-to-first-token
- Real-time feedback

Decision
--------
Use **PHP Generators** for streaming:

.. code-block:: php

   public function streamChat(array $messages, array $options = []): Generator
   {
       $response = $this->sendStreamingRequest($messages, $options);

       foreach ($this->parseSSE($response) as $chunk) {
           yield $chunk;
       }
   }

   // Usage
   foreach ($llmManager->streamChat($messages) as $chunk) {
       echo $chunk;
       flush();
   }

Implementation details:

- Server-Sent Events (SSE) parsing
- Chunked transfer encoding
- Memory-efficient iteration
- Provider-specific adaptations

Consequences
------------
**Positive:**

- Memory efficient
- Natural iteration syntax
- Real-time output
- Works with output buffering

**Negative:**

- No response object until complete
- Error handling complexity
- Connection management
- No caching possible

.. _adr-010:

ADR-010: Tool/Function Calling Design
=====================================

Status
------
**Accepted** (2024-04)

Context
-------
Modern LLMs support tool/function calling for:

- External data retrieval
- Action execution
- Structured output generation

Decision
--------
Support **OpenAI-compatible tool format**:

.. code-block:: php

   $tools = [
       [
           'type' => 'function',
           'function' => [
               'name' => 'get_weather',
               'description' => 'Get weather for location',
               'parameters' => [
                   'type' => 'object',
                   'properties' => [
                       'location' => ['type' => 'string'],
                   ],
                   'required' => ['location'],
               ],
           ],
       ],
   ];

Tool calls returned in ``CompletionResponse::$toolCalls``:

- Array of tool call objects
- Includes function name and arguments
- JSON-encoded arguments for parsing

Consequences
------------
**Positive:**

- Industry-standard format
- Cross-provider compatibility
- Flexible tool definitions
- Type-safe parameters

**Negative:**

- Complex nested structure
- Provider translation needed
- No automatic execution
- Testing complexity
