.. include:: /Includes.rst.txt

.. _adr-008:

==================================
ADR-008: Error Handling Strategy
==================================

.. _adr-008-status:

Status
======
**Accepted** (2024-02)

.. _adr-008-context:

Context
=======
LLM operations can fail due to:

- Authentication issues.
- Rate limiting.
- Network errors.
- Content filtering.
- Invalid inputs.

.. _adr-008-decision:

Decision
========
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

- All provider errors extend :php:`ProviderException`.
- :php:`RateLimitException` includes :php:`getRetryAfter()`.
- Exceptions include provider context.
- HTTP status code mapping.

.. _adr-008-consequences:

Consequences
============
**Positive:**

- ●● Granular error handling.
- ● Provider-specific recovery strategies.
- ● Clear exception hierarchy.
- ● Actionable error information.

**Negative:**

- ◑ Many exception classes.
- ◑ Exception handling complexity.
- ✕ Breaking changes in new versions.

**Net Score:** +5.0 (Positive impact - robust error handling enables graceful recovery strategies)
