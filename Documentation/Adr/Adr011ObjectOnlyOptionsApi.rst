.. include:: /Includes.rst.txt

.. _adr-011:

====================================
ADR-011: Object-Only Options API
====================================

.. _adr-011-status:

Status
======
**Accepted** (2024-12)

**Supersedes:** :ref:`ADR-006 <adr-006>`

.. _adr-011-context:

Context
=======
ADR-006 introduced Option Objects with array backwards compatibility (union types
``ChatOptions|array``). This dual-path approach created:

- Unnecessary complexity in the codebase.
- :php:`OptionsResolverTrait` with 6 resolution methods.
- :php:`fromArray()` methods in all Option classes.
- Cognitive load deciding which syntax to use.
- Inconsistent usage patterns across the codebase.

Given that:

- No external users exist yet (pre-release).
- No breaking change impact on third parties.
- Clean break is possible without migration burden.

.. _adr-011-decision:

Decision
========
Remove array support entirely. Use **typed Option objects only**:

.. code-block:: php
   :caption: Example: Object-only options API

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

- Signatures: :php:`?ChatOptions` instead of ``ChatOptions|array``.
- Defaults: :php:`null` creates default Options in method body.
- Removed: :php:`OptionsResolverTrait`, all :php:`fromArray()` methods.
- Preserved: Factory presets, fluent builders, validation.

.. _adr-011-consequences:

Consequences
============
**Positive:**

- ●● Type safety enforced at compile time.
- ●● Single consistent API pattern.
- ● Reduced codebase complexity (~250 lines removed).
- ● No trait usage or resolution overhead.
- ● Better IDE support without union types.
- ◐ Cleaner method signatures.

**Negative:**

- ◑ No array syntax for quick prototyping.
- ◑ Slightly more verbose for simple cases.

**Net Score:** +6.0 (Strong positive - type safety and consistency outweigh minor verbosity increase)

.. _adr-011-files-changed:

Files changed
=============
**Deleted:**

- :file:`Classes/Service/Option/OptionsResolverTrait.php`

**Modified:**

- :file:`Classes/Service/Option/AbstractOptions.php` - Removed :php:`fromArray()` abstract.
- :file:`Classes/Service/Option/ChatOptions.php` - Removed :php:`fromArray()`.
- :file:`Classes/Service/Option/EmbeddingOptions.php` - Removed :php:`fromArray()`.
- :file:`Classes/Service/Option/VisionOptions.php` - Removed :php:`fromArray()`.
- :file:`Classes/Service/Option/ToolOptions.php` - Removed :php:`fromArray()`.
- :file:`Classes/Service/Option/TranslationOptions.php` - Removed :php:`fromArray()`.
- :file:`Classes/Service/LlmServiceManager.php` - Object-only signatures.
- :file:`Classes/Service/LlmServiceManagerInterface.php` - Object-only signatures.
- :file:`Classes/Service/Feature/*Service.php` - All feature services updated.
- :file:`Classes/Specialized/Translation/LlmTranslator.php` - Uses :php:`ChatOptions` objects.
