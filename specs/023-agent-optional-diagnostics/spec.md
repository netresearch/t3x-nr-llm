<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# Contain optional agent diagnostics

ADR225. Publish this decision as a separate signed PR on main, then create the implementation from its exact published signed head. This restores existing lifecycle and persistence failure contracts, without introducing new outcomes or a public logger API.

## Executed defect

On frozen f10fab7582e2823b5534545c7a5959d380dd6442, a real PSR-3 logger whose log method throws replaces normal AgentRunExecutor results for failure, guardrail denial, cooperative cancellation and lease loss. QueuedRunFailureRecovery also loses its original error or ownership outcome; AgentRunPersister::begin throws a logger error instead of returning null when storage is unavailable. A canonical22-case probe yields11 pure assertion failures and11 passing recording-logger controls, zero framework errors/warnings; all three production files equal the frozen commit. An initial probe with an incorrect fixture property produced a warning and is excluded.

ADR101 promises settled lifecycle outcomes rather than thrown run failures. ADR104 and the recovery source promise fail-soft queued recovery. The persister promises null/false on unavailable storage. Optional diagnostic emission must preserve these existing contracts.

## Required behavior and design

1. Contain Throwable raised by optional diagnostic emission inside AgentRunExecutor, QueuedRunFailureRecovery and AgentRunPersister. An internal shared private callback guard invokes the existing logger severity method exactly once and contains only that diagnostic invocation. Preserve level, message, context values, ordering and the absence of a logger. The guard emits no secondary log, so a failed logger cannot recurse.
2. Preserve the original lifecycle outcome, run UUID, steps, original error identity and guardrail class when a logger fails. Cancellation and lease loss must still leave a row untouched; an ownership-refused settle must not overwrite another worker. Successful approval/input suspension must retain resumable state, and a failed suspension must retain its existing fail-closed result.
3. Preserve queued classification, original error, guarded settlement, retry budget, write fence and dispatch behavior. A diagnostic failure must neither invent a recovery failure nor change NOT_RETRYABLE into RETRIES_EXHAUSTED. Genuine recovery or dispatch failures keep their existing dead-letter handling and ownership guards.
4. Preserve the persister's exact existing fallback values and safety interpretation when storage throws: null handles, false mutations, empty or nullable read lists and unavailable positions remain as specified by their current methods. Nullable inbox-read failures stay null; explicitly propagated caller InvalidArgumentException contracts continue to throw. Containment applies to optional logging; it must never convert a failed mandatory audit, durable write fence, ownership transition or suspension into success.
5. Do not change public signatures, result enums, database schema, retention, raw-response privacy or logging contents. Other agent consumers' diagnostic sites remain separately auditable; this bounded implementation does not certify the entire family.

## Acceptance and tests of tests

- Canonical Unit tests use the actual executor, persister and recovery with an independent recording repository and a real throwing PSR-3 logger, not a stubbed subject. Literal assertions cover outcomes, original errors, recorded statuses/reasons, ownership refusal and the absence of forbidden settlement. Pair failing-logger cases with successful recording controls so invalid fixtures cannot masquerade as defects.
- Extend the executed22-case counterexample with suspension, policy approval, both ownership-refusal arms and real recovery-internal failure. Exercise actual persister storage failures across mutations and reads; preserve their current fallbacks and fail-closed fence/audit decisions. Repository doubles prove local contract behavior, not SQL concurrency. Existing Functional ownership/fence/suspension suites remain required.
- Capture normal logger calls and independently assert the exact existing level, message and essential context, plus the original failure object when logged. A missing logger remains valid. Verify successful persistence and loop completion controls.
- Selected source faults removing containment in each of the three consumers must fail genuine assertions with zero framework errors/warnings. Separate faults that swallow the diagnostic entirely or alter a guarded result/reason must be detected by independent logging/outcome assertions. Restore exact source bytes after every fault.
- Final publication requires complete independent source/test/manual review, full make gate, strict renderer baseline comparison and exact final file hashes. Fresh external CI on the published head and actual branch dependencies remain mandatory before normal history-preserving merge.

## Scope

Source is the shared lifecycle/recovery and AgentRunPersister; tests belong to Tests/Unit/Service/Agent and Tests/Unit/Service/Tool. The current Agent Runs manual should explain optional diagnostics alongside the existing authoritative persisted run state. No new diagnostic format, telemetry service, public DI alias, actor authorization, approval requirement or retry policy is introduced. The repair does not declare every logger in the extension safe.
