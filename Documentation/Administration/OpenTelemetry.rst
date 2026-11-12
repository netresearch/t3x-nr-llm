.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-opentelemetry:

=====================================
Tracing with OpenTelemetry (optional)
=====================================

The extension reports its AI work as OpenTelemetry spans and metrics
(:ref:`ADR-291 <adr-291>`): every call to a model, every attempt of a fallback
chain, every streamed answer, every tool execution and every agent run. The
spans join the trace of whatever is current in the installation, such as the
backend request or the queue job that caused the call.

The extension does not send anything by itself. It uses the OpenTelemetry
**API** only and ships no SDK, exporter or collector configuration. Whether
spans leave the server, where they go and how many are kept is decided where
the installation sets up OpenTelemetry. Without that setup the extension
emits nothing and costs one check per call.

Enabling tracing
================

#. Install an OpenTelemetry SDK and an exporter at installation level, in
   the project's :file:`composer.json`, for example
   :composer:`open-telemetry/sdk` and :composer:`open-telemetry/exporter-otlp`
   with a PSR-18 HTTP client.
#. Let the SDK configure itself from the environment of the web server and of
   the CLI and queue workers. The SDK reads the standard variables, for example
   ``OTEL_PHP_AUTOLOAD_ENABLED=true``, ``OTEL_SERVICE_NAME``,
   ``OTEL_TRACES_EXPORTER``, ``OTEL_METRICS_EXPORTER`` and
   ``OTEL_EXPORTER_OTLP_ENDPOINT``. Their meaning is documented by the
   OpenTelemetry project.
#. Leave :guilabel:`Emit OpenTelemetry spans and metrics`
   (``telemetry.openTelemetry``) on in the extension configuration. It is on
   by default. Switching it off silences the extension while the rest of the
   installation keeps reporting.

Where the signals go is the installation's choice as well: an OpenTelemetry
collector, or any backend that accepts OTLP. Sentry's PHP SDK
(:composer:`sentry/sentry` 4.23.0 or later, with its ``OTLPIntegration``) and
Sentry's OTLP endpoint accept traces and logs but not metrics, so the spans of
this extension reach Sentry and its metrics do not. This has not been tested
with this extension.

A TYPO3 integration that instruments the request, the database and the HTTP
client is a way to get the surrounding trace. Two exist as Composer packages,
:composer:`a9f/opentelemetry-auto-typo3` and
:composer:`r3h6/opentelemetry-auto-typo3`; both are options of the
installation. This extension depends on neither and has not been tested
against either. Any setup that registers providers through the OpenTelemetry
API receives the spans.

What is recorded
================

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - Signal
      - Content
    * - ``chat {model}``, ``embeddings {model}``, and the like (client span)
      - One per attempt at a model: operation, provider, requested and
        answering model, finish reason, input and output tokens as the
        provider reported them, whether it streamed, the time to the first
        chunk, and the class name of the exception when it failed. Calls
        answered from the answer cache or the idempotency store make no span,
        because no model was called.
    * - ``nr_llm.fallback_chain``
      - Around the attempts of a configuration that has a fallback chain: the
        number of fallbacks tried, the configuration that answered, and one
        event for each attempt that failed, with the configuration and the
        class name of its exception.
    * - ``invoke_agent {configuration}``
      - One per segment of an agent run: the run as it starts and each time it
        continues after an approval, an answer or a finished dependency. Its
        attributes are the iterations, the token totals and the outcome
        (``nr_llm.run.outcome``). A pause, a cancellation or a guardrail
        verdict is an outcome, not an error.
    * - ``execute_tool {tool}``
      - One per tool execution: the tool name and type and the call id, and
        ``tool_failed`` or the exception class when it failed.
    * - ``gen_ai.client.operation.duration``, ``gen_ai.client.token.usage``,
        ``gen_ai.client.operation.time_to_first_chunk``
      - Histograms of the calls, with operation, provider and model as
        attributes.

Every call carries ``nr_llm.correlation_id``, the correlation id of the row in
the telemetry log (:ref:`ADR-058 <adr-058>`); for an agent run it is the run
uuid. It joins a trace to the log, the usage and the agent run in the backend.

What is not recorded
====================

Prompts, answers, system prompts, tool arguments and tool results are never
recorded, and there is no setting that turns that on. Exception messages are
not recorded, because they can contain parts of a request; the class name is.
URLs, user names and user ids are not recorded. Configuration identifiers,
tool names, model names and the name of the calling extension are, because
an administrator or a developer chose them.

A **streamed** answer carries no token counts. The extension only has an
estimate there, and an estimate is not reported as a measurement.

Limits
======

- A run that a queue worker picks up starts a trace of its own in the worker;
  it is not linked to the request that queued it. Use
  ``nr_llm.correlation_id`` to find the request's telemetry row.
- The span of a stream is not the current span while the stream is read, so
  HTTP client spans of the provider call are not its children.
- The GenAI semantic conventions are in development. The metric names follow
  semantic-conventions v1.41.0; a later convention renames some of them
  (:ref:`ADR-291 <adr-291>`).
