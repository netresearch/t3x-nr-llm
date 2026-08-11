.. _adr-148:

=====================================================================
ADR-148: The routing readout is a second gate on the Governance tab
=====================================================================

:Status: Accepted
:Date: 2026-08-11
:Amends: :ref:`ADR-145 <adr-145>` (the simulator gains the second gate it named)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-142 <adr-142>` made an automatic model selection explainable:
:php:`RoutingDecisionService::decide()` returns a :php:`RoutingDecision` that
carries the selected model, every ranked candidate with its score and signals,
and every refused candidate with its reason.

Nothing read it. The decision was produced on every criteria-mode call and
thrown away except for `->selected`, so the question an operator actually asks —
"why this model and not that one" — had no answer anywhere in the backend.

:ref:`ADR-145 <adr-145>` built the shape for answering such a question: a
read-only surface that calls the runtime's own gate and renders what it says. It
closed by naming the routing decision as a second gate that was "answerable the
same way and not wired in yet". This record wires it in.

Decision
========

**A section on the Governance tab, not a tab of its own.** ADR-145 already
established that page as the place an operator asks "which rule applies here,
and what would it say about this call". A second tab would need its own module
route, its own doc-header entry and its own template, and would split one
question — "why does this configuration behave like this" — across two pages.
The section is admin-only for the same reason the rest of the page is: the
module registration in ``Configuration/Backend/Modules.php`` is
``access: admin``.

**It calls the real decision point.**
:php:`ModelSelectionServiceInterface::explainRouting()` runs the same
:php:`RoutingDecisionService::decide()` the runtime runs. There is no second
ranking, no second eligibility check and no second reading of the enforcement
switch. A readout with its own copy of the rules would be worse than none,
because the two can disagree and only one of them runs.

**The readout lives on the selection service.** It could have been a separate
readout service in the shape of :php:`EffectivePolicyReadout`. It is not,
because :php:`ModelSelectionService` already owns all four predicates the answer
needs — the fixed-vs-criteria branch, the stored criteria, the
:php:`OperationCapabilityMap` lookup and the ``routing.operationCapabilityEnforcement``
switch — and a separate service would have had to own second copies of every
one. The rule that the operation capability joins the criteria only while
enforcement is on now exists once, in :php:`constrainedCriteria()`, read by both
the resolution and the readout.

**Fixed mode is reported as no decision.** A configuration that names its model
chose nothing: there are no candidates, no ranking, no policy mode and no
rejection reasons. :php:`RoutingReadout` therefore has two states, and every
field that describes a decision is ``null`` in the fixed one. Rendering a fixed
configuration as a decision with a single winning candidate would invent
reasoning the runtime never performed, and an operator would then debug criteria
that are not consulted.

**Trying a policy mode changes nothing.** :php:`decide()` gained an optional
:php:`?RoutingPolicyMode` argument. It is evaluated for that one call; the
install setting is neither read nor written, and the next call without it is
back to the configured mode. The alternative — writing the setting and reading
it back — is the apply path :ref:`ADR-140 <adr-140>` argued down, for the same
reason: writing extension configuration rewrites the whole merged array.

The narrowest widening that makes it reachable
----------------------------------------------

Three changes, and no more:

- :php:`RoutingDecisionService::decide()` takes an optional policy mode.
  Existing calls are unchanged.
- :php:`ModelSelectionServiceInterface` gains :php:`explainRouting()`, because a
  controller cannot reach a concrete ``@internal`` service's method through the
  interface it is wired against. :php:`decide()` deliberately stays OFF the
  interface: it takes a raw criteria array and knows nothing about the
  fixed-vs-criteria branch, so a controller calling it would be choosing which
  half of the rule to apply.
- Nothing changed in DI. :php:`RoutingDecisionService` stays private and
  ``@internal``; private services are injectable, and only a direct container
  fetch would have needed ``public: true``.

Both enums gained label keys
----------------------------

:php:`RoutingRejectionReason` and :php:`RoutingPolicyMode` had no labels,
because nothing rendered them. They follow
:php:`GovernanceProfile::getLabelKey()` — including its ``get…`` prefix, which
exists because Fluid reaches a method only through the get/is/has convention and
a plain ``labelKey()`` yields an empty translation key that throws at render
time.

``RoutingDecision::noCandidates()`` is deleted
----------------------------------------------

It was dead: :php:`decide()` never called it. Giving it a caller would have been
decorative — on the empty-catalogue path :php:`decide()` already constructs the
byte-identical value, so the named constructor was a synonym rather than a
distinction. The distinction is real, though, and it now has a reader at the
other end: :php:`RoutingReadout::isEmptyCatalogue()` separates "no active model
was even considered" from "every candidate was refused", because the two need
opposite fixes and the page says which one happened.

Consequences
============

✓ An operator can answer "why this model", "why not that one", "what would
economy mode pick" and "is the operation-capability switch actually enforcing" —
from the runtime's own decision, on the page that already answers the
governance questions.

✓ A fixed-mode configuration is answered honestly: nothing was decided, and the
page says so instead of manufacturing a one-candidate decision.

✓ The signals table distinguishes "no data" from a measured zero, which is the
distinction :php:`RoutingCandidate` carries and the one a Fluid conditional
would have destroyed. The flattening happens in the controller, as the dashboard
already does for its bar widths.

◐ The readout answers for the configuration and operation the operator picks,
under their own backend session. It does not simulate another user — routing has
no per-user axis today, so unlike the tool gate there is nothing a user picker
would change.

◐ Only the operations that :php:`OperationCapabilityMap` maps to a capability
are offered. The rest constrain nothing, and offering them would promise a
dimension the decision does not have. "No operation selected" is reported as
its own state rather than as an operation that requires nothing:
:php:`RoutingReadout` carries whether one was named alongside the capability it
required, because a null capability has both causes and one sentence for the
two would describe an operation the operator never chose.

✕ No apply path, and no way to persist a tried policy mode from this page.
ADR-140's reasoning is untouched.

Revisit when
============

Routing gains a per-actor or per-site axis, or the decision becomes worth
persisting per call rather than recomputing on demand.
