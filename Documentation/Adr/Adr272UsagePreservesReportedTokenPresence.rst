.. include:: /Includes.rst.txt

.. _adr-272:

================================================
ADR-272: Usage preserves reported token presence
================================================

:Status: Accepted
:Date: 2026-11-08
:Authors: Netresearch DTT GmbH

Context
=======

Adapters replaced missing token measurements with zero. Pricing those zeros
made unmeasured calls look free, including on per-call telemetry.

Decision
========

Preserve input and output presence independently through normalization and
serialization. Keep the existing integer counters for consumer compatibility.
Add optional presence arguments to UsageStatistics, with legacy inference
when omitted, and read presence through ``isPromptTokensReported()`` and
``isCompletionTokensReported()``; the arguments are not public properties,
so the legacy rule lives in one place. A normalized explicit zero is known; an absent or invalid field
is unknown. Local split-token pricing requires both measurements. An
independently reported monetary amount remains known without token counts.
A sum has known presence only when both operands have it.

This is an additive public constructor change. Existing positional and named
arguments keep their meaning. Legacy all-zero objects remain unmeasured;
callers requiring an explicit zero measurement supply presence or fromArray.

Consequences
============

Daily aggregates retain their existing integer storage and request counting.
Per-call telemetry preserves nullability; aggregated zero alone does not
prove free usage. UsagePresenceTest checks both telemetry and tracker inputs.
