.. include:: /Includes.rst.txt

.. _adr-004:

==============================
ADR-004: PSR-14 Event System
==============================

Status
======
**Accepted** (2024-02)

Context
=======
Consumers need extension points for:

- Logging and monitoring
- Request modification
- Response processing
- Cost tracking and rate limiting

Decision
========
Use **TYPO3's PSR-14 event system** with events:

- ``BeforeRequestEvent``: Modify requests before sending
- ``AfterResponseEvent``: Process responses after receiving

Events are dispatched by ``LlmServiceManager`` and provide:

- Full context (messages, options, provider)
- Mutable options (before request)
- Response data (after response)
- Timing information

Consequences
============
**Positive:**

- ●● Follows TYPO3 conventions
- ●● Decoupled extension mechanism
- ● Multiple listeners without modification
- ● Testable event handlers

**Negative:**

- ◑ Event overhead on every request
- ◑ Listener ordering considerations
- ◑ Debugging event flow complexity

**Net Score:** +6.5 (Strong positive impact - standard TYPO3 integration with decoupled extensibility)
