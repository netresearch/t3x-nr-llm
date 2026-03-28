.. include:: /Includes.rst.txt

.. _adr-017:

============================================
ADR-017: Safe Type Casting via SafeCastTrait
============================================

:Status: Accepted
:Date: 2025-12
:Authors: Netresearch DTT GmbH

.. _adr-017-context:

Context
=======

Processing untyped data from JSON API responses, form submissions, and
configuration arrays requires casting ``mixed`` values to specific scalar types.
At PHPStan level 10, direct casts like ``(string)$mixed`` trigger
"Cannot cast mixed to string" errors. Each usage site would need inline type
guards, leading to repetitive boilerplate.

.. _adr-017-problem-statement:

Problem statement
-----------------

1. **PHPStan level 10 strictness:**
   ``(string)$data['key']`` is forbidden on ``mixed``.
2. **Verbose alternatives:**
   ``is_string($v) ? $v : (is_numeric($v) ? (string)$v : '')``
   at every call site.
3. **Inconsistent defaults:** Different code paths used
   different fallback values.
4. **Suppression temptation:** Teams resort to
   ``@phpstan-ignore`` instead of proper narrowing.

.. _adr-017-decision:

Decision
========

Extract a reusable :php:`SafeCastTrait` with three static methods that handle
``mixed`` input with sensible defaults and no PHPStan suppressions:

.. code-block:: php
   :caption: Classes/Utility/SafeCastTrait.php

   trait SafeCastTrait
   {
       private static function toStr(mixed $value): string
       {
           return is_string($value) || is_numeric($value) ? (string)$value : '';
       }

       private static function toInt(mixed $value): int
       {
           return is_numeric($value) ? (int)$value : 0;
       }

       private static function toFloat(mixed $value): float
       {
           return is_numeric($value) ? (float)$value : 0.0;
       }
   }

Design choices:

- **Static methods** -- No instance state needed;
  enables ``self::toStr()`` calls.
- **Private visibility** -- Implementation detail of
  the using class, not public API.
- **Numeric passthrough** -- ``is_numeric()`` covers
  int, float, and numeric strings.
- **Empty-string default** -- Safer than ``null`` for
  string contexts (concatenation, comparison).
- **Zero default** for int/float -- Neutral value for arithmetic operations.

Complements the :php:`ResponseParserTrait` in :file:`Classes/Provider/` which
serves a similar purpose for provider API response arrays but with key-based
access (``getString($data, 'key')``). SafeCastTrait handles standalone values.

Usage in :php:`WizardGeneratorService`:

.. code-block:: php
   :caption: Example: Normalizing LLM JSON output

   $result = [
       'identifier' => $this->sanitizeIdentifier(self::toStr($data['identifier'] ?? '')),
       'temperature' => $this->clamp(self::toFloat($data['temperature'] ?? 0.7), 0.0, 2.0),
       'max_tokens' => $this->clampInt(self::toInt($data['max_tokens'] ?? 4096), 1, 128000),
   ];

.. _adr-017-consequences:

Consequences
============
**Positive:**

- ●● PHPStan level 10 compliance without any ``@phpstan-ignore`` suppressions.
- ● Consistent fallback behavior across all consumers.
- ● Three-line methods are trivially testable and auditable.
- ◐ Reduces boilerplate by ~5 lines per cast site.

**Negative:**

- ◑ Trait usage adds an indirect dependency (mitigated
  by being a small utility).
- ◑ ``is_numeric()`` accepts numeric strings like ``"1e2"`` which may surprise.

**Net Score:** +4.5 (Positive)

.. _adr-017-files-changed:

Files changed
=============

**Added:**

- :file:`Classes/Utility/SafeCastTrait.php`

**Modified (consumers):**

- :file:`Classes/Service/WizardGeneratorService.php` --
  Uses :php:`SafeCastTrait` for JSON normalization.
- :file:`Classes/Controller/Backend/TaskController.php`
  -- Uses :php:`SafeCastTrait` for form data casting.
