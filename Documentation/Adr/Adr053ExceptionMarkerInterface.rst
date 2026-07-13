.. include:: /Includes.rst.txt

.. _adr-053:

=========================================================
ADR-053: One marker interface for all thrown exceptions
=========================================================

:Status: Accepted
:Date: 2026-07-12
:Authors: Netresearch DTT GmbH

.. _adr-053-context:

Context
=======

A consumer that wraps an nr_llm call and wants to convert failures into
its own domain exception has to enumerate concrete classes today::

    } catch (
        InvalidArgumentException          // PHP's own, from ChatMessage/ToolSpec::fromArray()
        | NrLlmInvalidArgumentException   // options validation
        | ProviderException               // covers the 5 provider subtypes
        | BudgetExceededException
        | AccessDeniedException
        | ConfigurationNotFoundException $e
    ) {

Two problems. The list goes stale silently: when a future version adds
or rethrows a new exception type, existing catch lists let it escape as
an uncaught 500 instead of the consumer's clean error path. And the
chat/tool value objects' ``fromArray()`` normalisation threw **PHP's**
global ``\InvalidArgumentException``, a different class from nr_llm's
own ``Exception\InvalidArgumentException`` — the first entry in the
list above exists only because of that mismatch (``nr_ai_search``'s
``NrLlmChatClient`` documents exactly this trap).

.. _adr-053-decision:

Decision
========

- ``Netresearch\NrLlm\Exception\NrLlmExceptionInterface`` (extending
  ``\Throwable``) marks every exception this extension throws on its
  public API surface. The five core exceptions and ``ProviderException``
  (which its five subtypes inherit from) implement it.
- ``ChatMessage`` / ``ToolSpec`` / ``ToolCall`` normalisation errors now
  throw ``Exception\InvalidArgumentException`` instead of PHP's global
  class. Backwards compatible: the nr_llm class extends
  ``\InvalidArgumentException``, so existing catches keep matching.
- A reflection test sweeps both exception directories so a future
  exception class cannot ship without the marker.

Consumers can now write ``catch (NrLlmExceptionInterface $e)`` — one
arm, future-proof.

.. _adr-053-consequences:

Consequences
============

- The remaining classes that imported the global
  ``InvalidArgumentException`` for their own validation errors
  (response parsers, task readers, backend response DTOs, value
  objects) throw ``Exception\InvalidArgumentException`` now — the
  compatible follow-up named here is done, guarded by the same
  reflection test. One deliberate exception:
  ``Service\Task\TaskInputResolver`` keeps the global import because it
  only *catches* the exception around ``RecordTableReader::fetchAll()``
  — narrowing that catch to the nr_llm subclass would miss a plain
  ``\InvalidArgumentException`` raised by third-party code inside the
  read path.
- Catch-all remains opt-in: consumers that want to handle budget
  exhaustion differently from provider outages keep catching the
  concrete classes.
