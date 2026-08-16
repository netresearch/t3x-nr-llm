# Tasks — per-call cost and real token counts

**Spec**: `spec.md` | **Plan**: `plan.md` | **Created**: 2026-08-16

Dependency-ordered. `[P]` marks tasks that can run in parallel with the one above them; everything else depends on what precedes it.

## Phase 0 — Blocking decision

The plan leaves one question open, and it decides whether Phase 2 has one task or three. Nothing below Phase 1 may start until it is answered.

- [ ] T001 Decide whether FR-003 "retry count" means fallback hops or provider-level retries. Read `Classes/Provider/Middleware/FallbackMiddleware.php` for what the retry path actually distinguishes, and record the answer in `specs/001-per-call-cost-and-tokens/plan.md` under the open question. If it means hops, `fallback_attempts` already satisfies FR-003 and T012 is dropped.

## Phase 1 — Failing tests first

Written before the schema so each one fails for the reason it names, not because a column is missing. Each asserts against persisted rows, per constitution principle III.

- [ ] T002 Add a functional test asserting a zero-priced model persists `NULL` cost for the call in `Tests/Functional/Provider/Middleware/TelemetryUsageRecordingTest.php`. Must fail today with `0.0`, which is the defect. Follow `Tests/Functional/Provider/Middleware/ServedConfigurationTelemetryTest.php` — it asserts the `served_*` telemetry columns through the same pipeline and is the closest existing shape.
- [ ] T003 [P] Add a functional test asserting a provider that reports no usage persists `NULL` in all three new columns, same file.
- [ ] T004 [P] Add a functional test asserting a call with reported usage persists both token counts and a cost derived from the serving model's pricing, same file.
- [ ] T005 [P] Add a functional test asserting the persisted row is joinable by `correlation_id` and names the serving model, same file.
- [ ] T006 Add a unit test asserting the pipeline returns an unchanged response and unchanged fallback ordering with recording active, in `Tests/Unit/Provider/Middleware/UsageMiddlewareTest.php` — this is FR-007 and it is the one that catches a regression rather than an absence.

## Phase 2 — Foundational

- [ ] T007 Add `usage_prompt_tokens`, `usage_completion_tokens` and `usage_cost` to `tx_nrllm_telemetry` in `ext_tables.sql`, all `DEFAULT NULL`, each with a comment stating that null means not measured.
- [ ] T008 Extend `Classes/Service/Telemetry/TelemetryRecord.php` with the three nullable values. Its constructor is not in the frozen surface, so no snapshot change follows from this task alone.
- [ ] T009 Extend `Classes/Service/Telemetry/TelemetryRepository.php` to write the three columns, keeping null distinct from zero all the way to the parameter binding — a `(int)` cast anywhere on this path reintroduces the defect T002 exists to catch.
- [ ] T010 Add a recorder method and its properties to `Classes/Provider/Middleware/TelemetrySignals.php`, following the shape of `recordServedBy()` and its null-until-set properties.
- [ ] T011 Record the measured values from `Classes/Provider/Middleware/UsageMiddleware.php` onto the signals, taking them from the same `$usage` object the aggregate already uses and passing null through where it is null rather than coalescing.
- [ ] T012 Only if T001 decided "provider-level retries": expose that count from `Classes/Provider/Middleware/FallbackMiddleware.php` and carry it the same way. Dropped otherwise.
- [ ] T013 Read the signals in `Classes/Provider/Middleware/TelemetryMiddleware.php` into the record it already writes, leaving its fail-soft behaviour intact — a telemetry write error stays logged and swallowed.

## Phase 3 — Make Phase 1 pass

- [ ] T014 Run the functional suite scoped to the new test class and make T002–T005 pass. Scope the run: the full functional suite takes ~35 minutes because of the provider-connection smoke tests.
- [ ] T015 Run the unit suite and make T006 pass.
- [ ] T016 Add fuzzy assertions for malformed provider token counts in `Tests/Fuzzy/`, matching the clamp `AbstractProvider::createUsageStatistics()` already applies. Contract changes have assertion twins there and the gate runs that suite.

## Phase 4 — Obligations

Each of these is a merge gate in its own right, not cleanup.

- [ ] T017 Write the ADR in `Documentation/Adr/Adr<N>PerCallUsageRecording.rst`, recording decision 1 (telemetry rather than the aggregate), decision 2 (the existing scratchpad) and the accepted consequence that the daily aggregate keeps writing `0.0` for zero-priced models. Add it to `Documentation/Adr/Index.rst`.
- [ ] T018 Regenerate `Tests/Unit/Api/api-surface.txt`. The diff must be additive; if it is not, stop and decide rather than accepting the snapshot.
- [ ] T019 Add a `### Added` entry under `## [Unreleased]` in `CHANGELOG.md`. Append under the existing heading — do not create a second one.
- [ ] T020 Update `Documentation/Administration/Analytics.rst` where it describes what telemetry records, stating that null means not measured.

## Phase 5 — Gate

- [ ] T021 Run `make gate`, not `composer ci`. If Rector cannot run because this worktree's `.Build` was resolved under a newer PHP than the pinned 8.2, say so in the PR rather than reporting the gate green.
- [ ] T022 Confirm SC-003 against real rows: count rows storing `0.0` for an unmeasured cost, which must be zero. A passing test is not the same as the population being clean.

## Explicitly not tasks

Backfilling historical rows, changing `routing_signal_cost`, adding a per-call quality signal (issue #772), and altering the daily aggregate's `NOT NULL` cost column. Each is out of scope per `spec.md`, and each would look reasonable to add while nearby.
