.. include:: /Includes.rst.txt

.. _adr-004:

==============================
ADR-004: PSR-14 Event System
==============================

.. _adr-004-status:

Status
======
**Accepted** (2024-02)

.. _adr-004-context:

Context
=======
Consumers need extension points for:

- Logging and monitoring.
- Request modification.
- Response processing.
- Cost tracking and rate limiting.

.. _adr-004-decision:

Decision
========
Use **TYPO3's PSR-14 event system** with events:

- :php:`BeforeRequestEvent`: Modify requests before sending.
- :php:`AfterResponseEvent`: Process responses after receiving.

Events are dispatched by :php:`LlmServiceManager` and provide:

- Full context (messages, options, provider).
- Mutable options (before request).
- Response data (after response).
- Timing information.

.. _adr-004-consequences:

Consequences
============
**Positive:**

- ●● Follows TYPO3 conventions.
- ●● Decoupled extension mechanism.
- ● Multiple listeners without modification.
- ● Testable event handlers.

**Negative:**

- ◑ Event overhead on every request.
- ◑ Listener ordering considerations.
- ◑ Debugging event flow complexity.

**Net Score:** +6.5 (Strong positive impact - standard TYPO3 integration with decoupled extensibility)
