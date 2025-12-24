.. include:: /Includes.rst.txt

.. _adr-008:

==================================
ADR-008: Error Handling Strategy
==================================

Status
======
**Accepted** (2024-02)

Context
=======
LLM operations can fail due to:

- Authentication issues
- Rate limiting
- Network errors
- Content filtering
- Invalid inputs

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

- All provider errors extend ``ProviderException``
- ``RateLimitException`` includes ``getRetryAfter()``
- Exceptions include provider context
- HTTP status code mapping

Consequences
============
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
