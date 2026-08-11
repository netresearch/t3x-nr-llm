.. _adr-149:

==================================================================
ADR-149: A criteria-mode trust zone comes from the resolved model
==================================================================

:Status: Accepted
:Date: 2026-08-11
:Amends: :ref:`ADR-144 <adr-144>` (where a criteria-mode zone comes from)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-144 <adr-144>` shipped with a known hole and named it as its own
revisit trigger. :php:`TrustZoneResolver::zoneFor()` read
:php:`LlmConfiguration::getProvider()`, which reads through the configuration's
model relation. A criteria-mode configuration has none — ``model_uid = 0``, the
model is chosen at call time — so every such record answered
``EXTERNAL_GLOBAL``, however local the model routing actually picked. A
configuration that only ever selects an on-premise Ollama could not be given a
confidential snippet. That is issue `#723`.

This is the same defect :ref:`ADR-143 <adr-143>` fixed one axis over: the
context window was also sized from the configuration's relation and also found
nothing there. The answer is the same shape — take the model that will actually
serve the call.

Decision
========

**The zone comes from the serving model when one is known.**
:php:`zoneFor()` takes an optional resolved :php:`Model` and reads the primary
zone from ITS provider. Passing nothing keeps the configuration's own relation,
which is the fixed-mode case and what every existing caller had.

**Fixed mode is untouched, structurally.** In fixed mode the configuration's
provider *is* the model's provider — :php:`getProvider()` is a delegation to
the relation — so a resolution there could only return what the gate already
had. :php:`LlmServiceManager` therefore resolves for the gate ONLY in criteria
mode. The invariant is a branch, not a coincidence.

**A routing failure stays a routing failure.** Criteria that match no model, or
match only models that cannot serve this operation, throw during resolution.
That exception belongs to the dispatch that follows — which resolves again and
raises it with its own semantics — so the resolution done for the gate swallows
it and hands the gate no model. With no serving model there is no serving
provider, and ``EXTERNAL_GLOBAL`` remains the honest answer. It is also the
answer this path already gave, so nothing is newly refused.

**The gate does not drive routing.** Resolving inside
:php:`InputContextTrustGate`, or reaching :php:`RoutingDecisionService` from
it, would invert the dependency: a governance check would decide which model
serves a call. The model is threaded in from :php:`LlmServiceManager`, which
already knows the operation and owns the dispatch. The gate stays a consumer of
a decision it cannot influence.

**One operation, one selection.** The manager resolves with the SAME
:php:`ProviderOperation` the terminal will use, which is the
:ref:`ADR-138 <adr-138>` rule for two resolutions of one call. A gate judging a
model the send never runs on would be worse than the fail-closed answer it
replaced.

What is deliberately not widened
--------------------------------

**The fallback hops.** :php:`zoneFor()` still reads each fallback
configuration's own relation, so a criteria-mode fallback still contributes
``EXTERNAL_GLOBAL``. Resolving a model per hop would run the routing decision
once for every entry of a chain that may never be walked, to answer a question
about a provider the call may never reach. Fail-closed is the right default for
a hop that has not happened.

**:php:`ceilingFor()`, and with it the ADR-094 tool gate.**
:php:`ToolCallPolicy` still asks :php:`zoneFor()` with the configuration alone,
so a criteria-mode run is still offered only the tools an external zone permits.
That leaves the two directions of one axis briefly asymmetric, which is stated
here rather than hidden: the READ side needs the model at a different point in
the run — tools are selected before the loop, and the loop's own resolution is
not this seam — and widening a signature for a caller that would have to be
rearranged first is the declaration-nothing-reads shape. The asymmetry is
fail-closed in the direction that matters: the tool gate offers LESS than it
could, never more.

Consequences
============

✓ A criteria-mode configuration that routes to a local model can carry a
classified snippet. The ADR-144 hole is closed for the primary provider.

✓ The refusal is checkable: the ``context_blocked`` audit row now names the
provider and model the zone was read from, instead of the empty relation a
criteria-mode record has.

◐ A criteria-mode send resolves its model twice on the pipeline path — once for
the gate, once in the dispatch — and three times on the streaming path, where
:php:`LlmServiceManager::streamChatWithConfiguration()` resolves once more for
its eager capability check before the opener resolves again. In the default
routing mode each of those is one extra
:php:`findActive()` query and an in-memory evaluation per send, against a call
that is about to cross a network to an LLM. Memoising the decision per call
would remove the extras, but that changes the routing invariant
(:ref:`ADR-142 <adr-142>`) rather than this gate, and it is not built here.
Fixed mode adds nothing.

The cost is paid **before** the gate's own short-circuit, so an installation
that has classified nothing — the majority, since the column ships undeclared
— pays it for an answer the gate then discards. Resolving lazily would mean
handing the gate a callable, which is the same inversion in slower motion: the
gate would still be the thing that decides when routing runs. The cheaper
answer is one resolution per call for everyone, and that lives in the planner,
not here.

◐ Enforcement can now REFUSE where it previously refused for a different
reason, and permit where it previously did not. An installation that had
classified sources and criteria-mode configurations was refusing all of them;
after this it refuses only the ones whose selected model is genuinely external.
No configuration whose zone was read from a provider it actually reaches
becomes refused.

One shape does newly refuse: a criteria-mode record that still carries a
``model_uid`` from an earlier fixed-mode edit. The TCA ``displayCond`` on that
column hides the field when the mode is criteria; it does not clear the value,
and nothing else clears it either. Such a record used to be judged against that
stale relation — a call it never reaches — and is now judged against the model
the criteria select. Where the stale relation is local and the criteria select
an external model, a send that used to be permitted now throws.

✕ Not a guarantee about the model that answers. The permit is computed from the
gate's resolution; the send runs on the dispatch's own. The window between the
two is the one ADR-138 already lives with, but what falls through it is no
longer a mismatched cache key or a capability check against the wrong adapter —
it is a classified snippet reaching a provider the gate never approved. It does
not take a change in the model set, either: :php:`CandidateRanker` feeds
measured ``quality``, ``health`` and ``cost`` signals into the ordering for
every mode that uses them, so the winner can move between two resolutions of
one call. Before this record criteria mode was always ``EXTERNAL_GLOBAL``, so
the disagreement had no governance consequence at all.

Revisit when
============

The tool gate needs the same treatment. It is the other half of the axis and
the asymmetry above is a debt, not a design; the work is finding the point in
the tool loop where the serving model already exists.

The fallback chain needs its real zones too — that is the same question this
record answers for the primary, and it needs a cheaper resolution than one
routing decision per hop before it is worth answering.

A per-call memo on the routing decision is now the answer to the ✕ above and
not only to its cost: one resolution per call closes the window in which the
gate's model and the dispatch's can disagree. It changes the routing invariant
(:ref:`ADR-142 <adr-142>`) rather than this gate, which is why it is not built
here, but it is the change this record most wants.

A criteria-mode record's leftover ``model_uid`` is load-bearing in two
directions now — it is what the pre-ADR-149 zone was read from, and it is what
a switch back to fixed mode restores. Clearing it on the mode switch, in a
DataHandler hook or an update wizard, would remove the newly-refusing shape
above; it is not done here because it changes what a mode switch does to stored
data, which is a decision about the record, not about the gate.
