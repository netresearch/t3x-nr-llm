.. include:: /Includes.rst.txt

.. _adr-051:

====================================================================
ADR-051: Tool-calling feature service — narrow consumer interface
====================================================================

:Status: Accepted
:Date: 2026-07-12
:Authors: Netresearch DTT GmbH

.. _adr-051-context:

Context
=======

Every capability except tool calling has a narrow feature-service
interface (``CompletionServiceInterface``, ``EmbeddingServiceInterface``,
``VisionServiceInterface``, ``TranslationServiceInterface``) whose
docblocks tell consumers to depend on the interface, not the concrete
class. Tool calling is the exception: ``chatWithTools()`` and
``chatWithToolsForConfiguration()`` exist only on
``LlmServiceManagerInterface`` — a nineteen-method surface.

A consumer that needs exactly tool calling therefore binds to the whole
manager interface. That coupling is not theoretical: ``nr_ai_search``
needs a single method, keeps a hand-written fake of the manager
interface for its unit tests, and that fake fatals every time the
manager interface grows a method — its 0.13 → 0.16 update was blocked in
CI by the two methods 0.14 added, none of which it calls.

.. _adr-051-decision:

Decision
========

Add the missing feature-service pair, mirroring the existing pattern:

- ``Netresearch\NrLlm\Service\Feature\ToolCallingServiceInterface``
  with exactly the two tool-calling entry points,
  ``chatWithTools()`` and ``chatWithToolsForConfiguration()``,
  signature-identical to the manager's.
- ``Netresearch\NrLlm\Service\Feature\ToolCallingService`` delegating to
  ``LlmServiceManagerInterface``, adding only the feature-service
  standard beUserUid auto-population
  (``AutoPopulatesBeUserUidTrait``, REC #4) so per-user budget
  enforcement works without caller wiring.
- Registered in ``Configuration/Services.yaml`` like the other feature
  services (public service + public interface alias).

The manager keeps its methods unchanged — this is additive; the feature
service is the documented consumer entry point going forward.

.. _adr-051-consequences:

Consequences
============

- A tool-calling consumer's test double is two methods, and additions to
  ``LlmServiceManagerInterface`` no longer break consumers that do not
  call them.
- The feature-service catalogue now covers every capability, so the
  integration guide's "depend on the feature interface" rule holds
  without exception.
- Consumers pinning a provider or configuration keep doing so through
  ``ToolOptions`` / the ``LlmConfiguration`` parameter — the service adds
  no second way to select one.
