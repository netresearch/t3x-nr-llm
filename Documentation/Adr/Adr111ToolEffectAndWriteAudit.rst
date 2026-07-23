.. include:: /Includes.rst.txt

.. _adr-111:

============================================================================
ADR-111: Tool side effects and fail-closed audit for writes
============================================================================

:Status: Accepted
:Date: 2026-07-23
:Authors: Netresearch DTT GmbH

.. _adr-111-context:

Context
=======

The agent queue is at-least-once (:ref:`ADR-102 <adr-102>`). A worker renews its
lease only at a step boundary, which fires *after* an operation completes, so a
provider or tool call that outlives the ``LEASE_SECONDS`` lease is reaped and the
run re-executed (:ref:`ADR-104 <adr-104>`). The ownership guard on ``finishRun``
prevents a double *settle*, but not a double *execution*: a tool's side effect
can land twice.

That is harmless for the tools shipped today — all are read-only. It is not
harmless for the WRITING tools the roadmap will add. Two gaps must close *before*
the first write tool ships:

1. :php:`AgentRunPersister::recordStep()` is fail-soft — a store hiccup is logged
   and swallowed. A tool can therefore execute with its audit event never
   persisted. For a write, that is an unrecorded mutation the run then reports
   success over.
2. Nothing tells the runtime whether re-running a reaped operation is safe.

.. _adr-111-decision:

Decision
========

Classify a tool's side effect and act on it.

``ToolEffect``
--------------

A three-value enum: ``READ_ONLY``, ``IDEMPOTENT_WRITE``, ``NON_IDEMPOTENT_WRITE``.
A tool declares it by implementing :php:`ToolEffectInterface::getEffect()`. A tool
that does NOT implement it is ``READ_ONLY`` — the opt-in default that keeps all
shipped builtins unchanged, and the reason a write must declare itself rather
than be inferred. The value is a property of the CODE and is not configurable, so
an administrator cannot relabel a write to dodge the guarantees. Resolution BY
NAME (:php:`ToolEffectResolver::effectFor()`) is stricter: an unknown name — a
stale or removed tool referenced in a persisted step — resolves to
``NON_IDEMPOTENT_WRITE``, the class that is both audit-critical and never
auto-retried.

Fail-closed audit for writes
----------------------------

``recordStep()`` still never throws, but now RETURNS whether it persisted. The
runtime fails a run whose WRITING tool executed but whose audit event could not
be stored — :php:`AuditPersistenceFailedException` — rather than continuing over
an unrecorded write. Read-only and non-tool steps keep the fail-soft behaviour:
a transient database blip does not fail an observe-only run.

No auto-retry for a failed write
--------------------------------

:php:`AuditPersistenceFailedException` carries no ``FailureClass`` of its own, so
:php:`FailureClassifier` maps it to ``UNKNOWN`` — deliberately not retryable
(:ref:`ADR-095 <adr-095>`). A queued run is dead-lettered (``NOT_RETRYABLE``); an
interactive run settles ``FAILED``. Re-running would re-execute the write, which
already ran once.

.. _adr-111-consequences:

Consequences
============

- The classification is the precondition, not the whole story. Two follow-ups
  build on it: withholding auto-retry from a ``NON_IDEMPOTENT_WRITE`` whose *own*
  execution (not just its audit) was interrupted, and a lease-extension-before-op
  (``operation_deadline``) so a long write is not reaped mid-call. Both need this
  enum first.
- ``ToolEffect`` is a separate opt-in interface, like :php:`ToolDataClassInterface`
  (:ref:`ADR-094 <adr-094>`); promoting it onto :php:`ToolInterface` is a later,
  announced breaking change.
- No builtin writes yet, so in production ``recordStep`` remains effectively
  fail-soft today — the machinery is in place for the first write tool, which is
  exactly when it must already exist.
