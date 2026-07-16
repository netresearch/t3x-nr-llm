.. include:: /Includes.rst.txt

.. _adr-073:

====================================================================
ADR-073: First-party test doubles for consumer-facing interfaces
====================================================================

:Status: Accepted
:Date: 2026-07-17
:Authors: Netresearch DTT GmbH

.. _adr-073-context:

Context
=======

The feature-service interfaces are documented as the consumer entry
points (ADR-051): a downstream extension depends on
``ToolCallingServiceInterface`` or ``EmbeddingServiceInterface`` and, in
its own unit tests, substitutes a fake. Every consumer currently
hand-rolls that fake. ``nr_ai_search`` carries a
``FakeToolCallingService`` and a ``FakeEmbeddingService`` under its own
test namespace, re-deriving the canned-response and call-recording
behaviour that any consumer of the same interface needs.

A hand-rolled fake also re-introduces the maintenance trap ADR-051 set
out to remove: it drifts from the interface it imitates. When the
interface grows a method the fake does not implement, the consumer's
suite fatals — the same breakage that blocked ``nr_ai_search``'s
0.13 → 0.16 update.

.. _adr-073-decision:

Decision
========

Ship maintained test doubles from nr_llm itself, in a new
runtime-autoloaded namespace ``Netresearch\NrLlm\Testing\``
(``Classes/Testing/``, mapped by the production ``autoload`` block, not
``autoload-dev``) so consumers autoload them without nr_llm's dev
dependencies:

- ``FakeToolCallingService`` implements ``ToolCallingServiceInterface``:
  a FIFO queue of ``CompletionResponse`` values, per-call recording for
  both methods, and a settable throwable.
- ``FakeEmbeddingService`` implements ``EmbeddingServiceInterface``: canned
  vectors plus per-call recording for the five provider-backed ``embed*``
  methods (so a test can assert the ``LlmConfiguration`` was passed
  through) and canned returns for the four pure vector helpers, which a
  fake has no reason to reimplement.

Both implement the real interface, so PHPStan fails the build if a fake
drifts from the contract — the drift ADR-051 could not prevent for a
consumer's own copy is now caught here.

The fakes are excluded from container autoconfiguration
(``Classes/Testing/*`` in the ``Configuration/Services.yaml`` exclude
list) and add no ``public: true`` override, so the audited public-service
count (ADR-028 / ADR-065 / ADR-069) is unchanged. Their docblocks mark
them as consumer test fixtures, not for production wiring.

.. _adr-073-consequences:

Consequences
============

- A consumer deletes its hand-rolled fake and type-hints
  ``Netresearch\NrLlm\Testing\Fake*`` instead; the double tracks the
  interface automatically.
- The ``Testing\`` namespace is a supported public surface. Renaming a
  fake's properties or methods is a breaking change for consumers and
  belongs in a release note.
- The fakes cover the tool-calling and embedding interfaces named in
  ADR-051. Other feature interfaces (completion, vision, translation)
  gain a first-party fake only when a consumer needs one.
