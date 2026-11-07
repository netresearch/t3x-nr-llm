.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-270:

======================================================================
ADR-270: Run privacy accounts for actual provider selection
======================================================================

:Status: Accepted
:Date: 2026-11-07
:Amends: :ref:`ADR-242 <adr-242>` (actual attempted providers and durable
    ceilings replace configuration-only run policy), :ref:`ADR-063 <adr-063>`
    (persisted agent calls bypass provider-response idempotency replay)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-242 <adr-242>` resolves a run's privacy level from its fixed
configuration model. Criteria selection and fallback can use another provider.
The request event exists before resolution, and a failed attempt may already
have received the request. Successful-response provenance is insufficient.

The global ``metadata`` default retains a background answer as functional output
under :ref:`ADR-234 <adr-234>`. An explicit provider or task ``metadata`` ceiling
must prohibit that answer even when it equals the global level.

Decision
========

Before each resolved agent chat or tool attempt, apply the actual provider's
ceiling to the run. This includes failed attempts and every fallback. Unknown
provider identity or an unreadable policy must fail closed. Enumerating possible
providers in advance is insufficient when routing changes after enumeration.

Persist separate monotone ceilings for audit content and background answers,
alongside attempted provider identifiers. Store no prompts, credentials or
endpoint URLs in this provenance. Resume, requeue and a looser fallback may only
retain or tighten the ceilings.

Serialize tightening and event/answer writes on the same run-row database lock.
Before a stricter provider attempt, refilter previous run-step payloads and any
stored answer within that transaction. If filtering, locking or persistence
fails, roll back and refuse the attempt. Every later content write rereads the
ceiling under that lock; an old in-memory handle cannot restore removed data.
Use a current locking read on databases whose repeatable-read snapshots can
otherwise return a stale row. Run and event tables must share a connection.
Refuse provider dispatch within an outer transaction: releasing a savepoint
would not make the ceiling durable before the provider receives the request.

Envelope rotation updates a sealed column only if its previous value still
matches. A concurrent scrub or state change aborts the rotation transaction;
the old key remains valid and removed answers cannot be restored by rotation.

Persisted agent calls bypass the provider-response idempotency cache. Historical
cache entries do not identify their originating run and retention ceiling, so a
cross-run replay could bypass the actual-provider boundary. Ordinary non-run
requests retain response replay; tool execution idempotence is unaffected.

Preserve approval and lineage metadata and the encrypted functional queue and
suspension state. Those states retain their existing lifecycle and purge rules.
This decision changes retention of audit copies, not which data may be sent.

Compatibility and rollout
=========================

The implementation adds an optional trailing retention collaborator to
the public service manager constructor. Existing non-run callers remain
compatible. Calls carrying a persisted run must refuse execution when that
collaborator is missing. The concrete repository requires its retention guard.
Production dependency injection and manually constructed integrations must wire
both boundaries. The proposal precedes the implementation change.

Install the added run columns through the TYPO3 database schema update before
deploying workers. Missing columns must block guarded writes and dispatch.
An empty ceiling on an older row means uninitialized historical policy: the
first guarded write or attempt applies the current policy and filters older
content when necessary. Do not invent historical provider identities. This is
lazy reconciliation, not a bulk purge of inactive historical runs.

Acceptance criteria
===================

- Persisted-row tests cover criteria selection, fallback, failed attempts,
  resume, old request payloads and background answers.
- Explicit provider/task ``metadata`` suppresses answers at a global
  ``metadata`` default; that default alone still permits them.
- A stale handle and an independent database connection cannot restore content
  after tightening. A scrub failure rolls back and prevents dispatch.
- Dispatch tests prove the boundary runs before both chat and tool adapters;
  production-container tests prove the guard is actually wired.
- Historical rows remain readable, missing schema fails closed, and no
  historical attempt provenance is fabricated.
- A conflicting envelope rotation refuses to overwrite newer state.

Verification
============

:php:`RunContentPrivacyGuardTest` inspects stored rows, rollback, production
dependency injection and independent-connection locking behavior.
:php:`RunProviderPrivacyDispatchTest` covers both dispatch paths and failed
attempts. :php:`IdempotencyMiddlewareTest` proves persisted calls bypass replay.
These tests use synthetic data; they do not establish deployment retention or
recall copies in backups, exports or provider systems.

Consequences
============

An early request can already have existed under the initial policy. Filtering
before a stricter attempt removes the live database copy, not copies already
read, exported or backed up. Operators must account for those separately.

Tightening makes an additional transaction and may refilter older events.
Unreadable historical content can stop a run until it is repaired or removed.
The guard does not classify personal data, prove provider geography, or apply
run-retention policy to evaluation results or answer caches.
