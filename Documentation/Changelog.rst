.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

All notable changes to the TYPO3 LLM Extension are documented here.

The format follows `Keep a Changelog <https://keepachangelog.com/>`_ and
the project adheres to `Semantic Versioning <https://semver.org/>`_.

.. _version-0-2-1:

Version 0.2.1 (2026-02-28)
===========================

Changed
-------

- Require ``netresearch/nr-vault`` ^0.4.0 for API key encryption.

.. _version-0-2-0:

Version 0.2.0 (2026-02-28)
===========================

Added
-----

- PHP 8.2+ and TYPO3 v13.4+ compatibility.
- TYPO3 v13.4 ddev install command.
- Coverage uploads and fuzz/mutation CI workflow.
- Unit tests for enums, WizardResult DTO, providers, services, and specialized classes.
- Coverage tests for PromptTemplateService and TranslationService.

Changed
-------

- Moved ``phpunit.xml`` and ``phpstan-baseline.neon`` into ``Build/`` directory.
- Expanded CI matrix to PHP 8.2-8.5 and TYPO3 v13.4/v14.
- Replaced TYPO3 v14-only APIs with v13-compatible equivalents.
- Narrowed testing-framework to ^9.0 for PHPUnit 12 compatibility.
- Removed dead ProviderRegistry class and orphaned phpstan baseline file.
- Removed 55 dead translation keys.
- Harmonized composer script naming to ``ci:test:php:*`` convention.
- Migrated CI to centralized workflows.
- Added SPDX copyright and license headers.
- Replaced generic emails with GitHub references.

Fixed
-----

- Resolved CI failures for PHP 8.2 and TYPO3 v13 compatibility.
- Resolved PHPStan failures for dual TYPO3 v13/v14 support.
- Fixed PHPUnit deprecation warnings.
- Used ``CoversNothing`` for excluded exception and enum test classes.
- Localized user-facing hardcoded strings in controllers.
- Disabled functional tests in CI (environment-specific).
- Fixed direct ``php-cs-fixer`` call in ``ci:test:php:cgl`` script.

.. _version-0-1-2:

Version 0.1.2 (2026-01-11)
===========================

Fixed
-----

- Fixed CI: use correct org secret name for TER token.
- Simplified TER upload workflow.

.. _version-0-1-1:

Version 0.1.1 (2026-01-11)
===========================

Fixed
-----

- Fixed CI: create zip archive for TER upload.

.. _version-0-1-0:

Version 0.1.0 (2026-01-11)
===========================

Initial release of the TYPO3 LLM Extension.

Added
-----

**Core Features**

- Multi-provider support (OpenAI, Anthropic Claude, Google Gemini, Ollama, OpenRouter, Mistral, Groq).
- Unified API via :php:`LlmServiceManager`.
- Provider abstraction layer with capability interfaces.
- Typed response objects (:php:`CompletionResponse`, :php:`EmbeddingResponse`).
- Three-tier configuration architecture (Providers, Models, Configurations).
- Encrypted API key storage using sodium_crypto_secretbox.

**Feature Services**

- :php:`CompletionService`: Text completion with format control (JSON, Markdown).
- :php:`EmbeddingService`: Vector generation with caching and similarity calculations.
- :php:`VisionService`: Image analysis with alt-text, title, description generation.
- :php:`TranslationService`: Translation with formality control and glossary support.
- :php:`PromptTemplateService`: Centralized prompt management with database-driven templates.

**Specialized Services**

- Image generation (DALL-E).
- Text-to-speech (TTS) and speech transcription (Whisper).
- DeepL translation integration.

**Provider Capabilities**

- Chat completions across all providers.
- Embeddings (OpenAI, Gemini).
- Vision/image analysis (all providers).
- Streaming responses (all providers).
- Tool/function calling (all providers).

**Infrastructure**

- TYPO3 caching framework integration.
- Backend module for provider management and testing.
- Prompt template management with versioning and performance tracking.
- Comprehensive exception hierarchy.
- Type-safe enums and DTOs for domain constants.

**Developer Experience**

- Option objects with factory presets (:php:`ChatOptions`).
- Full backwards compatibility with array options.
- Extensive PHPDoc documentation.
- Type-safe method signatures.

**Security**

- Enterprise readiness security workflows and supply chain controls.
- SLSA Level 3 provenance, Cosign signatures, and SBOM generation.
- OpenSSF Scorecard and Best Practices compliance.

**Testing**

- Comprehensive unit and integration tests.
- E2E testing with Playwright.
- Property-based (fuzz) testing support.

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

3. **Install current version**

   .. code-block:: bash

      composer require netresearch/nr-llm:^0.2

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
