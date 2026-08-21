.. include:: /Includes.rst.txt

.. _adr-174:

=============================================================================
ADR-174: Per-call cost, and request facts measured before the model is chosen
=============================================================================

:Status: Accepted
:Date: 2026-08-14
:Amends: :ref:`ADR-156 <adr-156>` (adds two column groups to the same row; its observer-only rule is unchanged)
:Authors: Netresearch DTT GmbH

.. _adr-174-context:

Context
=======

:ref:`ADR-156 <adr-156>` wrote down three conditions that must hold before
anything is allowed to route on complexity, and the third of them is *"real cost
drops"*. Neither instrument that criterion needs exists.

**No cost can be joined to a call.** ``tx_nrllm_telemetry`` records routing,
success, latency and a complexity estimate per provider call.
``routing_signal_cost`` is a ranking signal, not money, and ``complexity_tokens``
is explicitly an estimate. The money lives in ``tx_nrllm_service_usage``, which
is a **daily aggregate** with no ``correlation_id`` — so "median cost per
complexity bucket" is not hard to compute, it is not computable.

A second defect sits underneath. ``UsageStatistics::$estimatedCost`` is never set
by any adapter, so cost is derived solely from the model row's pricing, and
``Model::hasPricing()`` is false when both prices are zero. A model priced at
zero — Ollama, Groq, anything local — therefore records a cost of ``0``, which is
the same number an unpriced model records. That is precisely the arm any
cheap-model experiment is about, and it is the arm the data cannot describe.

**The complexity score is measured after the model is chosen, and partly from
it.** ``resolveModel()`` runs before ``fitToContextWindow()`` on every path, and
``measureComplexity()`` hangs off the fit. The size term of the score is
estimated tokens against the budget of the model that was **already selected**;
context utilisation is by definition a property of the chosen window. The
decision path never sees the payload at all — ``decide(array $criteria,
?RoutingPolicyMode)`` takes criteria. So the record is sound post-decision
observability and cannot become an input to the same decision, and any adaptive
step that would bucket requests *before* the decision rests on a data flow that
does not exist.

Decision
========

Two more column groups on the one row ADR-156 already writes, and nothing reads
either of them inside the decision path.

What the call actually consumed
-------------------------------

``UsageMiddleware`` already extracts the tokens and derives the cost for the
daily aggregate. It now records the same three facts a second time, onto the
:php:`TelemetrySignals` scratchpad, and ``TelemetryMiddleware`` writes them to
the row under the correlation id the call already carries. Two writes, two
shapes, one derivation: the aggregate is what a budget needs, the per-call copy
is what an experiment needs, and neither is recomputed from the other.

**NULL is a measurement that did not happen, and is never a zero.** This is the
whole point of the group and the reason it is stated before the columns:

- ``actual_cost`` is NULL where the serving model carries no pricing. An unpriced
  model and a free one are different claims, and only one of them is "this call
  cost nothing".
- ``actual_input_tokens`` / ``actual_output_tokens`` are NULL where the provider
  reported no usage block.
- ``provider_retries`` is the exception and is always written: both write sites
  hold the counter, so a recorded ``0`` is a measured "no retry" and no row from
  this version reports NULL. The column is nullable because rows that existed
  before it did have no value for it, and that is the only population
  ``provider_retries IS NULL`` selects.

**Telling "no usage" from "zero usage" needs a rule, because the type cannot.**
:php:`UsageStatistics` types its three counts as plain ints, so an adapter that
finds no ``usage`` block builds the same object as one that was told zero. The
rule is: all three counts zero at once means nothing was reported. A call that
reached a provider consumed prompt tokens, and a response that produced no
output still reports its prompt count — so a ``0`` that survives the rule is a
measurement. Widening :php:`UsageStatistics` to nullable ints would say it in the
type instead; that is a breaking change to an ``@api`` class used on every
response, and the rule buys the same distinction for this row without it.

**The model that answered is recorded separately from the model that was
asked for.** ``served_model`` is the configuration's model. ``response_model`` is
what the provider named on the response, which can be a dated snapshot of the
alias that was requested. Which one answered is the joinable fact.

**A streamed run records no token counts.** It has a char-based estimate and no
usage block, and putting an estimate in a column named ``actual`` would recreate
the exact confusion this record exists to end. Its retries and its facts are
recorded; its usage stays NULL.

**Retries are counted through a process-wide counter, not the scratchpad.** The
retry loop lives in ``AbstractProvider::sendRequest()``, which is reached from
the pipeline terminal and never sees the call context. :php:`ProviderRetryCounter`
is monotonic and is never reset: both write sites take a snapshot difference
around the run they record. A reset would be wrong under nesting — a tool loop
runs pipeline runs inside a pipeline run — while a difference counts correctly at
both levels. A repeat is counted when the loop is about to send again, not at the
attempt counter: the final failed attempt increments that and is never repeated.

**A difference is only sound around a run that holds the stack throughout, and
the streaming path does not.** :php:`TelemetryMiddleware` wraps a synchronous
pipeline run, so one before/after difference is exact there.
:php:`StreamingDispatcher::drain` is a generator: it suspends at every yield, the
consumer runs in that gap, and a provider call the consumer makes there
increments the same counter. One difference around the whole drain would put
those retries on the stream's row. The dispatcher therefore sums a difference per
resumption segment — opener, each pull of the inner stream, the flush — and
counts nothing while suspended. A drain the consumer abandons closes no final
segment, so a later unrelated call cannot be attributed to it either. The
constraint generalises: a write site that suspends cannot use a single
difference.

What the request was, before anything chose a model
---------------------------------------------------

:php:`RequestFacts` is formed by the manager on the caller's own thread, before
:php:`runThroughPipeline` builds a context — which is before ``resolveModel()``,
because that runs inside the terminal. Message count, turn count, tool count,
payload bytes, a provider-independent token estimate, request shape.

**The operation is not part of the group.** ``operation`` already names it on the
same row, and a second copy would only be a way for the two to disagree.

**What is counted is the caller's transcript, not the wire.** The
configuration's stored system prompt is prepended by ``applySystemPrompt()``
inside the terminal, from call options built out of the resolved model — so it
is in none of these figures, and it cannot be without moving the measurement
past the decision the measurement exists to precede. A system message the caller
supplied is counted. The post-fit complexity record draws the same line for its
counts: ``complexity_payload_bytes``, ``complexity_shape`` and the turn term
inside ``complexity_score`` are measured on ``ContextFitResult::$messages``, the
bounded list, which the configuration's prompt has not been prepended to either
— so the system prompt is never the difference between them. Pruning is: where
the fit dropped turns, the post-fit record measured fewer messages than the
pre-routing one, and every count below inherits that gap.

``complexity_tokens`` is the one figure not measured that way, and comparing it
with ``facts_token_estimate`` needs to account for it. It is
``ContextFitResult::$estimatedTokens``: the fit's own estimate, taken over the
list the fit settled on, with the configuration's system prompt charged on top
(``missingSystemPromptTokens()``) and the whole sum scaled by the fit's
calibration factor. **Three things separate the two figures, and only one of them
is on every row.**

*Calibration, always.* :php:`TranscriptEstimator` scales its result by
``max(1.0, $calibration)``. The collector passes ``1.0``; the fit passes a factor
that starts at ``ContextWindowManager::CALIBRATION_SEED`` — 1.15 — and only ever
grows from there. Two identical lists therefore still differ by at least fifteen
percent, with the fit's figure the larger one.

*Pruning, when the fit dropped turns.* ``complexity_tokens`` describes the
bounded list, ``facts_token_estimate`` the list the caller handed over.

*The configuration's system prompt, when the transcript does not already open
with one.* :php:`ContextWindowManager::missingSystemPromptTokens()` returns 0
when ``$messages[0]`` is a system message, and 0 again when the effective prompt
is empty; otherwise it charges that prompt — per-call
override and composed snippets included. On a configuration without a system
prompt this difference is therefore absent. Neither figure holds that prompt as text; it is a
charge the fit adds beside its transcript estimate, which is why it moves this
one figure and none of the counts.

**The skill block is not a fourth difference, and it is named here because the
code reads as though it were.** ``injectedTokens()`` does charge it, but the two
fits whose results become ``complexity_tokens`` — the ones in
:php:`LlmServiceManager::fitToContextWindow()` and
:php:`LlmServiceManager::reportPromptOverflow()`, between them the only callers
of ``recordComplexity()`` — both pass ``$injectedText`` as ``''``. The one caller
that passes a real block, :php:`ConversationService`, records no complexity from
its fit. And where a block is injected at all, :php:`SkillInjectionService`
prepends it into the transcript at the public entry point, before
``collectRequestFacts()`` runs — so both figures carry it as text already.
:ref:`ADR-151 <adr-151>` records the same emptiness from the readout side, for
the agent loop.

The counts beside the two token figures are the closest pair, with the same
pruning caveat: on a row where the fit dropped turns,
``facts_payload_bytes`` exceeds ``complexity_payload_bytes``.

**What is deliberately excluded is the load-bearing half of the design**:
context utilisation, the chosen model, its window, its price. Every one of them
is a property of a decision that has not been taken, and admitting any one would
recreate the circularity. :php:`RequestFactsCollector` is built so that this is
structural rather than a promise — it takes messages and tool schemas, and its
only two collaborators are a transcript reader and a token estimator, neither of
which can name a model. A test asserts that constructor rather than the numbers,
because a later collaborator would leave every recorded figure looking correct.

**The token estimate is the same estimator the context fit uses, uncalibrated.**
:php:`TranscriptEstimator` needs no model and no budget. The calibration factor
is grown from a model's real prompt-token counts, so it is exactly the part that
would make the figure model-dependent, and it is passed as ``1.0``. Using the
same estimator is what makes ``facts_token_estimate`` comparable with
``complexity_tokens`` at all; the three things that separate the two figures are
named above, calibration among them.

**One reader of a transcript, not two.** "A turn", "tool traffic" and the byte
count are defined once, in :php:`MessageInspector`, and both the pre-routing and
the post-fit measurement read through it. The two records sit on one row in order
to be compared; a second, subtly different definition would break the comparison
without breaking either caller's own tests.

**The post-fit complexity record stays exactly as it is.** It answers a different
question — how full the chosen window was — and both are worth having.

The paths that form no fact set
-------------------------------

The four configuration-driven sends form one: chat, completion, tools, stream.
Nothing else does — the provider-pinned entry points, embeddings, and every
specialized service, image, speech and translation alike. Translation belongs in
that list and is easy to leave out of it: ``DeepLTranslator`` extends
``AbstractSpecializedService``, whose ``dispatch()`` runs
``ProviderOperation::Translation`` through the same pipeline, so a DeepL call
writes a telemetry row like any other and that row's fact group is empty. The
boundary is the one the complexity measurement already has, for the same reason:
these paths carry no transcript the collector can read, or they are not on a path
that measures one. ``facts_shape`` is ``''`` there and the five numbers are NULL,
which is the flag a reader checks before averaging anything.

What this does NOT do
=====================

**Nothing routes on any of it.** There is no new signal in
:php:`CandidateRanker`, no weight in :php:`RoutingPolicyMode`, no predicate in
:php:`EligibilityEvaluator`, and no opt-in that would add one. ADR-156 keeps its
observer-only status and its three activation criteria unchanged; this record
supplies the evidence those criteria need rather than pre-empting them. The
criteria are what decides, and they have not been evaluated yet.

**No new reader in the backend.** The Governance tab shows the routing and
complexity groups; these two are queried in SQL, like the rest of a table that
has no TCA and no UI by design. The consumer named in ADR-156 is the evaluation
of its own activation criteria, and that evaluation is a query, not a page. A
readout can be added when there is a result worth showing; adding one before
there is any data would be a page that renders NULLs.

**No new index.** The analyses these columns exist for filter by ``crdate``,
which is indexed, and group in memory afterwards. An index chosen before a query
exists is a guess.

**No estimate in a column named "actual".** See the streaming case above.

**No retention change.** The new columns live inside the existing row and are
purged with it by ``nrllm:telemetry:purge``.

**No prompt, no response.** Everything added is a count, a size, a price, an enum
name or a model id. The row's prompt-freedom stays structural: neither new DTO
has a field that could hold content.

Consequences
============

✓ "What did this request cost, and what shape was it" is one row and one join
key. Cost per complexity bucket, per shape, per policy mode and per served model
are all ``GROUP BY`` over ``tx_nrllm_telemetry``.

✓ ADR-156's third activation criterion becomes computable, which is the only
thing standing between the complexity question and an answer.

✓ A zero-priced model now reports real tokens and no cost. The cheap-model arm
is describable for the first time.

✓ A request can be characterised without reference to the model that answered
it, which is the prerequisite for bucketing requests before a decision — should
the criteria ever be met.

◐ Eleven more columns on a high-volume table, all scalars, most of them NULL on
any given row. The row was already the widest thing this extension writes per
request.

◐ Two writes of the same tokens, to the aggregate and to the row. They are
derived once and written twice, so they cannot disagree, but the aggregate stays
the billing ledger and this stays observability — merging them would make one
table answer two questions badly.

✕ "All three counts zero means nothing was reported" is a rule, not a type. A
provider that genuinely reports a zero-prompt call would be recorded as
unmeasured. No provider does; if one appears, the fix is the nullable
:php:`UsageStatistics`, with the deprecation that implies.

✕ Streamed runs stay a hole in the cost data. They have no usage block to read,
and the honest column is empty rather than approximate.

✕ The figures are recorded and nobody has looked at them yet. Whether the
pre-routing facts predict anything about the decision is exactly the open
question; this record only makes it askable.

Revisit when
============

ADR-156's three activation criteria have been evaluated against a real sample —
whichever way they come out. That evaluation is now a query rather than a
project, and its result belongs in ADR-156, not here.

Also revisit if a provider is found that reports a genuine zero-token call, or if
streamed responses start carrying a usage block.
