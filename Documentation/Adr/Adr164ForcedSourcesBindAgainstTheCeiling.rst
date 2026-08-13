.. _adr-164:

===============================================================
ADR-164: A run's forced sources bind against the trust ceiling
===============================================================

:Status: Accepted
:Date: 2026-08-13
:Amends: :ref:`ADR-144 <adr-144>` (the ceiling now reads a run's forced set, not
    the configuration alone) and :ref:`ADR-151 <adr-151>` (its "shown and not
    gated" asymmetry is resolved in favour of gating)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-144 <adr-144>` refuses a call whose injected context is classified
above the trust zone the run can reach. It asks
:php:`InputContextClassifier::classify()`, which answers for the configuration's
own snippets, skills and system prompt.

A run can inject *more* than that. The Tool Playground builds a forced set from
the request body and :php:`ToolLoopService` adds each entry as a leading system
message, so it reaches the wire exactly like a configuration's own snippet. The
ceiling never saw it. :ref:`ADR-151 <adr-151>` documented that gap rather than
closing it, because widening enforcement is not a readout's decision — issue
`#731` is the decision it deferred.

The gap's severity was small and stating it precisely matters. The playground is
admin-only, so this was never a privilege escalation: it was an administrator
able to send, for one run, context the configuration's trust zone would refuse.
Every forced source still passed the mandatory input guardrail and secret
redaction (:ref:`ADR-087 <adr-087>`).

Decision
========

**A forced source binds against the same ceiling as a configuration's own.**

The ceiling is a statement about where text may travel, not about who may ask.
A caller choosing to attach a snippet does not make the destination more
trustworthy, and the class was declared on the snippet record as standing
policy. Treating an ad-hoc tick as a waiver of that policy would make the
declaration advisory — and an advisory ceiling is the "declaration nothing
reads" this codebase removes elsewhere.

"The playground is admin-only" is an argument about *authority*, and the ceiling
is not an authority control. The tool gate (:ref:`ADR-094 <adr-094>`) already
applies to administrators for the same reason: it answers where data may go, and
that answer does not change with the seniority of the person asking.

**The gate folds one source list.** :php:`decide()` now folds
:php:`InputContextClassifier::sources($configuration, $forcedSnippets,
$forcedSkills)` instead of calling :php:`classify()`. With an empty forced set
the two are the same fold, so a caller that injects nothing gets exactly the
pre-ADR-164 answer. It is also the list the :ref:`ADR-151 <adr-151>` readout
folds, so the panel and the ceiling can no longer disagree about what a run
carries — the asymmetry ADR-151 documented is gone rather than merely explained.

:php:`classify()` keeps its narrower meaning: what this *configuration* carries,
independent of any one run. That is a real question with real callers, and
folding a run into it would leave no way to ask it.

**The refusal names whichever source set the class.** The strictest declaration
wins and carries its own source name, so a refusal reads
``snippet "incident-report"`` when the forced source is the reason and the
configuration's identifier when it is not. Without that an operator cannot tell
which of the two halves to edit — they live in different places.

**The forced set travels as a domain value object.** :php:`InjectedContext`
carries the two lists;
:php:`\\Netresearch\\NrLlm\\Service\\Tool\\RunAugmentation::injectedContext()`
converts. The architecture rules forbid :php:`LlmServiceManager` from depending
on :php:`Service\\Tool`, and that rule is right — the manager has no business
knowing the tool loop exists. The value object is what both may name.

The two configuration-driven entry points that a run's assembled messages pass
through, :php:`chatWithConfiguration()` and
:php:`chatWithToolsForConfiguration()`, take it as a trailing optional
parameter. Both are :php:`@api`; the addition is additive and the frozen
surface records it.

.. _adr-164-not:

What this does not do
=====================

**It does not gate a dry run.** A dry run assembles the messages and returns
without calling a provider, so nothing leaves the installation and there is no
send to judge. :php:`RunAugmentation::injectedContext()` deliberately drops the
flag rather than carrying it into a decision it has no part in.

**It does not classify the task input.** ADR-144's second argument is untouched:
the accepted input is whatever the caller passed this second, with no per-record
home a declaration could live in.

**It does not cover a resumed run.** :php:`ToolLoopService::resume()` and
:php:`resumeWithInput()` re-enter the loop with assembly skipped, and the
suspended-run state does not persist the forced set — so the resumed send
carries the forced text in its transcript with no forced set for the gate to
read. The bound is narrow: the forced text entered that transcript at assembly
time, where this ADR *does* gate it, so nothing new is injected on resume. What
a resume can miss is a ceiling that changed *while* the run was suspended — the
configuration re-pointed at a less trusted provider, or enforcement switched
from observe to enforce. Closing that needs the forced set persisted into the
suspended state, which is issue `#761`.

Consequences
============

An installation that has classified nothing is unaffected: an undeclared source
still places no constraint, in the forced set exactly as everywhere else.

An installation that has classified snippets may see a playground run refused
that previously went through. That is the intended change, and the refusal names
the forced snippet so the operator can see which tick caused it. Observe mode
(``dataClassEnforcement``) shows the same decision without throwing, which is
the way to measure the effect before switching enforcement on.
