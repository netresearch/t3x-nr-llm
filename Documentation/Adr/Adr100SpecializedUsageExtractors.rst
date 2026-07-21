.. include:: /Includes.rst.txt

.. _adr-100:

============================================================================
ADR-100: Specialized usage recorded by tagged extractors in the pipeline
============================================================================

:Status: Accepted
:Date: 2026-07-21
:Authors: Netresearch DTT GmbH

.. _adr-100-context:

Context
=======

:ref:`ADR-097 <adr-097>` routed the specialized dispatches through the pipeline,
but each service still recorded its own usage by calling
``UsageTrackerServiceInterface::trackUsage()`` directly after the dispatch — a
second write path alongside :ref:`UsageMiddleware <adr-058>`, which records the
token-shaped chat / embedding / vision responses. The specialized responses are
not token-shaped (they measure images, characters, audio seconds), so
``UsageMiddleware`` skipped them and the service filled the gap itself. Two
recorders, two places to keep the request-count and attribution rules
consistent.

.. _adr-100-decision:

Decision
========

A specialized service no longer writes usage. Before dispatch it attaches a
``SpecializedUsageIntent`` — the stable, dispatch-independent inputs it knows
(model, resolved model / configuration uid, attribution uid, and the input-
derived counters: characters, size, quality, batch size) — to the call-context
metadata. A tagged ``UsageMetricsExtractorInterface`` (one per service, matched on
operation **and** provider so DALL·E and FAL do not collide) reads that intent
together with the raw response and returns a ``ProviderUsageRecord``.
``UsageMiddleware`` writes it, as the single recorder, after the token path finds
nothing.

The response supplies what the service could not know up front: DALL·E's
gpt-image token object and the number of images returned, Whisper's audio
duration (``verbose_json`` only). Cost is computed in the extractor from the
``SpecializedCostCalculator`` exactly as the service did.

A service records usage **iff** it set an intent. DeepL's language-detection
sub-call and the ``getUsage()`` / ``getGlossaries()`` metadata calls
(:ref:`ADR-099 <adr-099>`) set none, so the extractor returns null and nothing is
recorded — the former double-count guard is now structural.

.. _adr-100-consequences:

Consequences
============

- One write path for every AI call: ``UsageMiddleware`` records both the token-
  shaped responses and the specialized operations. The services drop their direct
  ``trackUsage()`` calls (and ``DallEImageService::trackImageUsage()`` /
  ``WhisperTranscriptionService::trackTranscriptionUsage()`` are gone).
- ``UsageMiddleware`` gained an autowired iterator of extractors; with none
  tagged its behaviour is unchanged. ``ProviderOperation::Metadata`` records
  nothing (no extractor claims it).
- The recorded rows are unchanged — same service type, provider, metrics, cost,
  model / configuration uid and attribution — verified end-to-end: the service
  tests now drive a real ``UsageMiddleware`` + the service's extractor and assert
  the same rows they asserted before.
- Adding a specialized provider means adding one extractor tagged
  ``nr_llm.usage_metrics_extractor`` and setting an intent before dispatch; no
  service touches the usage table.
