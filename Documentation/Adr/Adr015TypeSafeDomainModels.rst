.. include:: /Includes.rst.txt

.. _adr-015:

=====================================================================
ADR-015: Type-Safe Domain Models via PHP 8.1+ Enums & Value Objects
=====================================================================

:Status: Accepted
:Date: 2025-12
:Authors: Netresearch DTT GmbH

.. _adr-015-context:

Context
=======

Domain constants were stringly-typed throughout the codebase. Adapter types were
plain strings (``'openai'``, ``'anthropic'``), capabilities were CSV strings in
database columns, task categories and output formats were validated ad-hoc. This
caused subtle bugs and PHPStan violations at higher analysis levels.

.. _adr-015-problem-statement:

Problem statement
-----------------

1. **No compile-time safety:** Typos like ``'opanai'`` pass silently at runtime.
2. **Scattered validation:** Each usage site re-validated allowed values.
3. **Missing behavior:** Constants carried no associated logic (labels, icons, defaults).
4. **PHPStan violations:** Stringly-typed comparisons defeated type narrowing.

.. _adr-015-decision:

Decision
========

Use PHP 8.1+ backed enums for all domain constants. Each enum provides:

- A ``string``-backed value for database/API compatibility.
- Static helpers: ``values()``, ``isValid()``, ``tryFromString()``.
- Domain-specific methods: ``label()``, ``getIconIdentifier()``, ``getContentType()``.

.. code-block:: php
   :caption: Example: AdapterType enum with behavior

   enum AdapterType: string
   {
       case OpenAI = 'openai';
       case Anthropic = 'anthropic';
       case Gemini = 'gemini';
       case Ollama = 'ollama';
       // ...

       public function label(): string { /* ... */ }
       public function defaultEndpoint(): string { /* ... */ }
       public function requiresApiKey(): bool { /* ... */ }
       public static function toSelectArray(): array { /* ... */ }
   }

Enums implemented:

.. csv-table::
   :header: "Enum", "Purpose", "Cases"
   :widths: 30, 40, 30

   "AdapterType", "LLM provider protocol type", "9 cases (OpenAI through Custom)"
   "ModelCapability", "Model feature flags", "8 cases (chat, vision, tools...)"
   "TaskCategory", "Task organization", "5 cases (content, log_analysis...)"
   "TaskInputType", "Task input source", "5 cases (manual, syslog, file...)"
   "TaskOutputFormat", "Response rendering format", "4 cases (markdown, json...)"
   "ModelSelectionMode", "Model selection strategy", "2 cases (fixed, criteria)"

Immutable readonly DTOs for composite data transfer:

- :php:`DetectedProvider` -- Provider detection result with confidence score.
- :php:`DiscoveredModel` -- Model metadata from API discovery.
- :php:`SuggestedConfiguration` -- AI-generated configuration preset.
- :php:`CompletionResponse` -- Immutable ``final readonly class`` for LLM responses.

.. _adr-015-consequences:

Consequences
============
**Positive:**

- ●● Invalid values caught at instantiation (``BackedEnum::from()`` throws).
- ●● PHPStan level 10 compliance without ``@phpstan-ignore`` suppressions.
- ● Self-documenting: ``AdapterType::OpenAI->defaultEndpoint()`` vs string lookup.
- ● IDE auto-completion and refactoring support.
- ◐ ``match`` expressions enforce exhaustive handling of all cases.

**Negative:**

- ◑ Requires PHP 8.1+ (already the minimum for TYPO3 v13).
- ◑ Enum ``#[CoversNothing]`` needed for PHPUnit 12 coverage.

**Net Score:** +6.0 (Strong positive)

.. _adr-015-files-changed:

Files changed
=============

**Added:**

- :file:`Classes/Domain/Model/AdapterType.php`
- :file:`Classes/Domain/Enum/ModelCapability.php`
- :file:`Classes/Domain/Enum/ModelSelectionMode.php`
- :file:`Classes/Domain/Enum/TaskCategory.php`
- :file:`Classes/Domain/Enum/TaskInputType.php`
- :file:`Classes/Domain/Enum/TaskOutputFormat.php`

**Modified:**

- :file:`Classes/Domain/Model/Provider.php` -- Uses :php:`AdapterType` enum.
- :file:`Classes/Domain/Model/Model.php` -- Uses :php:`ModelCapability` enum.
- :file:`Classes/Domain/Model/Task.php` -- Uses :php:`TaskCategory`, :php:`TaskInputType`, :php:`TaskOutputFormat`.
- :file:`Classes/Provider/AbstractProvider.php` -- Adapter type matching via enum.
