.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-221:

===================================================
ADR-221: Skill synchronization holds a durable lease
===================================================

:Status: Accepted
:Date: 2026-10-10
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-035 <adr-035>` namespaces imported skills by their persisted
source. The backend saves a source before offering synchronization, and
``SkillSourceController::syncAction()`` loads that saved row. There is no
documented consumer contract for synchronization without a source row.

``SkillSyncService`` currently checks the status and heartbeat on its
loaded entity and then persists ``syncing``. Two requests can hold stale
entities: the second can enter while the first row is already syncing.
Reclaim, renewal and completion also write ordinary entity updates. A
late worker can therefore overwrite a successor's state. A timestamp
alone cannot identify ownership when two claims happen in one second.

Decision
========

Synchronize only a persisted source that is neither physically absent
nor soft-deleted by TYPO3. Missing or soft-deleted rows fail
closed before remote contact or skill changes. Detached test fixtures
must become real persisted sources; tests cannot define an unsafe
production exception. The internal service and source repository are
outside the frozen ``@api`` surface.

Add an internal database lease with a fresh, opaque random token for
each successful claim. Claim is one conditional update of the source
row, using its actual status and heartbeat. A fresh ``syncing`` row
refuses another claim. An interrupted or sufficiently skewed heartbeat
remains reclaimable under the existing 180-second window. Tokens are
internal bookkeeping and are absent from FormEngine and responses.

Renewal requires the matching token and a live lease. Reclaim checks
the current persisted expiry condition in the same write that changes
the state. Terminal release matches the owner token; losing ownership
cannot clear, renew or overwrite a successor's state or pinned SHA.

Remote collection runs outside a transaction. After collection, a
conditional renewal confirms ownership before materialization. Hold
the source-row write lock in a short transaction through skill upserts,
orphaning, their persistence and conditional terminal release. This
closes the gap between checking a lease and publishing imported data.
No remote request occurs while that publication transaction is held.

Source, skill and skill-audit writes must use the same database
connection for this transaction. A configuration that splits these
tables across connections fails closed before synchronization; an
ordinary local transaction cannot promise atomic publication across
independent databases. Failed publication rolls back its database
changes and leaves a retryable diagnostic when the worker still owns
the lease. Its published-change counters are all zero after rollback.
An expired or replaced worker publishes no collected data.

Consequences
============

The implementation follows this decision in a separate dependent PR.
``specs/019-skill-sync-durable-lease/spec.md`` defines the acceptance
oracles. Existing token handling, immutable upstream revisions,
fingerprint verification, review, bounds and orphaning rules remain.

The schema gains internal ownership bookkeeping. There is no guessed
backfill: an existing ``syncing`` row keeps its heartbeat and can be
reclaimed by the expiry rule. Long remote calls may outlive the window;
their worker must discard the result and retry after losing its lease.
Lease expiry is not cancellation of an already running HTTP request.

Real database interleavings prove claim, renewal, reclaim, publication
and release. Mutation counterexamples remove ownership or expiry
conditions and must fail on state or imported-content assertions.
These tests establish local persistence behavior, not a live GitHub
availability guarantee.

Alternatives considered
=======================

Refreshing the entity before checking is insufficient: another worker
can claim between the read and the write. Comparing only
``last_synced`` admits a same-second ownership ambiguity. An atomic
entry claim followed by unconditional renewal or release still lets
an old worker disturb its successor. Holding a transaction across
remote collection would serialize slow network calls and is avoided.
