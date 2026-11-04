.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-256:

================================================
ADR-256: Routing may rank by the call history
================================================

:Status: Accepted
:Date: 2026-11-04
:Authors: Netresearch DTT GmbH

Context
=======

:php:`CandidateRanker` (:ref:`ADR-142 <adr-142>`) weighs three signals:
measured quality, provider health and the list price. Two facts an operator
asks about a model are in none of them.

**How long a call to this model takes.** Provider health
(:ref:`ADR-063 <adr-063>`) folds latency into its score at 20 %, but per
PROVIDER and over fifteen minutes. Two models behind one provider are
indistinguishable to it. On the demo installation, over 30 days, the two chat
models of its single OpenAI provider averaged 4,259 ms and 9,094 ms.

**What a call to this model costs.** The list price is per million tokens. What
a call costs also depends on how many tokens the model spends on an answer, and
that differs between models of similar price. :ref:`ADR-174 <adr-174>` records
the real cost on every telemetry row; on the demo the same two models cost
$0.0067 and $0.0184 per chat call.

Both figures are on ``tx_nrllm_telemetry`` already. Nothing routes on them:
ADR-174 says so explicitly, and so does ADR-156 for the complexity columns.

The ranker also looked provider health up by the provider record's identifier,
while telemetry names a provider by its adapter type. Wherever the two differ,
the health signal was always absent.

Decision
========

**An opt-in setting, off by default.** ``routing.callHistory`` adds two
signals to the measured modes. A measured mode is already an opt-in, but an
installation that chose one chose quality and health, not these. The ADR-142
rule holds: a version bump does not change which model serves a call.

**The population is what the model answered itself.** One ``GROUP BY`` over the
last seven days, keyed by the SERVING adapter type, model and operation, over
the rows with ``success = 1``, ``fallback_attempts = 0`` and ``cache_hit = 0``.
A rescued run measures the sibling that answered; a cache hit (ADR-249) measures
the cache. The aggregate is cached for five minutes in the health cache, so a
decision costs a cache read.

**Per operation.** On the demo the same model averaged $0.029 per tool turn and
$0.0067 per chat answer. The operation the call runs is handed to the decision
(:php:`RoutingDecisionService::decide()`); without one, no history is read.

**Five calls before a figure counts.** Below that, the figure is an absence, and
an absence is skipped as every other signal's is (ADR-142): it neither promotes
nor demotes.

**Relative, not against a ceiling.** ``latency`` is the fastest candidate's mean
over this one's, so the fastest scores 1.0; ``callCost`` likewise for the
cheapest. A fixed ceiling cannot fit a population that runs from a few hundred
milliseconds to tens of seconds: a ceiling that fits tool turns flattens chat
answers.

**One cost basis per decision.** ``callCost`` replaces the list-price ``cost``
signal, with the same weight, when every candidate that has any cost figure has
a measured one; otherwise the whole decision ranks by list price as before. The
two are different units, and mixing them in one comparison would compare
nothing. An unpriced model with no priced calls blocks neither basis.

**Latency weighs less than health.** 0.2 in ``balanced``, 0.1 in ``quality`` and
``economy``: a model that answers slowly is still preferable to one that fails.
Judgement, like the other weights.

**Telemetry records whether each signal moved the decision**, in
``routing_signal_latency`` and ``routing_signal_call_cost``, by the ADR-156
rule. The Governance tab lists them under "Signals used", and the
"Why this model?" readout shows their per-candidate values when an operation is
selected.

**Health is looked up by adapter type.** That is the name the health window
knows.

This does not touch the complexity question of ADR-156. Its criteria are about
routing a request by its shape to a cheaper model; these signals describe the
model, not the request, and its criteria stay as written. ADR-174's "nothing
routes on any of it" no longer holds for ``latency_ms`` and ``actual_cost``,
and only where an operator switches this on.

Consequences
============

✓ Two models behind one provider can be told apart by speed.

✓ Cost is ranked by what calls cost, where that is known for every priced
candidate.

✓ Health now reaches the ranking for a provider whose identifier differs from
its adapter type. On an installation in a measured mode this changes decisions
after the upgrade; it is the signal the operator opted into, working.

◐ Means, not percentiles. One very slow call moves a model's mean; five calls
bound how far.

◐ A model's history reflects the requests it was given. A model used for long
answers looks slower and dearer than one used for short ones. Within one
operation the mix is closer, not equal.

◐ A new model has no history and is ranked without these signals until it has
five calls — which it gets only where something else ranks it first.

✕ Streamed calls have no cost (ADR-174), so a configuration that only streams
never gets a measured cost basis.

Alternatives considered
=======================

**Turning it on with the measured modes.** Rejected: it would change which model
serves a call on upgrade for every installation that chose ``balanced``.

**A new "fast" policy mode.** Rejected: latency matters in every mode, as health
does, and a fifth mode would still need a weight for it in the other three.

**Cost per token instead of per call.** Rejected: measured cost per token is the
list price again, wherever the cost was derived from it.

**Percentiles.** Not portable across the supported databases without reading
every row.
