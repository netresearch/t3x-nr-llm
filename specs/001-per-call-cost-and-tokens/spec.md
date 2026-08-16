# Per-call cost and real token counts

**Feature directory**: `specs/001-per-call-cost-and-tokens` | **Created**: 2026-08-16 | **Status**: Draft | **Source**: [#770](https://github.com/netresearch/t3x-nr-llm/issues/770) | **Related**: ADR-156

## Overview

Every provider call is recorded twice today, and neither record can answer what the call cost. `tx_nrllm_telemetry` holds routing, success, latency and a complexity estimate per call; its `routing_signal_cost` is a ranking signal rather than money and its `complexity_tokens` is explicitly an estimate. Actual spend lives in `tx_nrllm_service_usage` as a daily aggregate carrying no `correlation_id`. There is therefore no key that joins a cost to the call, the request shape or the complexity bucket that produced it.

The consequence is not that per-bucket cost is laborious to compute. It is that it cannot be computed at all — which makes ADR-156's third activation criterion ("real cost drops") unmeasurable, and every routing readiness statement that mentions cost unfounded.

This specification says what must be recorded and how a missing measurement must be distinguishable from a measured zero. It does not choose a schema, a class or a migration; that is the plan's job.

## Clarification of the second defect

Issue #770 states that models priced at zero "report a cost of 0 by construction", locating the defect at `UsageMiddleware.php:185-198`. Reading those lines shows the mechanism sits one layer further down, and the distinction matters because it decides which code the requirements bind to.

At the middleware, cost is taken from `$usage->estimatedCost` and, only if that is `null`, derived from the model's pricing. Two facts combine there: no adapter ever sets `estimatedCost` — `AbstractProvider::createUsageStatistics()` constructs `UsageStatistics` from prompt and completion tokens alone — and `Model::hasPricing()` is `costInput > 0 || costOutput > 0`, which is **false** for a zero-priced model. So the fallback is not taken either, `$cost` stays `null`, and the middleware correctly omits the `cost` key from its metrics array rather than writing a zero.

The zero is introduced at persistence: `UsageTrackerService` reads `$metrics['cost'] ?? 0.0` in two places (lines 131 and 154). That coalescing is what turns "nobody measured this" into "this cost nothing", and it is the behaviour FR-005 below forbids.

The conclusion in #770 stands. Its stated location does not, and a plan written against the stated location would change code that is already behaving correctly.

## Functional Requirements

- **FR-001**: Each provider call MUST record the input token count the provider reported, and the output token count the provider reported, as two separate values.
- **FR-002**: Each provider call MUST record the model that actually served it, which is not necessarily the model that was requested.
- **FR-003**: Each provider call MUST record the number of retries that preceded the recorded outcome.
- **FR-004**: Each provider call MUST record a `correlation_id` equal to the one the call already carries, such that a cost record and its telemetry record can be joined on it.
- **FR-005**: Where a provider reported no usage, the recorded value MUST be null and MUST NOT be zero. A measured zero and an absent measurement MUST remain distinguishable in the stored data and in anything derived from it.
- **FR-006**: Cost MUST be derived from the recorded token counts and the pricing of the model that actually served the call. Where either input is absent, cost is null under FR-005 rather than derived from a substitute.
- **FR-007**: The recording MUST NOT alter routing behaviour, the fallback chain or the response returned to the caller.

## User Scenarios

### Scenario 1 — a cost statement per complexity bucket (Priority: P1)

Someone assessing ADR-156 asks for the median cost per complexity bucket over the last thirty days.

- **Given** calls recorded across several complexity buckets, **When** cost records are joined to telemetry on `correlation_id`, **Then** each call contributes its own cost to exactly one bucket.
- **Given** a bucket in which no provider reported usage, **When** the median is computed, **Then** that bucket reports "not measured" and not a cost of zero.

**Independent test**: a functional test that writes calls across two buckets and asserts the join returns one cost row per call.

### Scenario 2 — a zero-priced model is not free (Priority: P1)

An experiment routes part of the traffic to a locally hosted, zero-priced model.

- **Given** a model whose input and output prices are both zero, **When** a call to it succeeds and the provider reports token counts, **Then** the token counts are recorded and the cost is recorded as null, not as `0.0`.
- **Given** the same call, **When** a cost report is produced, **Then** the call appears as unmeasured cost rather than contributing a zero that lowers an average.

**Independent test**: a functional test asserting the persisted cost column is `NULL` for a zero-priced model, which fails today because of the `?? 0.0` coalescing.

### Scenario 3 — a provider that reports no usage (Priority: P2)

A provider returns a valid response with no usage block.

- **Given** such a response, **When** the call is recorded, **Then** both token counts and the cost are null and the call is still recorded with its correlation id, model and retry count.

## Success Criteria

- **SC-001**: For any recorded call, a cost record and a telemetry record can be joined on `correlation_id`, with no call contributing more than one cost row.
- **SC-002**: A query for median cost per complexity bucket returns a result over recorded data, where today it cannot be expressed.
- **SC-003**: For every call whose provider reported no usage, the stored token and cost values are null; the count of rows storing `0.0` for an unmeasured cost is zero.
- **SC-004**: ADR-156's third activation criterion is computable from stored data without any estimate standing in for a measurement.

## Out of scope

- Changing how cost influences routing. `routing_signal_cost` stays a ranking signal; this feature does not make it money.
- Backfilling historical rows. Records written before this feature have no correlation id and stay unjoinable; they are not reconstructed or guessed.
- Any per-call quality signal. That is issue #772 and is specified separately.
- Changing the daily aggregate in `tx_nrllm_service_usage`, beyond what a correlated per-call record requires.

## Assumptions

- The correlation id the call already carries is stable across retries within one call, so FR-003 and FR-004 do not conflict. If it is not, the plan must resolve which of the two the id identifies.
- Providers that report usage report it in the same response as the content, so no second request is needed to obtain it.
- The distinction demanded by FR-005 must survive the storage layer, which today is where it is lost. Enforcing it only in the domain model would leave the defect in place.

## Constitution check

| Principle | Bearing on this specification |
|---|---|
| III — verifiable by test | Each scenario names the suite that proves it; SC-003 is an assertion over stored rows rather than an inspection. |
| IV — backward compatibility | FR-007 forbids a behavioural change. Whether the recording touches the `@api` surface is a plan question and must be answered before implementation, not at the point `api-surface.txt` fails. |
| V — security boundaries | Token counts and model identifiers are not credentials; no boundary moves. Cost records must not be written where provider input or output text would travel with them. |
| VI — measurement distinguishable from absence | FR-005 is this principle, and the clarification above names the two lines that violate it today. |
