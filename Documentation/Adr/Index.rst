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

Symbol Legend
=============

Each consequence in the ADRs is marked with severity symbols to indicate impact weight:

+--------+------------------+-------------+
| Symbol | Meaning          | Weight      |
+========+==================+=============+
| ●●     | Strong Positive  | +2 to +3    |
+--------+------------------+-------------+
| ●      | Medium Positive  | +1 to +2    |
+--------+------------------+-------------+
| ◐      | Light Positive   | +0.5 to +1  |
+--------+------------------+-------------+
| ✕      | Medium Negative  | -1 to -2    |
+--------+------------------+-------------+
| ✕✕     | Strong Negative  | -2 to -3    |
+--------+------------------+-------------+
| ◑      | Light Negative   | -0.5 to -1  |
+--------+------------------+-------------+

Net Score indicates the overall impact of the decision (sum of weights).

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

- ●● Consumers use single API regardless of provider
- ●● Easy to add new providers
- ● Capability checking via interface detection
- ●● Provider switching requires no code changes

**Negative:**

- ✕ Lowest common denominator for shared features
- ◑ Provider-specific features require direct provider access
- ◑ Additional abstraction layer complexity

**Net Score:** +5.5 (Strong positive impact - abstraction enables flexibility and maintainability)

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

- ●● Clear separation of concerns
- ● Reusable, tested implementations
- ●● Consistent behavior across use cases
- ● Built-in best practices (caching, prompts)

**Negative:**

- ◑ Additional classes to maintain
- ◑ Potential duplication with manager methods
- ◑ Learning curve for service selection

**Net Score:** +6.5 (Strong positive impact - services provide high-level abstractions with best practices)

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

- ●● Strong typing with IDE support
- ● Immutable objects are thread-safe
- ●● Clear API contract
- ● Easy testing and mocking

**Negative:**

- ◑ Cannot extend responses
- ✕ Breaking changes require new properties
- ◑ Slight memory overhead vs arrays

**Net Score:** +5.5 (Strong positive impact - type safety and immutability outweigh flexibility limitations)

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

- ●● Follows TYPO3 conventions
- ●● Decoupled extension mechanism
- ● Multiple listeners without modification
- ● Testable event handlers

**Negative:**

- ◑ Event overhead on every request
- ◑ Listener ordering considerations
- ◑ Debugging event flow complexity

**Net Score:** +6.5 (Strong positive impact - standard TYPO3 integration with decoupled extensibility)

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

- ●● Reduced API costs
- ●● Faster responses for cached content
- ● Follows TYPO3 patterns
- ◐ Configurable per deployment

**Negative:**

- ✕ Cache invalidation complexity
- ◑ Storage requirements
- ✕ Stale responses if TTL too long

**Net Score:** +4.5 (Positive impact - significant cost/performance gains with manageable cache complexity)

.. _adr-006:

ADR-006: Option Objects vs Arrays
=================================

Status
------
**Superseded** by :ref:`ADR-011 <adr-011>` (2024-12)

Context
-------
Method signatures like ``chat(array $messages, array $options)`` lack:

- Type safety and validation
- IDE autocompletion
- Documentation of available options
- Factory methods for common configurations

Decision
--------
Introduce **Option Objects** (initially with array backwards compatibility):

.. code-block:: php

   // Option objects only
   $options = ChatOptions::creative()
       ->withMaxTokens(2000)
       ->withSystemPrompt('Be creative');

   $response = $llmManager->chat($messages, $options);

Implementation:

- Pure object signatures: ``?ChatOptions``
- Factory presets: ``factual()``, ``creative()``, ``json()``
- Fluent builder pattern
- Validation in constructors

Consequences
------------
**Positive:**

- ● IDE autocompletion for options
- ● Built-in validation
- ● Convenient factory presets
- ●● Type safety enforced
- ● Single consistent API

**Negative:**

- ◑ Migration required for existing code
- ◑ No array syntax available

**Net Score:** +5.5 (Strong positive impact - developer experience improvements with backwards compatibility)

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

- ● Easy provider registration
- ● Clear priority system
- ●● Supports custom providers
- ● Automatic fallback

**Negative:**

- ◑ Priority conflicts possible
- ◑ All providers instantiated
- ◑ Configuration complexity

**Net Score:** +5.5 (Strong positive impact - flexible multi-provider support with minor overhead)

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

- ●● Granular error handling
- ● Provider-specific recovery strategies
- ● Clear exception hierarchy
- ● Actionable error information

**Negative:**

- ◑ Many exception classes
- ◑ Exception handling complexity
- ✕ Breaking changes in new versions

**Net Score:** +5.0 (Positive impact - robust error handling enables graceful recovery strategies)

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

- ●● Memory efficient
- ● Natural iteration syntax
- ●● Real-time output
- ◐ Works with output buffering

**Negative:**

- ✕ No response object until complete
- ◑ Error handling complexity
- ◑ Connection management
- ✕ No caching possible

**Net Score:** +3.5 (Positive impact - streaming UX benefits outweigh implementation complexity)

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

- ●● Industry-standard format
- ●● Cross-provider compatibility
- ● Flexible tool definitions
- ● Type-safe parameters

**Negative:**

- ◑ Complex nested structure
- ◑ Provider translation needed
- ✕ No automatic execution
- ◑ Testing complexity

**Net Score:** +5.0 (Positive impact - OpenAI-compatible format ensures broad compatibility)

.. _adr-011:

ADR-011: Object-Only Options API
================================

Status
------
**Accepted** (2024-12)

**Supersedes:** :ref:`ADR-006 <adr-006>`

Context
-------
ADR-006 introduced Option Objects with array backwards compatibility (union types
``ChatOptions|array``). This dual-path approach created:

- Unnecessary complexity in the codebase
- OptionsResolverTrait with 6 resolution methods
- ``fromArray()`` methods in all Option classes
- Cognitive load deciding which syntax to use
- Inconsistent usage patterns across the codebase

Given that:

- No external users exist yet (pre-release)
- No breaking change impact on third parties
- Clean break is possible without migration burden

Decision
--------
Remove array support entirely. Use **typed Option objects only**:

.. code-block:: php

   // All methods now use nullable typed parameters
   public function chat(array $messages, ?ChatOptions $options = null): CompletionResponse;
   public function embed(string|array $input, ?EmbeddingOptions $options = null): EmbeddingResponse;
   public function vision(array $content, ?VisionOptions $options = null): VisionResponse;

   // Usage with factory presets
   $response = $llmManager->chat($messages, ChatOptions::creative());

   // Usage with custom options
   $response = $llmManager->chat($messages, new ChatOptions(
       temperature: 0.7,
       maxTokens: 2000
   ));

   // Usage with defaults (null)
   $response = $llmManager->chat($messages);

Implementation:

- Signatures: ``?ChatOptions`` instead of ``ChatOptions|array``
- Defaults: ``null`` creates default Options in method body
- Removed: ``OptionsResolverTrait``, all ``fromArray()`` methods
- Preserved: Factory presets, fluent builders, validation

Consequences
------------
**Positive:**

- ●● Type safety enforced at compile time
- ●● Single consistent API pattern
- ● Reduced codebase complexity (~250 lines removed)
- ● No trait usage or resolution overhead
- ● Better IDE support without union types
- ◐ Cleaner method signatures

**Negative:**

- ◑ No array syntax for quick prototyping
- ◑ Slightly more verbose for simple cases

**Net Score:** +6.0 (Strong positive - type safety and consistency outweigh minor verbosity increase)

Files Changed
-------------
**Deleted:**

- ``Classes/Service/Option/OptionsResolverTrait.php``

**Modified:**

- ``Classes/Service/Option/AbstractOptions.php`` - Removed ``fromArray()`` abstract
- ``Classes/Service/Option/ChatOptions.php`` - Removed ``fromArray()``
- ``Classes/Service/Option/EmbeddingOptions.php`` - Removed ``fromArray()``
- ``Classes/Service/Option/VisionOptions.php`` - Removed ``fromArray()``
- ``Classes/Service/Option/ToolOptions.php`` - Removed ``fromArray()``
- ``Classes/Service/Option/TranslationOptions.php`` - Removed ``fromArray()``
- ``Classes/Service/LlmServiceManager.php`` - Object-only signatures
- ``Classes/Service/LlmServiceManagerInterface.php`` - Object-only signatures
- ``Classes/Service/Feature/*Service.php`` - All feature services updated
- ``Classes/Specialized/Translation/LlmTranslator.php`` - Uses ChatOptions objects
