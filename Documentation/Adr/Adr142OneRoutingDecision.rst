.. _adr-142:

==============================================================
ADR-142: One routing decision, with a reason per candidate
==============================================================

:Status: Accepted (the trace deferral is settled; the complexity-routing deferral is measured but still open — see :ref:`ADR-156 <adr-156>`)
:Date: 2026-08-10
:Amends: :ref:`ADR-060 <adr-060>` (quality is no longer only a separate hook)
:Amended: 2026-08-11 by :ref:`ADR-156 <adr-156>`
:Authors: Netresearch DTT GmbH

Context
=======

Criteria-mode selection worked and had exactly one production path —
:php:`ModelSelectionService::resolveModel()`, reached through
:php:`ConfigurationCallPlanner`. What it could not do is say **why**.

:php:`modelMatchesCriteria()` returned a bool. A model that never appeared in a
call was indistinguishable from one that lost on cost, and an operator asking
"why is it not using the model I added" had nothing to read.

Two signals also sat outside the decision, for different reasons:

- :php:`QualityAwareModelSelector` (:ref:`ADR-060 <adr-060>`) is a documented
  opt-in hook whose own docblock calls first-class wiring a deliberate
  follow-up. Nothing in the core calls it.
- :php:`ProviderHealthService::reorder()` (:ref:`ADR-063 <adr-063>`) reorders
  the FALLBACK CHAIN. That is a different axis — which provider to try next
  after a failure — not which model to select.

So this is not a merge of competing routers. There is one path; it gains an
explanation, and two measurements it never consulted.

Decision
========

Discovery, then eligibility, then ranking — and the boundary between the last
two is the load-bearing part.

**Eligibility is a hard constraint with a reason.**
:php:`EligibilityEvaluator` answers "may this model serve this call" and returns
a :php:`RoutingRejectionReason` instead of `false`. It is the ONLY
implementation of that question: :php:`modelMatchesCriteria()` now delegates to
it rather than keeping a second copy of the predicates.

**Ranking never revisits eligibility.** A rejected candidate carries no score at
all — not a low one — so there is no number for it to come back with. This is
why :php:`RoutingCandidate` is either eligible-with-a-score or
rejected-with-a-reason and never both.

**The reasons have an order, and it matters.** The operator's own criteria are
evaluated first and the operation capability (:ref:`ADR-138 <adr-138>`) last.
A caller reads ``OPERATION_CAPABILITY_MISSING`` as "would have served, but not
this operation", and :php:`resolveModel()` raises a misconfiguration error on
it. Evaluated earlier, a model the criteria excluded anyway would report that
reason and the error would name a model nobody wanted.

**Measured signals are opt-in.** :php:`RoutingPolicyMode::PROVIDER_PRIORITY` is
the default and reproduces the previous ordering exactly. ``balanced``,
``quality`` and ``economy`` add quality and health. Opting in is deliberate:
these signals change which model serves a call, and an installation that never
asked must not get it from a version bump — the shape ``health.reorderFallback``
already uses.

**Provider priority outranks every measurement.** A priority is an operator's
instruction; a score is evidence. Evidence does not overrule an instruction, so
priority is a sort key ABOVE the score rather than a term inside it.

**Absent data is not bad data.** A model with no quality measurement contributes
nothing to the weighted mean rather than a zero, and a provider with no samples
in the window yields no health signal at all —
:php:`ProviderHealthScore::NEUTRAL_SCORE` already established that rule for the
fallback chain. The consequence that makes the default safe: with no data, every
candidate scores identically and the established tiebreaks decide.

Three named modes, not a weight panel
-------------------------------------

An operator can state an intent. They cannot reasonably calibrate four
coefficients against each other, and a backend full of sliders produces settings
nobody can explain six months later.

What this does NOT do
=====================

**No request-complexity routing.** The plan this work came from lists it, and it
is deliberately not built: routing on an estimated complexity score needs
evidence that the score predicts anything, and that evidence does not exist yet.
Measuring it first, deciding later, is the same shape :ref:`ADR-138 <adr-138>`
used for the operation-capability axis.

:ref:`ADR-156 <adr-156>` does the measuring, and still does not route: it names
the three things that must hold before anything may.

**No persisted decision trace, yet.** A trace whose only reader is a future
analytics view is a declaration nothing reads. The decision object exists and is
returned; persisting it belongs with the surface that displays it.

That surface exists — :ref:`ADR-148 <adr-148>` explains a hypothetical decision,
:ref:`ADR-156 <adr-156>` persists the real ones and reads them back on the same
page — so the trace is now written.

**No change to fixed mode.** Nothing is chosen there.

Consequences
============

✓ Every automatic selection can name the model it chose and the reason each
other active model was not chosen.

✓ Hard constraints cannot be overridden by a score, structurally rather than by
convention.

✓ The predicates exist once. `modelMatchesCriteria()` and the decision point
cannot drift apart, because they are the same code.

✓ The default behaviour is unchanged, and the whole existing test suite passing
untouched is the evidence.

◐ `QualityAwareModelSelector` still exists and still has no core consumer. It is
not deleted: it is documented public surface with its own semantics (a hard
`minQuality` FILTER, which the ranking deliberately does not have — a minimum
quality is a constraint, and constraints belong in eligibility). Whether it
should become one is a separate decision, and one this ADR makes easier rather
than answers.

◐ Collecting quality and health costs one lookup per candidate in the modes that
use them. The default mode collects nothing.

✕ The weights are judgement, not calibration. They are chosen to be defensible
and to keep the ordering stable, not because a measurement said 0.4 was right.

Revisit when
============

Enough real decisions have been traced to say whether the modes correspond to
anything operators actually want, or whether one of them is never chosen.

Also revisit when a `minQuality` floor is asked for: that is an eligibility
constraint with a rejection reason, not a ranking weight, and it is the natural
moment to fold `QualityAwareModelSelector` in.
