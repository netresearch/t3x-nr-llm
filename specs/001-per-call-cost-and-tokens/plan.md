# Implementation plan — per-call cost and real token counts

**Spec**: `spec.md` | **Created**: 2026-08-16 | **Status**: Draft | **Constitution**: `.specify/memory/constitution.md`

## Technical context

PHP 8.2–8.5, TYPO3 13.4 and 14.3, PHPStan level 10, Doctrine DBAL. The write paths involved are the provider middleware pipeline (`Classes/Provider/Middleware/`) and the two tables in `ext_tables.sql`. No new dependency.

Scoped instructions that bind this change: `Classes/AGENTS.md` for source patterns, `Configuration/AGENTS.md` for anything reaching TCA, `Tests/AGENTS.md` for suite placement, `Documentation/AGENTS.md` for the ADR format.

## What already exists

Measured rather than assumed, because three of the six requirements turn out to be partly satisfied and planning against the issue text alone would have duplicated them.

| Requirement | Present today |
|---|---|
| FR-002 model actually served | `tx_nrllm_telemetry.served_model`, `served_provider`, `served_configuration_identifier` — written when a swap happened, equal to the requested triple otherwise |
| FR-004 correlation id | `tx_nrllm_telemetry.correlation_id varchar(36)` with `KEY correlation`, a UUID v4 minted per call in `ProviderCallContext` |
| FR-003 retry count | `fallback_attempts` — satisfied in full; see the resolution below for why a hop is the only thing "retry" can mean here |
| FR-001 real token counts | absent from telemetry; the aggregate has `prompt_tokens` / `completion_tokens` summed per day |
| FR-006 cost | absent from telemetry; the aggregate has `estimated_cost` |
| FR-005 null ≠ zero | already the convention in this table: `complexity_tokens` is `DEFAULT NULL` with the comment "NULL where no context fit ran, which is not the same as an empty send" |

## Decision 1 — extend telemetry, not the usage aggregate

The per-call record goes into `tx_nrllm_telemetry`. Three reasons, in order of weight.

It is already per-call: `TelemetryMiddleware` writes exactly one row per run, on success and failure alike (ADR-058). It already carries the join key the spec asks for, indexed. And it already keeps unmeasured values as `NULL` — the distinction FR-005 demands is this table's existing habit, not a new rule imposed on it.

`tx_nrllm_service_usage` is the wrong home by construction. It is a daily aggregate, its `estimated_cost` is `decimal(14,6) NOT NULL DEFAULT '0.000000'`, and that column width is deliberately aligned with the budget-ceiling columns so a day's cost can be compared against a configured ceiling. A nullable per-call cost cannot live there without either breaking that comparison or turning the aggregate into something it is not.

**Consequence accepted knowingly**: the daily aggregate keeps writing `0.0` for a zero-priced model, because `UsageTrackerService` reads `$metrics['cost'] ?? 0.0` into a `NOT NULL` column. That stays. Changing it would alter budget arithmetic, which this feature has no mandate to touch, and FR-007 forbids behavioural change. The truth now lives in the per-call row; the aggregate remains a budget instrument rather than a measurement.

## Decision 2 — carry the values on the existing scratchpad

`UsageMiddleware` (priority 25) computes tokens and cost; `TelemetryMiddleware` (outermost) writes the row. They cannot pass a modified context: the pipeline threads one immutable `ProviderCallContext` and `$next` forwards only the configuration. The channel that survives the unwind already exists and is documented as exactly this — `TelemetrySignals`, a mutable scratchpad reachable from the shared context, which `CacheMiddleware` and `FallbackMiddleware` already annotate on the way in.

So: no new plumbing. `UsageMiddleware` records the measured values on `TelemetrySignals`; `TelemetryMiddleware` reads them into the row it already writes.

ADR-026 says the context carries no payload. Token counts, a model identifier and a cost are cross-cutting observability state rather than payload, which is the same argument the class already makes for itself, so this stays inside that rule rather than bending it.

## Decision 3 — nullable columns, and what null means

New columns on `tx_nrllm_telemetry`, all `DEFAULT NULL`:

- `usage_prompt_tokens int(11) unsigned DEFAULT NULL` — reported by the provider, not estimated
- `usage_completion_tokens int(11) unsigned DEFAULT NULL`
- `usage_cost decimal(14,6) DEFAULT NULL` — derived from the two above and the serving model's pricing

Null means "not measured" in all three, and the three are independent: a provider may report tokens for a model with no pricing, which yields tokens and a null cost. Nothing derives a cost from an estimate; `complexity_tokens` stays what it is, an estimate, in its own column.

Existing rows keep `NULL` and are therefore not mistakable for measured zeros — the same property the table's comment already claims for the pre-existing served columns.

## Public surface

`TelemetrySignals` is `@api` and frozen in `Tests/Unit/Api/api-surface.txt` at line 1064, with every method and property listed. Adding a recorder method and its properties is **additive**: regenerate the snapshot and add a `### Added` entry, per the rule in the root `AGENTS.md`. It is not a breaking change and does not touch `Documentation/Api/Deprecation.rst`.

`TelemetryRecord` does not appear in the surface file, so its constructor is free.

An ADR is required — the public surface moves. It records the two decisions above and their alternative, in `Documentation/Adr/` as `Adr<N>Description.rst`, not as Markdown under `specs/`. It also has a relationship to state: ADR-156's third activation criterion becomes computable, and ADR-058's "one row per run" is what makes SC-001 hold.

## Resolved — FR-003 needs nothing

This was raised as an open question ("is a retry a fallback attempt, or a retry against the same provider?") and then answered by reading the code rather than by choosing.

There is no provider-level retry in this system, by design. `FallbackCandidateResolver` (ADR-137) removes the primary's own identifier from its chain, documented as "**No self-retry.** The primary's identifier is removed from its own chain; a configuration listing itself yields no candidate." `FallbackMiddleware` increments the counter only when it swaps to another configuration, and its comment states that the primary attempt is not counted.

So a "retry" here can only mean a fallback hop, and `tx_nrllm_telemetry.fallback_attempts` already records it. **FR-003 is satisfied by existing data. No column, no signal, no task.**

Worth keeping as a note for whoever reads FR-003 later: the number is hops, not attempts including the first. A call that succeeded on its second configuration reads `1`.

## Compatibility

`ext_tables.sql` additions apply to TYPO3 13.4 and 14.3 identically; the columns are additive, so an install that has not run the schema update reads them as absent rather than wrong. Nothing in the change is PHP-version dependent, so the 8.2–8.5 matrix is not at risk. No TCA, so no XLIFF pair.

## Suites that must prove it

| Requirement | Suite | Assertion |
|---|---|---|
| FR-001, FR-006 | functional | a call with reported usage persists both token counts and a derived cost |
| FR-005 | functional | a zero-priced model persists `NULL` cost, not `0.0` — fails today |
| FR-005 | functional | a provider reporting no usage persists `NULL` in all three columns |
| FR-002, FR-004 | functional | the row is joinable to itself by `correlation_id` and names the serving model |
| FR-007 | unit | the pipeline returns the same response and the same fallback ordering with the recording in place |
| contract | fuzzy | negative or malformed provider token counts stay clamped, as `createUsageStatistics()` already does |

## Obligations before merge

ADR under `Documentation/Adr/`. `api-surface.txt` regenerated with a `### Added` CHANGELOG entry. `Documentation/Administration/Analytics.rst` updated where it describes what telemetry records. `make gate`, not `composer ci`.
