.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-291:

=====================================================================
ADR-291: Model calls are traced through the OpenTelemetry API
=====================================================================

:Status: Accepted
:Date: 2026-11-12
:Amends: :ref:`ADR-063 <adr-063>` (the extension now emits OpenTelemetry
   signals; it still owns no exporter)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-063 <adr-063>` decided not to build OpenTelemetry into the
extension. Its reasons were that an exporter, a collector endpoint and a
sampling configuration are the host installation's business, and that an
in-extension pipeline would couple the extension to an exporter SDK. It
pointed a host at :sql:`tx_nrllm_telemetry`, which a host "can scrape or
forward".

That table cannot do what a trace does. One row per pipeline run carries no
span context, so a slow answer cannot be placed inside the request or the
queue job that asked for it. Fallback attempts, tool executions and the
rounds of an agent run are not rows at all. Installations that already run
an OpenTelemetry integration for TYPO3 see the HTTP request, the database
and the HTTP client, and see a hole where the model call is.

The reasons of ADR-063 still hold for what they name. What they did not
consider is instrumentation through the vendor-neutral API: the OpenTelemetry
specification asks libraries to depend on the API package only, to get their
tracer and meter from the global providers and to leave the SDK, the
exporters and the sampling to the application. That is a different thing from
the pipeline ADR-063 rejected.

Decision
========

**The extension emits spans and metrics through the OpenTelemetry API and
nothing else.** It requires :composer:`open-telemetry/api`, obtains its tracer
and meter from :php:`OpenTelemetry\API\Globals` through the API's
:php:`CachedInstrumentation` under the scope name ``netresearch/nr-llm`` with
the installed package version, and joins whatever context the host has
current. It configures no SDK, no exporter, no processor and no sampler, and
it reads no ``OTEL_*`` variable. Where the host registered no SDK the globals
are the API's no-op providers, and the extension skips its instrumentation
after one check per call.

**Dependency: ``require``, not ``suggest``.** ``open-telemetry/api`` 1.11.0,
the current release, requires PHP ``^8.2``, which is this extension's floor
(``composer.json``). The package is the API only, it adds no exporter and no
network code, and with no SDK it has no behaviour. A ``suggest`` with
``class_exists()`` guards would put a branch in every instrumented method for
a package that costs nothing to install. The SDK is ``require-dev``, for the
in-memory exporters the tests use. The constraint is ``^1.7``, the lowest
version the tests were run against (1.7.0 with SDK 1.9.0, and the current
1.11.0); earlier 1.x releases were not tried.

**Seams: the narrowest set that covers every provider.** Every generation
call of a provider adapter passes the middleware pipeline
(:ref:`ADR-026 <adr-026>`), so one middleware covers all adapters and all
operations; the streaming path is the one exception. The rest follows the call
tree.

.. list-table::
   :header-rows: 1
   :widths: 24 30 46

   * - Seam
     - Signal
     - Why there
   * - :php:`GenAiTracingMiddleware`, priority 30, between
       :php:`FallbackMiddleware` (50) and :php:`UsageMiddleware` (25)
     - ``chat {model}`` CLIENT span per attempt; duration and token metrics
     - Every configuration the fallback walk dispatches is its own attempt,
       with its own provider and model. A cache or idempotency hit never
       reaches this layer, and no model was called.
   * - :php:`FallbackMiddleware`
     - ``nr_llm.fallback_chain`` INTERNAL span, one event per failed attempt
     - The attempts become children of one parent. Only a configuration
       with a chain gets this span.
   * - :php:`StreamingDispatcher`
     - ``chat {model}`` CLIENT span per stream, time to first chunk
     - Streams do not pass the pipeline (:ref:`ADR-026 <adr-026>`).
   * - :php:`ToolLoopService`, around one segment of a run
     - ``invoke_agent {configuration}`` INTERNAL span
     - A fresh run and a queued run enter through :php:`runLoop()`, every
       resume path through one private method, and both wrap the same loop,
       so a run, an approval and an answer are one kind of span.
   * - :php:`ToolLoopService`, around the tool's own ``execute()``
     - ``execute_tool {tool}`` INTERNAL span
     - The one place every tool execution passes through.

**What is recorded.** The names follow the GenAI semantic conventions of
semantic-conventions v1.41.0, the last release that defines them. They are
listed in :php:`GenAiSemconv` and in the administration page
:ref:`administration-opentelemetry`, and nowhere else.

- Model call: ``gen_ai.operation.name``, ``gen_ai.provider.name``,
  ``gen_ai.request.model``, ``gen_ai.response.model``,
  ``gen_ai.response.finish_reasons``, ``gen_ai.usage.input_tokens`` and
  ``gen_ai.usage.output_tokens`` when the provider reported them,
  ``gen_ai.request.stream`` and ``gen_ai.response.time_to_first_chunk`` for
  a stream, and ``error.type``.
- Metrics: ``gen_ai.client.operation.duration`` (s),
  ``gen_ai.client.token.usage`` (``{token}``, by ``gen_ai.token.type``) and
  ``gen_ai.client.operation.time_to_first_chunk`` (s), with the bucket
  boundaries the convention advises.
- Tool: ``gen_ai.operation.name`` ``execute_tool``, ``gen_ai.tool.name``,
  ``gen_ai.tool.type`` (``extension`` for a remote MCP tool, else
  ``function``) and ``gen_ai.tool.call.id``.
- Agent: ``gen_ai.operation.name`` ``invoke_agent``, ``gen_ai.agent.name``
  (the configuration identifier), the token totals, and ``nr_llm.run.*``.
- Of the extension: ``nr_llm.correlation_id`` (the correlation id of
  :sql:`tx_nrllm_telemetry`, which for an agent run is the run uuid, so a
  trace and a telemetry row join), ``nr_llm.configuration``,
  ``nr_llm.fallback.attempt`` and ``nr_llm.source_extension``.

``gen_ai.provider.name`` is the well-known value where the convention has
one (``anthropic``, ``gcp.gemini``, ``mistral_ai``, ``azure.ai.openai``,
``openai``, ``groq``, ``ollama``, ``perplexity``); any other adapter type is
passed through, as the convention allows. The specialised services have no
operation name in the convention and use a custom one (``image_generation``,
``transcription``, ``speech_synthesis``, ``translation``). A provider status
lookup is no AI call and gets no span.

**What is never recorded.** No prompt, no completion, no system prompt, no
tool arguments, no tool result, no exception message, no URL, no user name or
id. An error is its status and ``error.type``, the class name of the exception,
the same value :sql:`tx_nrllm_telemetry` keeps as ``error_class``;
:php:`recordException()` is never called, because it exports the message. A
tool that returns a failed result is ``tool_failed``. The conventions' opt-in
content attributes are not emitted and there is no switch for them. A switch
would have to run every value through the redaction of ADR-088 and the
privacy level of :ref:`ADR-064 <adr-064>` on every call, for a feature that
nobody asked for; it can be decided when somebody does.

**Control flow is no error.** A run that pauses for an approval, an answer or
a dependency, is cancelled, loses its lease or ends on a guardrail verdict
sets ``nr_llm.run.outcome`` and leaves the span status unset. A dependency
tool announcing a pending operation is not a failed tool. A stream its
consumer drops sets ``nr_llm.stream.abandoned``; it is no error either.

**A stream is a span that is started but not made current.** A generator
hands control to its consumer between two chunks, and a scope held across a
``yield`` would leave the stream's span current inside code that is not part
of the stream. The span's parent is the context of the code that asked for the
stream, captured when :php:`StreamingDispatcher::stream()` is called, and the
span ends in the ``finally`` of the drain. A stream carries no token counts:
what the dispatcher counts there is an estimate from lengths, and an estimate
must not be reported as a measurement.

**Queue workers: no link yet.** A queued run executes in a worker process, so
its spans are not children of the request that enqueued it. Linking them
needs the W3C trace context of the enqueuing request to be stored with the
run, which is a column of :sql:`tx_nrllm_agentrun` or a field of the stored
request, and a change of the queue coordinator. A link to a context that was
not stored would be invented. The worker's ``invoke_agent`` span is therefore
a root of the worker's own trace (or a child of whatever the worker's
integration made current), and ``nr_llm.correlation_id``, the run uuid, is the
join key to the enqueuing request's telemetry row. Storing the context and
adding the link is the follow-up this decision leaves open.

**Setting.** ``telemetry.openTelemetry`` in :file:`ext_conf_template.txt`,
beside ``telemetry.enabled``, on by default and independent of it. With an SDK
registered and the setting off, nothing is emitted. With the setting on and no
SDK there is nothing to emit to, and nothing is.

Status of the convention
------------------------

The GenAI conventions are in development, and their home moved to
`open-telemetry/semantic-conventions-genai
<https://github.com/open-telemetry/semantic-conventions-genai>`__, which has
no release yet. Its main branch (commit ``e6658f14c9d9``, read on 2026-11-12)
renames the client metrics to ``gen_ai.client.inference.*``, replaces the
token histogram by one counter per token class and modality, says that
``gen_ai.client.operation.duration`` should no longer be reported for
inference, and has the span type ``gen_ai.client.inference``. Those are
unreleased and may change again. This record follows v1.41.0. The span
attributes are unchanged between the two; the metric names are what will
move, and they are constants of one class.

Consequences
============

- ● A model call, a fallback, a tool execution and an agent run appear in the
  host's trace of the request or the job that caused them, with no
  configuration in the extension.
- ● A host without OpenTelemetry pays one provider check per call.
- ● No prompt or answer text leaves the process through this path.
- ◐ A new package is required, though it contains no code that runs without
  an SDK.
- ◑ The metric names follow a released convention that its successor will
  rename; the rename is one class and one documentation page.
- ◑ A queued run is not linked to its request.
- ◑ The span of a stream is not current while the stream is consumed, so an
  HTTP client span of the provider call made while pulling a chunk is not its
  child.
- ✕ The ``execute_tool`` and ``invoke_agent`` spans add a small allocation to
  every tool execution on a host with an SDK.

Alternatives considered
=======================

- **One span per provider adapter.** Rejected: seven adapters and every new
  one would each need the same code, and a provider added by a third party
  would have none. The pipeline is the one place they share.
- **``suggest`` with ``class_exists()`` guards.** Rejected: see the
  dependency above.
- **A hook-based auto-instrumentation package** on the native
  ``ext-opentelemetry``. Rejected for this extension: it needs a native
  extension on every host and ties the instrumentation to method signatures
  of classes that change from release to release. It stays possible beside
  this decision, because both would use the same API.
- **Recording content behind a setting.** Rejected for now, see above.

Related
=======

- :ref:`ADR-063 <adr-063>` (resilience; its OpenTelemetry section is amended
  here).
- :ref:`ADR-058 <adr-058>` (the telemetry log whose correlation id the spans
  carry).
- :ref:`ADR-026 <adr-026>` (the middleware pipeline and its streaming
  exception).
- :ref:`administration-opentelemetry` (enabling and reading the signals).
