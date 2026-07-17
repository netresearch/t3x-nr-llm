.. include:: /Includes.rst.txt

.. _adr-079:

============================================================================
ADR-079: First-party fakes for Completion, Vision and Budget services
============================================================================

:Status: Accepted
:Date: 2026-07-17
:Authors: Netresearch DTT GmbH

.. _adr-079-context:

Context
=======

:ref:`ADR-073 <adr-073>` shipped maintained, first-party test doubles for
the tool-calling and embedding feature services under the runtime-autoloaded
``Netresearch\NrLlm\Testing\`` namespace, because consumers were hand-rolling
doubles that fatal the moment the interface grows. It deliberately deferred
the remaining feature interfaces: "Other feature interfaces (completion,
vision, translation) gain a first-party fake only when a consumer needs one."

That consumer now exists. ``nr_repurpose`` (podcast/diagram/story generation)
consumes ``CompletionService`` and ``VisionService`` and gates image/speech
spend through ``BudgetServiceInterface`` — and its hand-written
``BudgetServiceInterface`` double is exactly the one that broke across
consumers when ``check()`` gained its ``?LlmConfiguration`` parameter (the
0.20 signature change), the failure ADR-073 exists to prevent.

.. _adr-079-decision:

Decision
========

**Ship three more first-party fakes**, extending ADR-073 to the surfaces a
concrete consumer now uses:

- ``FakeCompletionService`` — the six ``CompletionResponse``-returning methods
  (``complete``, ``completeFactual``, ``completeCreative`` and their
  ``*ForConfiguration`` twins from :ref:`ADR-077 <adr-077>`) draw from a FIFO
  ``$responses`` queue; ``completeJson*`` and ``completeMarkdown*`` return
  canned properties. Every call is recorded.
- ``FakeVisionService`` — the four string-or-array methods return the canned
  string echoing the caller's arity (single image → string, batch → one per
  input); ``analyzeImageFull`` returns a canned ``VisionResponse``.
- ``FakeBudgetService`` — ``check()`` returns a canned ``BudgetCheckResult``
  (allowing by default) and records every call.

Each is a plain ``final class`` (not ``readonly`` — the canned-value and
call-recording properties are set by tests) implementing the real interface,
so PHPStan level 10 keeps the double in lock-step with the production
contract. Each carries a one-shot ``$throwable`` to exercise a consumer's
error path.

Beyond ADR-073's named trio, this includes **Budget** (not a
``Service\Feature\`` interface, but the one whose signature drift concretely
broke consumers) and omits **translation** (no consumer needs it yet — the
same demand-pull rule ADR-073 set).

.. _adr-079-consequences:

Consequences
============

- Consumers type-hint their unit tests against a maintained fake instead of a
  hand-rolled double; a future signature change fails the build *here* rather
  than silently in every consumer.
- The three interfaces each gain a second implementor (the fake). A future
  signature change now has a production + fake blast radius — intended: the
  fake failing PHPStan is the guardrail.
- Purely additive and DI-neutral: ``Classes/Testing/*`` is already excluded
  from container autoconfiguration (``Configuration/Services.yaml``) and
  covered by the production PSR-4 autoload, so the public-service count
  (:ref:`ADR-028 <adr-028>`) is unchanged and consumers get the doubles
  without nr_llm dev dependencies.
- The ``Testing\`` surface (property and method names) is a supported public
  API, as ADR-073 established: renames are breaking changes and belong in
  release notes.
