<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Durable skill synchronization lease

Status: specified before implementation. Decision: ADR-221.

## Capability and boundaries

Correct the existing SkillSyncService concurrency promise using persisted
ownership. Keep the GitHub client, parsing, fingerprint gate, review model,
version-change detection and scoped orphaning. No new frozen @api signature,
background queue, remote cancellation or multi-database transaction protocol.
Lease identifiers are internal random metadata, never credentials or output.
The implementation PR depends on the separate ADR/spec PR.

## Requirements

1. A null, non-positive, physically missing or TYPO3 soft-deleted source UID
   returns ERROR with a
   stable local diagnostic before any remote request, skill write or source
   insertion. A saved source is the unit of synchronization. Existing detached
   functional fixtures must be replaced by genuine rows.
2. Claim atomically updates the actual source row with SYNCING, heartbeat and a
   new opaque owner token. A second request with a stale entity cannot enter
   while that persisted lease is live. Different successful claims have
   different tokens even if their timestamps are equal.
3. Preserve the existing expiry rule: last_synced=0 or an absolute timestamp
   difference greater than 180 seconds is stale. The exact 180-second boundary
   is live, including a small future skew. Reclaim writes ERROR and removes
   ownership only when the current row is still stale and SYNCING. A stale
   loaded entity cannot reclaim a renewed or completed row.
4. Renewal matches UID, owner token, SYNCING and a still-live heartbeat. A lost
   or expired lease stops collection/publication. Throttling cannot bypass the
   final ownership confirmation after a slow remote fetch.
5. Collection and remote contact happen outside the publication transaction.
   Before any collected skill or orphan state changes, a conditional live-owner
   update obtains the source-row write lock. Upserts, orphan changes, audit
   writes, persistence and conditional source completion share a short database
   transaction. Ownership loss publishes no new collected data. A publication
   exception rolls back that transaction and reports zero published-change
   counters: created, updated, disabledOnChange, orphaned and injectionBlocked.
6. A terminal source update, including an error path, matches the owner token.
   An older worker cannot clear or modify a newer lease, its status, error,
   heartbeat or pinned SHA. Same-second replacement must not pass this guard.
   Source bookkeeping must not escape the guard through an ordinary Extbase
   flush. A lost owner returns ERROR without pretending its work completed.
7. The source, skill and skill-audit tables must resolve to the same database
   connection before work begins; a split mapping returns ERROR without remote
   contact or partial publication. The schema field stays outside editable TCA
   and public output. Existing rows require no invented ownership backfill.
8. Preserve healthy success/partial counters, actual imported bytes, disabled
   review defaults, body/version digests, manifest rejection and scoped orphan
   safety. Document interrupted-lease recovery, the persisted-source contract,
   the database-connection constraint and slow-fetch lease loss.

## Acceptance evidence

| Requirement | Suite and behavioral oracle |
|---|---|
| Missing and separately soft-deleted source fail before outbound contact and writes | Functional SkillSyncServiceTest with real SQLite rows and call counters for each case |
| Stale snapshot cannot enter a live sync | Functional deterministic nested client callback; persisted SYNCING asserted before the competing call |
| Same-second tokens and exact expiry/skew boundaries | Functional lease repository tests with controlled timestamps and actual row state |
| Stale snapshot cannot reclaim a renewal/completion | Functional repository/service interleavings and unchanged row assertions |
| Lost renewal / slow-fetch ownership loss publishes nothing | Functional fake-client callback replacing the persisted lease; actual skill rows and successor metadata asserted |
| Conditional publication and release, including same-second replacement | Functional transaction/ownership tests plus persisted skill/content and source-row assertions |
| Publication failure rollback and retryable diagnostics | Functional controlled persistence failure after an attempted write; database rollback and all five zero counters asserted |
| Connection mapping constraint and internal schema/output | Functional connection configuration counterexample; Unit schema/TCA contract checks |
| Existing ingest and review contracts | Complete Functional SkillSyncServiceTest, SkillIngestIsolationTest and SkillVersionDigestSyncTest families |
| Operator guide and decision navigation | Documentation inspection and strict render |

Use the shared test runner and make gate. Keep every new PHP test in a
configured suite. At least the entry, renewal, reclaim and release ownership
guards require deliberate removed-condition counterexamples that fail on
assertions, followed by exact source restoration. A framework error or timeout
is not an ownership oracle. Local SQLite evidence does not claim concurrent
live GitHub interoperability; the CI database matrix must pass before merge.
