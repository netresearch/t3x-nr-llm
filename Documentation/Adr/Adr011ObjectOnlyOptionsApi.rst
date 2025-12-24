.. include:: /Includes.rst.txt

.. _adr-011:

====================================
ADR-011: Object-Only Options API
====================================

Status
======
**Accepted** (2024-12)

**Supersedes:** :ref:`ADR-006 <adr-006>`

Context
=======
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
========
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
============
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
=============
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
