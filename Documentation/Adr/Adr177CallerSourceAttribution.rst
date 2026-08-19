.. include:: /Includes.rst.txt

.. _adr-177:

==============================================================================
ADR-177: Caller-source attribution on the options path
==============================================================================

:Status: Accepted
:Date: 2026-08-18
:Authors: Netresearch DTT GmbH
:Amended: 2026-08-19 by :ref:`ADR-178 <adr-178>`

Context
=======

A telemetry row names what ran (operation, provider, model, configuration)
and who was logged in (``be_user``) — but not **which piece of software
called**. Every consumer-facing entry point looks the same in the table: a
wizard task, a scheduler run and a downstream extension all appear as the
operation they used.

The compatibility layer (`nr-llm-compat
<https://github.com/netresearch/t3x-nr-llm-compat>`__) makes this gap
concrete: it reroutes third-party AI extensions (ai_seo_helper, ns_t3ai,
ai_filemetadata, …) through nr-llm at runtime, and its design wants every
intercepted request attributable to its origin — so telemetry doubles as an
AI inventory of the installation (issue `#816
<https://github.com/netresearch/t3x-nr-llm/issues/816>`__). Today it has no
channel to say who it is: ``complete()`` and ``completeJson()`` accept only
an options object, and nothing that reaches ``TelemetryMiddleware`` carries a
caller identity.

Two channels exist and both are wrong for this. The ``$metadata`` array on
the ``*WithConfiguration()`` methods is positional plumbing the feature
services do not expose, and inventing a parallel entry point per feature
service would multiply the public surface. A global "current caller" service
would be state smuggled past the call, breaking for nested calls (a tool
loop calling on behalf of another caller).

Decision
========

**The caller identity travels on the options object** — the same channel the
idempotency key already uses, with the same wire-leak rule.

#. ``AbstractOptions`` gains ``withCallerSource(string $extension, string
   $operation = ''): static`` plus the two getters. Like
   ``$idempotencyKey``, the fields are **never** part of ``toArray()``: they
   are call metadata, not provider options, and must not reach the provider
   wire.
#. ``CallMetadataFactory::callerSource(AbstractOptions $options)`` maps them
   into the pipeline metadata (keys ``sourceExtension`` /
   ``sourceOperation``, published as constants), and every consumer-facing
   entry point in ``LlmServiceManager`` that already sums
   ``budget() + idempotency()`` metadata adds this term.
#. ``TelemetryMiddleware`` persists them: ``tx_nrllm_telemetry`` gains
   ``source_extension`` and ``source_operation`` (``varchar(64)``, ``''``
   default), threaded through ``TelemetryRecord``. An unannotated call
   writes ``''`` — indistinguishable from today's rows, so nothing changes
   for existing consumers.

The identity is two short strings, not a payload: the extension key of the
calling software and the operation inside it (e.g. ``ai_seo_helper`` /
``requestAi``). Naming stays with the caller; nr-llm records, it does not
validate.

Alternatives considered
=======================

* **Metadata parameter on the feature-service interfaces** — a new parameter
  on every ``@api`` method (breaking or default-parameter creep) for a value
  that is cross-cutting, not per-method.
* **A registered "current consumer" context service** — ambient state; wrong
  answer under nesting (skills/tools calling the pipeline while serving
  another caller), and invisible in the call signature.
* **Deriving the caller from a backtrace** — magic, fragile under DI
  inlining and proxies, and wrong for queued/deferred execution.

Consequences
============

* Downstream consumers annotate with one wither and get per-source telemetry;
  the compatibility layer tags every bridge call.
* The channel is metadata-only (two identifiers). Privacy semantics
  (ADR-064) are untouched: no payload, no new retention class — the purge
  command already covers the telemetry table.
* Attribution is honest but unauthenticated: a caller can claim any name.
  Telemetry is an observability surface, not an access-control one (access
  control stays with configurations and budgets).
* Analytics aggregation by source (dashboard widget, module filter) is
  deliberately not part of this record; it can follow as UI work once rows
  carry the columns. Amended by :ref:`ADR-178 <adr-178>`: the follow-up
  turned out to need a second persistence decision, because cost lives in
  the usage table and not in telemetry.
* ``api-surface.txt`` grows additively (new ``AbstractOptions`` methods).
