<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Actual-provider retention for agent runs

## Requirements

- Before an agent chat/tool attempt reaches its resolved provider, apply that
  provider's retention ceiling, even if the attempt later fails. Criteria
  selection and fallback must use the resolved provider identifier.
- Persist monotone audit and background-answer ceilings on the run. The global
  metadata default may retain a background answer; an explicit provider/task
  metadata ceiling may not. Resume and stale handles cannot loosen either.
- When tightening, scrub already stored run-step payloads and answers before
  dispatch. A scrub failure rolls back and refuses the attempt. Event and answer
  writes use the same run-row lock and re-evaluate the persisted ceilings.
- Preserve metadata and functional queued/suspended state. Those states remain
  encrypted and follow their existing lifecycle/retention rules.

## Boundaries and design

An initial request can already have been stored under its initial policy. This
change removes that copy before a stricter provider is attempted; it cannot
recall previously read/exported copies or backups. It is not an egress control.
Provider identities are stored without prompts, credentials or endpoint URLs.
Run/event tables must share one database connection for atomic tightening.
Provider dispatch inside an already open database transaction is refused: a
savepoint release would not make the privacy changes durable before the send.
Empty legacy ceilings are reconciled lazily; historical provider identities are
not inferred. Missing schema columns make guarded writes/dispatch fail closed.

Enumerating possible providers before the run is rejected: routing may change
between enumeration and dispatch, and unused providers would suppress otherwise
permitted background results. The actual attempt is the enforcement boundary.

The LlmServiceManager constructor gains an optional trailing internal privacy
guard collaborator. Existing callers remain compatible; production DI supplies
it. No callable method signature changes. ADR-270 amends ADR-242 and ADR-063.

Persisted runs bypass provider-response idempotency replay, whose historical
entries cannot attest the originating run policy. Non-run replay and tool
execution idempotence are unchanged. Envelope rotation compares the old sealed
value when updating; a concurrent scrub/state change aborts rotation so it
cannot resurrect content and the old key remains valid.

## Verification

Functional tests inspect stored rows for criteria and fallback provider attempts,
failed attempts, old request scrubbing, resumed/stale writes, explicit metadata
at a global metadata default, monotonicity and scrub rollback. Unit dispatch
tests check the guard executes before the adapter on both agent call paths.
The API snapshot records the intentional optional constructor addition.
The final gate is make gate; no real provider calls or costs are required.

Locking rationale: SQLite permits one concurrent write transaction; the leading
UPDATE acquires that write boundary before any policy read. MySQL/InnoDB and
PostgreSQL take a row lock through the UPDATE, followed by a SELECT FOR UPDATE
current read for the persisted ceiling and event rows. This avoids a caller's
earlier repeatable-read snapshot restoring content. See the primary references:
https://www.sqlite.org/lang_transaction.html and
https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html.
An independent SQLite connection test asserts blocked write then current-ceiling
filtering after commit. This is not a stress test of every database backend.
