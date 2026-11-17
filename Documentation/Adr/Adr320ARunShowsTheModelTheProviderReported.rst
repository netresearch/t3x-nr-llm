.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-320:

=================================================
ADR-320: A run shows the model the provider named
=================================================

:Status: Accepted
:Date: 2026-11-17
:Authors: Netresearch DTT GmbH

Context
=======

Every provider call of a run carries the run's uuid as its correlation id
(:ref:`ADR-153 <adr-153>`), and the run timeline joins the telemetry rows of
that id. A row names the provider and model the call was sent to and, after a
fallback, the ones that served it.

Since :ref:`ADR-174 <adr-174>` the same row also stores ``response_model``:
the model the provider named on its response. That need not be the model the
call asked for. An alias such as ``gpt-5`` resolves to a dated snapshot, and
which snapshot answered is the fact that separates a model change from a skill
or input change when two runs of the same configuration answer differently.
:php:`TelemetryRepository::findByCorrelation()` did not read the column, so
the timeline could not show it.

Decision
========

1. :php:`TelemetryRepository::findByCorrelation()` reads ``response_model``
   and :php:`TelemetryCall` carries it as ``responseModel``.
2. A provider-call row of the timeline shows it as ``reportedModel`` when it
   is not empty and differs from the model the call was sent to: the serving
   model after a fallback, the requested one otherwise. This is the rule the
   serving pair already follows; a repetition of the name already shown is
   left out.
3. Nothing is derived from it. The timeline shows what the provider said; it
   does not decide whether the answer came from an acceptable model.

Consequences
============

● An administrator can read on the run which snapshot of a model answered
each call, and tell two runs apart whose configuration and requested model are
equal.

◐ The value is metadata like the rest of the row: an identifier, never content
of the call. The timeline stays metadata only at every privacy level.

◑ The column is ``''`` wherever the provider named no model and wherever no
token-shaped response was produced (a cache hit, a failed call, the
specialized operations; see :ref:`ADR-174 <adr-174>`). The timeline then
shows nothing, which cannot be told apart from a provider that repeated the
requested name.

◑ The value is what the provider reports. A provider that names the alias it
was sent, not the snapshot behind it, gives the timeline nothing to show.
