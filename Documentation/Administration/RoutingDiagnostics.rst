.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

===============================
Routing and request diagnostics
===============================

.. _administration-governance-routing:

Why this model?
===============

The same tab answers the other question an operator asks about a
configuration: which model would actually serve a call through it, and why
not one of the others. Pick a configuration, optionally the operation the
call runs, optionally a policy mode to try, and press
:guilabel:`Explain` (:ref:`ADR-148 <adr-148>`).

The answer comes from :php:`Service\\Routing\\RoutingDecisionService` — the
decision point the runtime itself uses, not a second implementation of the
ranking. It reports:

- the selected model, and the eligible candidates in the order they were
  ranked, each with its score and the per-signal values behind it;
- every refused candidate with the reason it was refused — a missing
  capability, an excluded adapter type, a context window below the minimum,
  a cost above the ceiling, or a declared capability set without the one the
  operation needs;
- the effective policy mode, and whether the operation-capability axis is
  enforcing or only observing.

Three things are worth knowing before reading it:

**A fixed-mode configuration is not a decision.** If the configuration names
its model, nothing is chosen at call time. The tab says so instead of
presenting the named model as the winner of a one-candidate ranking — there
are no criteria to debug in that case.

**A signal without data is not a zero.** ``no data`` means nothing was
measured for that model. It neither promotes nor demotes: the score is the
weighted mean over the signals that *do* have data
(:ref:`ADR-142 <adr-142>`). In :guilabel:`Provider priority` mode no signal
is collected at all, and the ordering falls through to provider priority and
the established tiebreaks.

**Trying a policy mode changes nothing.** The mode selector evaluates a
hypothetical for that one page view. ``routing.policyMode`` in the Install
Tool is not written and not affected — the same read-only rule the rest of
the tab follows.

**The call history needs an operation.** With ``routing.callHistory`` switched
on, the measured modes also rank by what each model's calls actually took over
the last seven days: ``latency`` and ``callCost``, the measured cost per call,
which replaces the list-price ``cost`` signal for the whole decision when every
priced candidate has one (:ref:`ADR-256 <adr-256>`). Both are relative to the
other measured candidates: a model at their average scores 0.5, the value an
unmeasured model counts as, so a model that is the only one measured is not
promoted for it. The values run from 0.33 to 0.67: twice as fast or slow as the
average, or more, counts the same. The one exception is a measured cost of zero,
which scores 1.0: nothing is cheaper than free. Both figures are kept per operation — a tool turn takes and
costs several times what a chat answer of the same model does — so the readout
shows them only when an operation is selected. A model with fewer than five
measured calls shows ``no data`` there. The figures come from the telemetry
table, so ``telemetry.enabled`` must be on.

The selector offers every operation a configuration's model is chosen for.
Chat, vision, tools and decision also constrain the candidates by capability;
completion, embedding and stream constrain nothing but read their own call
history. Leaving the selector on :guilabel:`No operation` is answered as
exactly that — neither the capability axis nor the call history was applied —
and not as an operation that requires nothing.

An empty result is reported in two distinguishable ways, because they need
opposite fixes: :guilabel:`No candidates at all` means the catalogue holds no
active model, while a populated :guilabel:`Refused, and why` table means the
criteria and the model records disagree.

.. _administration-governance-routed-calls:

Calls that were routed
======================

The readout above answers a hypothetical. :guilabel:`Calls that were routed`
answers the same question about calls that already ran: the last seven days
of runs whose model was chosen automatically, newest first, twenty at a time
(:ref:`ADR-156 <adr-156>`).

Each row names when the call ran and against which configuration, which model
answered it, and the decision behind that: the policy mode, how many
candidates were considered, which measured signals actually moved the ranking,
and the distinct reasons that refused the rest.

**Fixed-mode calls are absent, and that is the point.** Nothing was chosen for
them, so there is no decision to show. If the table is empty on a busy
installation, the likely reasons are that every configuration names a fixed
model, or that ``telemetry.enabled`` is off in the Install Tool.

**"Signals used" means a measured signal contributed to scoring.** It does
not prove that removing the signal would change the winner. The readout
requires a positive weight and an available measurement; it does not run a
counterfactual comparison. A ``quality`` decision over a
catalogue nobody has scored shows no signals used and ranks exactly as
:guilabel:`Provider priority` would — the weights only apply to signals that
have data. A signal the mode weighs at zero is not listed either: ``quality``
weighs cost at zero, so :guilabel:`Prefer Lowest Cost` on a ``quality``
configuration shows no cost signal, even though it still breaks ties between
models that scored equally.

``cost`` and ``callCost`` are the same signal on two bases, the list price and
the measured cost per call, and a decision uses one of them, never both.
``latency`` and ``callCost`` appear only where ``routing.callHistory`` is on,
and only where two candidates of the decision differ on them.

**The candidate models are not stored per call.** Which models exist and which
lost is a catalogue question; read it off the live catalogue with the readout
above. The row keeps the count and the reason set, which is what varies from
request to request.

Rows are purged with the rest of the telemetry table by
``nrllm:telemetry:purge``; a window shorter than your observation period
deletes the evidence before you read it.

.. _administration-governance-complexity:

The complexity columns are observed, not applied
================================================

The same rows carry a measurement of how involved each request was: a 0-100
structural score, the request shape (a single question, a conversation, or a
tool-assisted transcript), the number of tool schemas the send carries, the
payload size in bytes, the token estimate and how much of the model's context
window it filled.

**You see them for routed calls only.** The measurement is taken on every
configuration-driven send, fixed-mode ones included, but it is stored on the
telemetry row and the table above shows only rows whose model was chosen
automatically. An installation with no criteria-mode configuration collects
these columns and displays none of them; the figures are in
``tx_nrllm_telemetry`` if you query it directly.

**Nothing routes on any of it.** There is no setting that turns it into a
routing signal, and none is planned until three things have been shown on real
traffic: that cheaper models hold for simple requests, that quality does not
degrade, and that real cost drops by enough to be worth a permanent branch in
the decision path (:ref:`ADR-156 <adr-156>` states the criteria in full). The
columns exist so that question can be settled with data rather than opinion.

Two readings need care:

**The score is uncalibrated.** It is three capped terms — conversation turns,
tool count, context utilisation — chosen to be defensible, not fitted to
anything. Correlate against it; do not treat it as a threshold.

**"window not measured" is not "empty".** The token and utilisation figures
come from the context fit (:ref:`ADR-143 <adr-143>`). Where no fit ran they are
stored as NULL, and the page says so rather than showing a zero nobody
measured. The byte count is unaffected — it needs no fit — so a row that says
"window not measured" still tells you how large the send was. A utilisation
above 100 % is real: it is the overflow case, and it is deliberately not
clamped.

**A measured 0 % is a measurement.** A short chat against a large window rounds
to zero, and the page shows ``~N tokens, 0% of the window`` for it rather than
falling back to "not measured".

**"complexity not measured" replaces the whole cell**, and is a different
statement from "window not measured". Some calls choose a model without ever
sending a measurable payload through the context fit — an embeddings
configuration in criteria mode is the usual one. Its row has a decision to show
and nothing to measure, so the score, the shape, the tool count and the byte
count are absent rather than shown as zeros.

.. _administration-governance-call-cost:

What a call cost, and what it was before a model was chosen
===========================================================

The same rows carry two further groups that the page does not show. They are
queried directly against ``tx_nrllm_telemetry`` (:ref:`ADR-174 <adr-174>`),
which is why they are described here rather than pointed at on screen.

**What the call consumed.** ``actual_input_tokens``, ``actual_output_tokens``,
``actual_cost``, ``response_model`` and ``provider_retries``. The cost table
``tx_nrllm_service_usage`` holds the same figures as a daily aggregate with no
per-call key, so a cost there cannot be attributed to one request; these columns
can, because they sit on the row the correlation id identifies.

**What the request was, before anything chose a model.** ``facts_messages``,
``facts_turns``, ``facts_tools``, ``facts_payload_bytes``,
``facts_token_estimate`` and ``facts_shape``. The complexity group above is
measured after the model is chosen and partly from it — its utilisation is a
property of the chosen window — so it describes a decision and cannot inform
one. This group is model-independent: no window, no price, no chosen model.

The counts describe the transcript the caller sent, not the bytes that left the
server: the configuration's system prompt is added afterwards, from options that
only exist once a model is resolved. A system message the caller wrote is
counted. ``complexity_payload_bytes`` and the complexity shape are measured the
same way, before that prompt is applied — but on the *pruned* list, so on a row
where the fit dropped turns the pre-routing byte count is the larger of the two.

The two token estimates are not. ``facts_token_estimate`` is the raw estimate of
the caller's transcript and its tool schemas. ``complexity_tokens`` is the
context fit's figure. Three things separate them, and only the first is on every
row:

* **The calibration factor.** The fit scales its estimate by a factor that starts
  at 1.15 and only grows toward the prompt-token counts providers report; the
  fact group is estimated with the same estimator at 1.0. Two identical lists
  still differ by at least fifteen percent, and ``complexity_tokens`` is the
  larger one.
* **Pruning**, on the rows where the fit dropped turns: ``complexity_tokens``
  describes the bounded list, ``facts_token_estimate`` the one the caller sent.
* **The configuration's system prompt**, on the rows whose transcript does not
  already open with a system message. The fit charges it on top of the
  transcript; the fact group never sees it.

An injected skill block is *not* a fourth difference. On every path that writes
both columns it is already inside the transcript by the time the fact group is
measured, so both figures carry it. Subtract the three above before reading
anything into the gap.

**NULL means no available measurement, and is never a measured zero.** A
provider-reported cost takes precedence over model pricing, including a
reported ``0.0``. Without a reported cost, the token-shaped usage path derives
cost only when at least one model price field is positive. Two zero price
fields alone leave cost NULL; they do not establish a measured free call.
Token counts are NULL where the provider reported no usage block. The fact
group is NULL across the board with ``facts_shape = ''`` on the paths that
measure nothing, exactly as ``complexity_shape`` flags the complexity group.
``provider_retries`` is the one column that is always written — ``0`` there is a
measured "no retry", and rows that report NULL are the ones written before the
column existed.

A streamed run records no token counts. It carries a character-based estimate
rather than a provider usage block, and the columns are named ``actual``.

Cost per request shape over the last thirty days, for example:

.. code-block:: sql

   SELECT facts_shape,
          COUNT(*)                AS calls,
          AVG(actual_cost)        AS mean_cost,
          SUM(actual_cost)        AS total_cost,
          AVG(actual_input_tokens)  AS mean_in,
          AVG(actual_output_tokens) AS mean_out
     FROM tx_nrllm_telemetry
    WHERE crdate >= UNIX_TIMESTAMP() - 30*86400
      AND facts_shape <> ''
    GROUP BY facts_shape;

``AVG`` skips unknown NULL costs and includes reported ``0.0`` costs. A model
with two zero price fields and no reported cost contributes no cost value to
the average. Filter on ``actual_cost IS NOT NULL`` when you want only calls
with an available cost.

The structural complexity facts remain diagnostic. Measured call cost and
latency can affect ranking when ``routing.callHistory`` is enabled and usable
history exists. Listed price and measured call cost are alternative cost
bases; the active mode and signal availability decide which is used. See
:ref:`routing-reference` for weighting and precedence.
