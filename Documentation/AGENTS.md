# Documentation Directory

> TYPO3 RST documentation standards for nr_llm extension

## Overview

Official TYPO3 documentation format using reStructuredText (RST) with TYPO3-specific directives.

## Structure

```
Documentation/
├── Index.rst               # Main entry point
├── guides.xml              # Build configuration
├── Includes.rst.txt        # Shared definitions
├── .editorconfig           # Editor settings
├── Introduction/           # Overview and features
├── Installation/           # Setup instructions
├── Configuration/          # Configuration reference
├── Architecture/           # Design patterns
├── Developer/              # API and integration guide
├── Api/                    # API reference
├── Testing/                # Test documentation
├── Adr/                    # Architecture Decision Records
├── Changelog.rst           # Version history
└── Sitemap.rst             # Site navigation
```

## Critical Rules

1. **UTF-8 encoding**, **4-space indentation**, **80 char max line length**, **LF line endings**
2. **CamelCase** for file/directory names, **sentence case** for headings
3. **Index.rst** required in EVERY subdirectory
4. **PNG format** for screenshots with `:alt:` text
5. **.editorconfig** required in Documentation/

## RST Patterns

### Headings (4 levels)

```rst
=============
Document Title (=, overlined)
=============

Chapter (=)
===========

Section (-)
-----------

Subsection (~)
~~~~~~~~~~~~~~
```

### Code Blocks

```rst
.. code-block:: php
   :caption: Example usage

   $response = $llmManager->chat($messages);
```

### TYPO3 Directives

```rst
.. confval:: apiKey
   :type: string
   :required: true

   The API key for the provider.

.. versionadded:: 0.2.0
   Added streaming support.

.. note::
   API keys are encrypted at rest.
```

### Card Grids

```rst
.. card-grid::
   :columns: 2

   .. card:: Quick Start
      :link: quickstart

      Get started in 5 minutes.
```

## Commands

```bash
# Render documentation locally
docker run --rm -v $(pwd):/project ghcr.io/typo3-documentation/render-guides:latest

# Validate RST syntax
scripts/validate_docs.sh .
```

## Pre-Commit Checklist

- [ ] Every directory has Index.rst
- [ ] 4-space indentation, no tabs
- [ ] Max 80 characters per line
- [ ] Code blocks have :caption:
- [ ] Inline code uses roles (:php:, :file:, :typoscript:)
- [ ] README.md and Documentation/ are consistent
