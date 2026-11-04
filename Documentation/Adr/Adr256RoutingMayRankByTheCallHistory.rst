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

And a call through a criteria-mode configuration recorded no cost, decisions
aside (:php:`LlmServiceManager::priced()` prices those).
:php:`UsageMiddleware` priced a call by the configuration's own model, which a
criteria-mode configuration does not have — so exactly the calls routing
decides were the ones without a measured cost.

Decision
========

**An opt-in setting, off by default.** ``routing.callHistory`` adds two
signals to the measured modes. A measured mode is already an opt-in, but an
installation that chose one chose quality and health, not these. The new
signals follow the ADR-142 rule: a version bump does not switch them on. (The
health fix below does change decisions in a measured mode; see Consequences.)

**The population is what the model answered itself.** One ``GROUP BY`` over the
last seven days, keyed by the SERVING adapter type, model and operation, over
the rows with ``success = 1``, ``fallback_attempts = 0`` and ``cache_hit = 0``.
A rescued run measures the sibling that answered; a cache hit (ADR-249) measures
the cache. The aggregate is cached for five minutes in the health cache, so a
decision costs a cache read.

**Per operation.** On the demo the same model averaged $0.029 per tool turn and
$0.0067 per chat answer. The operation the call runs is handed to the decision
(:php:`RoutingDecisionService::decide()`); without one, no history is read.

**Five calls before a figure counts.** Below that, the figure is an absence. An
absence must neither promote nor demote (ADR-142), and for these two signals
that takes more than skipping it: see the next paragraph.

**Relative to the other measured candidates, centred on the neutral value.**
A candidate with figure *x* scores *g / (g + x)*, where *g* is the geometric
mean of the measured candidates' figures. A candidate at the mean scores 0.5,
and a candidate of the same decision without a figure is scored as 0.5 too —
the trace still shows it as ``no data``. Skipping the absence, the rule for the
absolute signals, does not work for a relative one: the unmeasured model would
keep its health-only score while every measured one is pulled toward 0.5, and a
model nobody had called would outrank the one that has been serving (found in
the second review round, with health at 0.95). So a model is promoted for being
faster or cheaper than the others, never for merely having history, and never
demoted for it either: a sole measured candidate scores 0.5, the same as an
unmeasured one. The ratio *x / g* is clamped to a factor of two either way, so
the signal runs from 1/3 (twice as slow as the mean or more) to 2/3 (twice as
fast or more); unbounded, a model thirteen times slower than its rival scored
0.21 against 0.79, and that outweighed a provider failing three calls in ten
(third review round). A measured cost of zero scores 1.0. A fixed ceiling cannot fit a population that
runs from a few hundred milliseconds to tens of seconds: a ceiling that fits
tool turns flattens chat answers. A plain "best over this" ratio was tried
first and rejected in review: it scores a sole measured candidate 1.0, which in
``economy`` lifted a priced cloud model above an unpriced local one nobody had
called yet, and its spread (1.0 against 0.47 for the demo's two models)
outweighed a provider failing one call in five.

**One cost basis per decision.** ``callCost`` replaces the list-price ``cost``
signal, with the same weight, when every candidate that has any cost figure has
a measured one; otherwise the whole decision ranks by list price as before. The
two are different units, and mixing them in one comparison would compare
nothing. An unpriced model with no priced calls blocks neither basis. While
the history is read, it counts as 0.5 on cost on either basis, so it is scored
over the same signals as its rivals and the same way whichever basis applies;
skipping its cost let its own latency take a larger share of its score than of
theirs, so being the fastest could demote it (third review round).

**Latency weighs a quarter of health.** 0.1 in ``balanced`` (health 0.4), 0.05
in ``quality`` and ``economy`` (health 0.3). With the signal bounded to 1/3–2/3,
a faster model on a provider that fails more than roughly a quarter of its first
attempts loses to a reliable one however much faster it is; below that, speed
can decide. Two tests pin it: the demo's latencies against a provider failing
one attempt in five, and a factor of thirteen against three in ten. Judgement,
like the other weights.

**A routed call is priced by the model it was routed to.** The planner hands
the resolved model record to :php:`TelemetrySignals`, and
:php:`UsageMiddleware` and, for a stream, :php:`StreamingDispatcher` price the
call — and attribute it in the usage aggregate — by that record where the
configuration names no model.

**The readout offers every routed operation.** Completion, embedding and stream
constrain no capability but select their own history, so the "Why this model?"
selector offers them too.

**Telemetry records whether each signal moved the decision**, in
``routing_signal_latency`` and ``routing_signal_call_cost``, by the ADR-156
rule, sharpened for a relative signal: it moved the decision only where two
eligible candidates differ on it, since every candidate at the mean and every
unmeasured one stands at the same 0.5. The Governance tab lists them under "Signals used", and the
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
after the upgrade, with ``routing.callHistory`` off; it is the signal the
operator opted into, working.

✓ Calls through a criteria-mode configuration record their cost, on the
telemetry row and in the usage aggregate, which now also names their model.
A stream records its estimated cost in the aggregate only, as a fixed-mode
stream does.

◐ That pricing does not depend on ``routing.callHistory``. A cost budget on a
criteria-mode configuration, or a user budget its calls count against, starts
counting those calls with the upgrade, and can now refuse a call it let through
before. That is the budget working as configured; it is still a change on
upgrade.

◐ The tool loop, the embedding cache key and the stream capability check
resolve a call's model twice. The
history cache (five minutes) can expire between the two, and the two can then
pick different models. Provider health (one minute) already could.

◐ Two provider records with the same adapter type share one health score and,
for the same model id, one call history — the key telemetry records. Provider
health already worked that way in the fallback reorder.

◐ A streamed call's latency is the whole stream, which depends on the answer's
length and on how fast the client reads it. Streams are compared only with
streams.

◐ Means, not percentiles. One very slow call moves a model's mean; five calls
bound how far.

◐ A model's history reflects the requests it was given. A model used for long
answers looks slower and dearer than one used for short ones. Within one
operation the mix is closer, not equal.

◐ A new model has no history and counts as average (0.5) until it has five
calls. It is not held back by the absence, but it reaches five calls only where
it is ranked first or used by a fixed-mode configuration.

✕ Streamed calls have no cost on the telemetry row (ADR-174), so a
configuration that only streams never gets a measured cost basis.

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
