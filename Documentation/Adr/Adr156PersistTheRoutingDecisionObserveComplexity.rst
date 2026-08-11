.. _adr-156:

==============================================================================
ADR-156: Persist the routing decision, and observe complexity without routing
==============================================================================

:Status: Accepted
:Date: 2026-08-11
:Amends: :ref:`ADR-142 <adr-142>` (both of its deferrals are lifted, one of them only halfway)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-142 <adr-142>` deferred two things and said exactly why.

**The decision trace**, on a condition: *"a trace whose only reader is a future
analytics view is a declaration nothing reads"*. :ref:`ADR-148 <adr-148>` then
built a reader — the Governance tab explains what a configuration **would**
resolve to. It recomputes live and reads nothing persisted, so it cannot answer
the question an operator actually arrives with: *this* call, yesterday, ran on
model A. Why?

**Complexity routing**, for want of evidence: *"routing on an estimated
complexity score needs evidence that the score predicts anything, and that
evidence does not exist yet. Measuring it first, deciding later, is the same
shape ADR-138 used"*. Nothing has been measured since, so the evidence still
does not exist — and it never will until something writes the numbers down.

``tx_nrllm_telemetry`` (:ref:`ADR-058 <adr-058>`) already holds one immutable,
prompt-free row per provider pipeline run, and already carries which model
served (``served_model``) and whether a fallback ran (``fallback_attempts``).
What it cannot say is **why** that model, or how big the request was.

Decision
========

Two column groups on the one table, and one reader for both.

**The decision summary is six scalars, not the decision.**
:php:`RoutingDecision` holds Model entities, per-candidate scores and per-signal
floats. What is persisted is :php:`RoutingSummary`: the policy mode, the
candidate count, the distinct rejection reasons, and three booleans saying
whether quality, health and cost actually moved this decision. The candidate
MODELS are deliberately not stored — which models exist and which lost is a
catalogue question the Governance tab answers against the live catalogue, and a
per-request copy would grow the log by the size of the model table and go stale
on the next rename.

**"Signal used" means it moved the decision, not that the mode weighed it.** A
signal counts as used when it carried weight in that mode AND at least one
eligible candidate had a measured value. Each half alone over-reports.

:php:`CandidateRanker` contributes neither value nor weight for an absent
signal, so a decision taken in ``quality`` mode against a catalogue nobody has
scored ranks exactly as ``providerPriority`` would. "The mode was quality" and
"quality decided anything" are different facts, and only the second one explains
the outcome.

The mirror case is why the weight is asked for rather than inferred from what
the ranker collected: :php:`CandidateRanker::signalsFor()` collects ``cost``
whenever the criteria set ``preferLowestCost``, but ``quality`` weighs cost at
0.0 and :php:`score()` skips a zero-weight signal. ``quality`` +
``preferLowestCost`` — both operator-settable — would otherwise record a cost
signal that moved no score. The cost TIEBREAK in that combination is a separate
mechanism (the criteria ordering equal-scoring candidates by raw cost) and is
deliberately not reported as a signal.

**One evaluation, two consumers.**
:php:`ModelSelectionServiceInterface::resolveModelForCall()` returns the model
AND the summary; :php:`resolveModel()` is that method with the reasoning
dropped. The alternative — resolve, then call :php:`explainRouting()` to learn
why — would run discovery, eligibility and ranking twice on every criteria-mode
request, and a model toggled between the two runs would make the recorded reason
describe a decision that never ran.

**The scratchpad is the channel, and both write sites use it.** The summary and
the complexity are recorded onto :php:`TelemetrySignals`, the one object that
survives the pipeline unwind. :php:`TelemetryMiddleware::safeRecord()` and
:php:`StreamingDispatcher::recordTelemetry()` both read it — the pair ADR-058
established, and moving only one of them would make streamed runs a silent hole
in the trace.

**A run that chose nothing writes that it chose nothing.**
``routing_policy_mode`` is ``''`` for fixed mode, for the service paths that
resolve no configuration, and for every row written before these columns
existed. Zeros would read as a decision that considered no candidates. The
reader filters on the empty mode in SQL rather than after the fact, because the
row limit is applied in the query: on an installation whose traffic is mostly
fixed-mode, filtering afterwards would fill the window with rows the page drops
and report a period's real decisions as none.

Complexity is measured and nothing more
---------------------------------------

Six figures per request: a 0-100 structural score, the payload size, the token
estimate, the tool count, the context utilisation and the request shape. All six
are shown in the readout — a column nothing reads is the thing ADR-142 refused,
and "raw evidence for a later sample" is not a reader.

**Nothing routes on them.** There is no new signal in :php:`CandidateRanker`, no
weight in :php:`RoutingPolicyMode`, no predicate in
:php:`EligibilityEvaluator`, and no opt-in flag that would add one. The
estimator's only consumer is a telemetry column.

.. _adr-156-activation:

**What has to be true before anything may route on this.** All three, measured
over a sample of real traffic, not argued:

1. **Cheaper models hold for simple requests.** Low-score requests served by a
   cheaper model complete successfully at a rate indistinguishable from the
   model that would otherwise have served them.
2. **Quality does not degrade.** On the same low-score population, the measured
   quality signal (:ref:`ADR-060 <adr-060>`) for the cheaper model is not worse
   than for the incumbent.
3. **Real cost drops.** The recorded cost for that population falls by enough to
   be worth a routing rule — a rounding-error saving buys a permanent branch in
   the decision path.

Fail any one of them and the correct outcome is to delete the idea, not to
weaken the criterion.

**The score's weights are judgement, not calibration** — the same admission
ADR-142 makes about the ranking weights. Three capped terms (conversation
turns, tool count, context utilisation) summing to at most 100. Nothing depends
on the exact numbers precisely because nothing routes on them; if the evidence
says a different shape predicts better, changing them breaks no behaviour.

**Bytes, not characters.** The payload size is :php:`strlen()`, and the field is
named ``payloadBytes`` to say so. The token estimator this shares a call with
counts bytes too (:ref:`ADR-121 <adr-121>`); a second unit here would make the
two figures incomparable.

The payload size is the one size figure that is always there: it needs no
context fit. That is what earns it a place in the readout rather than only in
the table — on a send where no fit ran, the token estimate and the utilisation
are NULL and the byte count is all the page can honestly show.

**Context utilisation comes from the fit** (:ref:`ADR-143 <adr-143>`) — the only
place that knows the token estimate and the model's budget together. Where no
fit ran, the token and utilisation figures are SQL NULL rather than zero: an
unmeasured send is not an empty one. The utilisation is not clamped at 100,
because above 100 is the overflow ADR-143 exists to report.

What this does NOT do
=====================

**No per-component context breakdown.** Utilisation is one number, estimated
tokens over budget. A sibling workstream is adding a per-component breakdown to
:php:`ContextFitResult` (which share of the window is system prompt, transcript,
tool schemas). When it lands, the ``complexity_context_percent`` column is the
place it should converge: the breakdown answers *what filled the window*, this
answers *how full it was*, and the second is the aggregate of the first. Nothing
here forecloses that — the reader shows one number and would show more.

**No routing on complexity.** See the criteria above.

**No prompt, no response, no criteria echo, no model list.** The table's
prompt-freedom is structural — the DTO has no field for any of them — and stays
that way. Everything added here is a count, a size, an enum name or a boolean.

**No retention change.** The new columns live inside the existing row and are
purged with it by ``nrllm:telemetry:purge``.

Consequences
============

✓ "Why model A for this call" is answerable after the fact, not only for a
hypothetical. The Governance tab shows both halves on one page.

✓ ADR-142's condition for persisting the trace is met by construction: the
reader ships in the same change as the writer.

✓ The complexity question can now be settled with data instead of opinion, and
the bar for acting on it is written down before anyone has a stake in the
answer.

◐ Twelve columns on a high-volume table. They are scalars, and eleven of the
twelve are ints or short enums; the row was already the widest thing this
extension writes per request.

◐ The routing summary costs one object allocation per criteria-mode resolution,
and nothing else — it is read off the decision that was already computed.

✕ The score is uncalibrated. It is a number to correlate against, not a number
to trust.

✕ A fixed-mode installation records no decisions and sees an empty table. That
is correct — nothing was chosen — but it does mean the readout is silent exactly
where an operator might most want reassurance that routing is not happening.

Revisit when
============

The three activation criteria have been evaluated against a real sample —
whichever way they come out. A "no" is as much a result as a "yes" and should be
written into this record rather than left for the next person to re-derive.

Also revisit when the per-component context breakdown lands, to fold it into the
same column group rather than beside it.
