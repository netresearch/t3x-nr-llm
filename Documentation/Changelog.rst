.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

All notable changes to the TYPO3 LLM Extension are documented here.

The format follows `Keep a Changelog <https://keepachangelog.com/>`_ and
the project adheres to `Semantic Versioning <https://semver.org/>`_.

.. _version-1-0-0:

Version 1.0.0 (2024-XX-XX)
==========================

Initial stable release of the TYPO3 LLM Extension.

Added
-----

**Core Features**

- Multi-provider support (OpenAI, Anthropic Claude, Google Gemini)
- Unified API via :php:`LlmServiceManager`
- Provider abstraction layer with capability interfaces
- Typed response objects (:php:`CompletionResponse`, :php:`EmbeddingResponse`)

**Feature Services**

- :php:`CompletionService`: Text completion with format control (JSON, Markdown)
- :php:`EmbeddingService`: Vector generation with caching and similarity calculations
- :php:`VisionService`: Image analysis with alt-text, title, description generation
- :php:`TranslationService`: Translation with formality control and glossary support

**Provider Capabilities**

- Chat completions across all providers
- Embeddings (OpenAI, Gemini)
- Vision/image analysis (all providers)
- Streaming responses (all providers)
- Tool/function calling (all providers)

**Infrastructure**

- TYPO3 caching framework integration
- PSR-14 events (:php:`BeforeRequestEvent`, :php:`AfterResponseEvent`)
- Comprehensive exception hierarchy
- Backend module for provider testing
- Prompt template management

**Developer Experience**

- Option objects with factory presets (:php:`ChatOptions`)
- Full backwards compatibility with array options
- Extensive PHPDoc documentation
- Type-safe method signatures

**Testing**

- 459 tests (unit, integration, functional, E2E, property-based)
- 58% Mutation Score Indicator
- CI/CD integration examples

Changed
-------

*Initial release - no changes*

Deprecated
----------

*Initial release - no deprecations*

Removed
-------

*Initial release - no removals*

Fixed
-----

*Initial release - no fixes*

Security
--------

*Initial release - no security fixes*

.. _upgrade-guides:

Upgrade Guides
==============

Upgrading from Pre-Release
--------------------------

If you used a pre-release version:

1. **Remove old extension**

   .. code-block:: bash

      composer remove netresearch/nr-llm

2. **Clear caches**

   .. code-block:: bash

      vendor/bin/typo3 cache:flush

3. **Install stable version**

   .. code-block:: bash

      composer require netresearch/nr-llm:^1.0

4. **Run database migrations**

   .. code-block:: bash

      vendor/bin/typo3 database:updateschema

5. **Update configuration**

   Review your TypoScript and extension configuration for any
   changed keys or deprecated options.

.. _breaking-changes:

Breaking Changes Policy
=======================

This extension follows semantic versioning:

- **Major versions** (x.0.0): May contain breaking changes
- **Minor versions** (0.x.0): New features, backwards compatible
- **Patch versions** (0.0.x): Bug fixes only

Breaking Changes Documentation
------------------------------

Each major version will document:

1. Removed or changed public APIs
2. Migration steps with code examples
3. Compatibility layer availability
4. Deprecation timeline for removed features

.. _deprecation-policy:

Deprecation Policy
==================

1. Features are marked deprecated in minor versions
2. Deprecated features remain functional for one major version
3. Deprecated features are removed in the next major version
4. Migration documentation provided before removal

Example deprecation notice:

.. code-block:: php

   /**
    * @deprecated since 1.1, will be removed in 2.0
    * Use ChatOptions::creative() instead
    */
   public function setCreativeMode(): void
   {
       trigger_error(
           'setCreativeMode() is deprecated, use ChatOptions::creative()',
           E_USER_DEPRECATED
       );
   }
