<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# The extension reports its AI work as OpenTelemetry spans and metrics

Governing decision: ADR-291. An installation that runs OpenTelemetry sees its
requests, queries and HTTP calls and sees nothing of the model call, the
fallback attempts, the tool executions or the agent run, because the telemetry
log of ADR-058 has no span context. ADR-063 declined to build an OpenTelemetry
pipeline into the extension; this specification is about the part that
declination did not weigh, instrumentation through the vendor-neutral API.

## Requirements

- **R1 API only.** The extension depends on `open-telemetry/api` and obtains
  tracer and meter from `OpenTelemetry\API\Globals`. It configures no SDK,
  exporter, processor or sampler and reads no `OTEL_*` variable.
- **R2 Silent without a host.** With the API's no-op providers, and with the
  setting `telemetry.openTelemetry` off, no span and no metric is created and
  the observed call behaves exactly as before.
- **R3 One span per attempt at a model.** A CLIENT span named
  `{operation} {model}` for every attempt the middleware pipeline hands to a
  provider, with the attributes of the GenAI conventions that the extension
  knows. A fallback chain is an INTERNAL span around its attempts, and each
  failed attempt is an event on it.
- **R4 Streams.** A streamed answer is one CLIENT span with the time to the
  first chunk. It is not made current across a `yield`, it is parented to the
  code that asked for the stream, and it carries no token counts.
- **R5 Tools and runs.** One `execute_tool` span per tool execution and one
  `invoke_agent` span per segment of a run. A pause, a cancellation, a lost
  lease and a guardrail verdict are outcomes of the run, not errors.
- **R6 Metrics.** `gen_ai.client.operation.duration`,
  `gen_ai.client.token.usage` and `gen_ai.client.operation.time_to_first_chunk`.
- **R7 Privacy.** No prompt, completion, system prompt, tool argument or
  result, exception message, URL or user identity is recorded. An error is a
  status and the class name of the exception.
- **R8 Join key.** Every model-call and agent span carries the correlation id
  of the telemetry log row.

## Out of scope

- Recording content, behind a setting or otherwise.
- Linking a queued run to the request that queued it: the trace context is not
  stored with the run.
- Instrumenting provider status lookups and connection tests.
- Any exporter, collector or sampling configuration.

## Verification

| Requirement | Evidence |
| --- | --- |
| R1, R2 | `GenAiTelemetryTest` (no SDK, setting off with an SDK, missing and unreadable setting) |
| R3 | `GenAiTelemetryTest` (attributes, parent, attempt order, two failed attempts, no chain) |
| R4 | `StreamingDispatcherTracingTest` |
| R5 | `ToolLoopServiceTracingTest` |
| R6 | `GenAiTelemetryTest`, `StreamingDispatcherTracingTest` |
| R7 | the same tests assert that no content, message or URL occurs in any recorded value |
| R8 | `GenAiTelemetryTest`, `ToolLoopServiceTracingTest` |
