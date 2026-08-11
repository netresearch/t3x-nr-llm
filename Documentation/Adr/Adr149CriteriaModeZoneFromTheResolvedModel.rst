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

◐ A criteria-mode send resolves its model twice — once for the gate, once in
the dispatch. In the default routing mode that is one extra
:php:`findActive()` query and an in-memory evaluation per send, against a call
that is about to cross a network to an LLM. Memoising the decision per call
would remove it, but that changes the routing invariant
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
No configuration that was permitted becomes refused.

✕ Not a guarantee about the model that answers. Routing runs again at dispatch,
and a change in the model set between the two resolutions would let them
disagree. The window for that is the same one ADR-138 already lives with.

Revisit when
============

The tool gate needs the same treatment. It is the other half of the axis and
the asymmetry above is a debt, not a design; the work is finding the point in
the tool loop where the serving model already exists.

The fallback chain needs its real zones too — that is the same question this
record answers for the primary, and it needs a cheaper resolution than one
routing decision per hop before it is worth answering.

Also revisit if the routing decision gains a per-call memo: the extra
resolution above disappears with it, and the two should be reconsidered
together.
