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
   :caption: Exception hierarchy (Classes/Provider/Exception/ + Classes/Exception/)

   \RuntimeException
   ├── Netresearch\NrLlm\Provider\Exception\ProviderException (base for provider errors)
   │   ├── ProviderConnectionException (transport / network failure)
   │   ├── ProviderResponseException (non-2xx / malformed API response)
   │   ├── ProviderConfigurationException (missing/invalid provider setup)
   │   ├── UnsupportedFeatureException (capability not implemented)
   │   └── FallbackChainExhaustedException (all providers in the chain failed)
   └── Netresearch\NrLlm\Exception\ConfigurationNotFoundException (missing configuration record)
   \InvalidArgumentException
   └── Netresearch\NrLlm\Exception\InvalidArgumentException (bad inputs)

Key features:

- All provider errors extend :php:`ProviderException` (itself a
  :php:`\RuntimeException`).
- :php:`FallbackChainExhaustedException` is raised by
  :php:`FallbackMiddleware` when every provider in the chain fails
  (:ref:`ADR-021 <adr-021>`, :ref:`ADR-026 <adr-026>`).
- :php:`ProviderResponseException` carries the offending HTTP status and a
  sanitised message (secrets stripped by ``ErrorMessageSanitizerTrait``).
- Exceptions include provider context.

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

**Net Score:** +5.0 (Positive impact - robust error
handling enables graceful recovery strategies)
