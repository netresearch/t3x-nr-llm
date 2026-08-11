.. _adr-143:

==================================================================
ADR-143: Bound every send against the model that actually serves it
==================================================================

:Status: Accepted
:Date: 2026-08-10
:Amends: :ref:`ADR-107 <adr-107>` (which model's window, and which paths bind one)
         and :ref:`ADR-139 <adr-139>` (its "generic paths bind no window" gap)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-107 <adr-107>` keeps an agent transcript inside the model's context
window. :ref:`ADR-139 <adr-139>` recorded that only two callers used it —
:php:`ConversationService` and :php:`ToolLoopService` — so which API a consumer
happened to call decided whether a long transcript was pruned or handed to the
provider whole. That is issue `#688`.

Reading the code turned up a second, quieter defect in the paths that DID bind
a window.

:php:`ContextWindowManager::fit()` sized the budget from
``$configuration->getLlmModel()``. A **criteria-mode** configuration carries no
model relation — ``model_uid = 0`` — and
:php:`ConfigurationCallPlanner::resolveModel()` deliberately does not write the
resolution back, because mutating the entity would mark it dirty and Extbase
would persist ``model_uid``, silently converting a dynamic configuration into a
fixed one. So on every dynamically-selected call the manager found no model,
fell back to its unknown-window default, and sized the budget against a number
that had nothing to do with the model on the wire.

Decision
========

**The window comes from the resolved model.** :php:`fit()` takes the model that
will actually serve the send and prefers it over the configuration's relation;
the response reserve follows the same model. Passing nothing keeps the entity's
model, which is the fixed-mode case and the behaviour every existing caller had.

**Every configuration-driven send of a transcript is bounded, at the point that
knows the model.** The bind sits inside the middleware-pipeline terminal in
:php:`LlmServiceManager` — chat, tool calling and, in its opener, streaming —
because that is the first place the resolved model exists. For a stream it runs
before the adapter is asked for the first chunk: once a stream is open there is
nothing left to prune. A tool-calling send counts its tool schemas against the
same budget, because they are on the wire with the transcript.

Embeddings are deliberately not bounded here. They carry neither skills nor
snippets nor a transcript, and their size limit is the provider's own input
limit rather than a context window to prune turns out of.

**A completion reports; it does not prune.** A raw prompt is a single unit: there
are no older turns to drop, and silently shortening a caller's prompt would
change what they asked for, which only the caller can judge. What the path does
deliver is the decision being explicit — an overflowing completion is named,
with the model and the budget it exceeded, instead of surfacing later as an
opaque provider error.

**Overflow at the floor still sends.** The estimate errs high, so a payload that
does not fit may well succeed; if it does not, the provider's own error is what
the caller would have received anyway. Refusing here would turn a call that
might have worked into one that certainly does not. This matches what
:php:`ConversationService` already did, and it is why the bound is a bound and
not a gate.

Consequences
============

✓ A criteria-mode configuration is sized against its real model. This is a
behaviour change on the paths that already bound a window: a transcript that
previously slipped through against a 128k assumption is now pruned against the
4k model actually answering.

✓ Chat and streaming through the generic manager are bounded like a conversation
or an agent loop.

✓ Completion overflow is visible before the provider rejects it.

◐ Two fits can run for one send — a conversation or agent loop fits its
transcript for its own semantics (`dropped_turns` is a stored fact, ADR-121),
and the send-level bound runs again at dispatch. The second is a no-op when the
first already fit, and it costs one estimator pass over a list that is about to
cross a network.

◐ `LlmServiceManager` gained two optional constructor arguments. Both default to
null, so every existing construction keeps its exact previous behaviour — a null
context window means "bounded by the provider", which is what these paths did.

✕ Not a token guarantee. The estimator is an estimate with a calibration factor;
ADR-107's limits are unchanged.

Revisit when
============

A consumer needs the send-level fit's decision as data rather than a log line —
that is the same surface question the routing decision trace raised
(:ref:`ADR-142 <adr-142>`), and the two should get one answer, not two.
