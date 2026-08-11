.. include:: /Includes.rst.txt

.. _adr-151:

============================================================================
ADR-151: The context budget is a breakdown, not a number
============================================================================

:Status: Accepted
:Date: 2026-08-11
:Authors: Netresearch DTT GmbH

.. _adr-151-context:

Context
=======

:ref:`ADR-107 <adr-107>` bounds a transcript against the model's context
window, and :ref:`ADR-143 <adr-143>` extended that bound to every
configuration-driven send. Both produce a :php:`ContextFitResult`: how many
turns were dropped, the estimated total, the budget, whether it overflowed at
the floor.

That is the *verdict*. It is not the *reason*. An operator whose agent run
keeps losing history could read "dropped 4 turns, 7100 of 6946 tokens" and had
no way to learn that 4300 of those tokens were a tool schema they could switch
off, or a snippet block someone attached to the configuration last week. The
fit's own log lines said the same thing in the same shape.

:ref:`ADR-143 <adr-143>`'s *Revisit when* named this: a consumer needing the
fit's decision as data rather than a log line.

A second gap sits next to it. :ref:`ADR-144 <adr-144>` gave injected context a
data class and built :php:`InputContextClassifier` to fold the strictest
declaration across a configuration's snippets and skills. Nothing renders it.
The classification is visible only when the gate refuses a call — at which
point the operator learns the answer by being blocked.

.. _adr-151-decision:

Decision
========

**The fit reports where the window went, as data.**
:php:`ContextFitResult` gains a :php:`ContextBudgetBreakdown`: the window, the
reserved output, the safety margin, the budget, and four component lines —
transcript, tool schema, system prompt, skills — plus the estimated total and
what is left.

**The lines close.** The four components sum to :php:`estimatedTokens`, and
``contextLength - reservedOutput - safetyMargin`` equals ``budget``. This is not
decoration: a breakdown a reader can subtract with must not drift from the
figure the pruning decision actually used. So the components are *derived from
that one figure* rather than measured a second time — the transcript line is
estimated directly and the tool-schema line is the remainder, which is exactly
the marginal cost of putting the schema block on the wire. Four independent
estimates would each carry their own rounding and would not add up.

**Snippets get no line of their own, and the label says so.**
:php:`ConfigurationSnippetResolver` composes a configuration's tag-selected
snippets INTO the effective system prompt (:ref:`ADR-031 <adr-031>`) before the
caller hands it to :php:`fit()`. At the point the estimate is taken they are no
longer distinct text. Two options were available: change what callers pass
:php:`fit()` so the block arrives separately, or report one line and label it
honestly. The second was taken. The first means a ninth parameter on
:php:`fit()` with exactly one caller able to fill it, to split a figure whose
components would then still be summed for every decision the manager makes —
surface bought for a readout. The line reads "System prompt (incl. snippets)"
everywhere it is rendered, and a test pins that the snippet block lands there
and in no other line.

**The surface is the playground inspector.** It is where the run trace already
lives, where the request step already shows what went on the wire, and where
:php:`ToolLoopService` already carried a note that a dedicated inspector step
was the follow-up ADR-107 wanted. The accounting is recorded as its own
:php:`RunStep` of kind ``context``, ahead of the request step it explains, and
recorded even when the floor overflows and the run stops — that is the run whose
operator most needs it.

**The classification is read from the gate's own service.** The same panel shows
every source the run injects — each snippet and skill by name, with the class it
declared or none — and the effective, strictest class.
:php:`InputContextClassifier::classify()` is now the fold over
:php:`InputContextClassifier::sources()`, so the readout and the ADR-144 gate
answer from one list and cannot disagree. Source NAMES only, never text: the
classification exists because the text is sensitive.

*The run, not the configuration.* A playground run also injects the forced
snippets and skills the operator ticked, and those reach the wire exactly like
the configuration's own, so :php:`sources()` takes them as arguments and the
panel lists them. The gate does not see them: it asks :php:`classify()`, which
answers for the configuration alone. A forced source is therefore **shown and
not gated**, and the panel is a superset of what ADR-144 enforces rather than a
mirror of it. Widening the gate to the forced set is a decision about
enforcement and belongs to ADR-144, not to a readout.

.. _adr-151-not:

What this does not do
=====================

**It does not change the estimate.** No component is measured differently, no
threshold moves, no run is pruned that was not pruned before. This is a readout
of arithmetic that already happened.

**It does not correct the system-prompt over-count.**
:php:`ContextWindowManager::missingSystemPromptTokens()` decides whether a
prompt will be prepended by looking at ``$messages[0]``, while
:php:`MessageShaper::applySystemPrompt()` scans the whole list. A transcript
whose system message sits deeper is therefore charged for a prompt that will not
be prepended. That errs HIGH, which is the safe direction, and it stays. What
changes is that the charge is now *visible* — a non-zero system-prompt line next
to a transcript that already carries a system message. Making the readout of a
known imprecision the occasion to change the imprecision would ship a behaviour
change inside an observability PR.

**Two of the four component lines are structurally empty on the surface this
ships to, and the panel says so.** The agent loop assembles its own prompt: it
bakes the effective system prompt as message 0 before any fit, and it injects
skill prose into the transcript with
:php:`SkillInjectionService::augmentMessages()`. By the time
:php:`ContextWindowManager::fit()` runs, both are inside the message list, so
:php:`missingSystemPromptTokens()` returns 0 with
:php:`systemPromptInTranscript` true, and no caller on that path passes an
injected skill block at all. On the playground the system-prompt line therefore
always reads "counted in the transcript" and the skills line always reads 0 —
not because nothing is there, but because the transcript line already carries
it.

The alternative was to make the two separable at the loop's seam: stop baking
the prompt into the list and let the shaper prepend it after the fit. That
changes what the loop assembles and in which order — the bake exists because a
forced snippet system message would otherwise satisfy the manager's
"a system message already exists" guard and suppress the configuration prompt
for the run — so it is a behaviour change wearing an observability change's
clothes, which the paragraph above refuses for the same reason. The lines stay,
because they are part of the sum a reader subtracts with, and the panel states
under the table why those two are empty here. They carry real figures for a
send that injects either after the fit; :php:`fit()` supports that and the unit
tests pin it.

**It does not reach the AgentRun module or the generic API paths.** The
:php:`LlmServiceManager` send-level fit (ADR-143) now produces a breakdown, but
nothing there renders it; its overflow still surfaces as a log line. The
consumer would be a run-history view, and that view does not exist yet.

**It is not merged with the routing decision trace.** :ref:`ADR-142 <adr-142>`
raised the same surface question for routing, and ADR-143 said the two should
get one answer. They still should — a single "why did this send look like this"
panel covering model choice and window accounting. Both readouts are being
built at once and against different data; converging them before either has a
user would be designing the join from two guesses. The convergence point is
named here so the next reader does not build a third.

.. _adr-151-consequences:

Consequences
============

●● An operator can see which component fills the window, per round, and act on
the one that is theirs to change.

● The data-class declaration finally has a reader that is not a refusal. An
operator can see that a configuration carries a CONFIDENTIAL snippet before a
call is blocked for it.

◐ :php:`ContextFitResult` grew a required constructor argument — a BREAKING
change for anyone constructing the result themselves, marked as such in the
CHANGELOG. ``Tests/Unit/Api/api-surface.txt`` does **not** catch it: the
snapshot records properties and methods and has never recorded a constructor,
so what it pins is the new :php:`breakdown` property, not the signature break.
The CHANGELOG entry is the only place the break is stated. No default is
offered, because the breakdown restates :php:`budget` and
:php:`estimatedTokens`: a defaulted one would permit a :php:`ContextFitResult`
whose halves contradict each other, and every surface would then report "no
accounting" for a fit that was measured. Construction is the manager's alone in
production, and the closure rule made :php:`ContextBudgetBreakdown`
:php:`@api` too.

◐ One extra estimator pass per fit, over the list that is about to cross a
network. The fit already makes at least two.

✕ The four lines are estimates scaled by the manager's calibration factor, not
tokenizer counts. ADR-107's limits are unchanged: a breakdown that adds up is
not a breakdown that is exact.

Revisit when
============

A run-history surface exists that needs the same accounting after the fact, or
the routing readout has enough users to make the joined panel a real design
rather than a guess at one.
