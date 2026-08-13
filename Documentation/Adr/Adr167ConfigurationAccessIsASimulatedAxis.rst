.. _adr-167:

============================================================
ADR-167: Configuration access is a simulated axis
============================================================

:Status: Accepted
:Date: 2026-08-13
:Amends: :ref:`ADR-157 <adr-157>` (its "Revisit when" clause fired: a fifth,
    actor-scoped axis)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-157 <adr-157>` built the Governance tab's simulator on four axes and
named, as a consequence rather than a defect, the one it did not ask:
configuration access. :php:`ConfigurationResolver` refuses a configuration whose
backend groups the acting user is not a member of; the tab's configuration
picker lists every active configuration and filters nothing. A group-restricted
configuration paired with a non-member therefore read :guilabel:`Allowed` on a
page whose entire purpose is that an operator does not have to guess, and was
refused at runtime.

That is a false positive, and it is the worst kind the page can produce: the
picker makes the pairing easy to build, and the readout answered with the one
verdict an operator would act on without checking.

The rule was reachable only through
:php:`ConfigurationResolver::getActiveByIdentifierForActor()`, which re-queries
by identifier and throws — and throws one of three types, so "refused" would
have had to be told apart from "not found" and "inactive" by catching. The
predicate itself was private.

Decision
========

**Configuration access is the fifth axis, and it folds into the verdict.** A
configuration the actor may not use makes the verdict ``BLOCK``, like every
other refusing axis. No fourth verdict value: the fold's meaning is unchanged —
any refusal blocks — and a separate outcome would have implied a difference in
what the operator has to do, when the row already names it.

The row carries ``Yes`` in the scope column, which is the widening
:ref:`ADR-157 <adr-157>` predicted. Two of five axes now read the actor: the
tool gate through ``requiresAdmin()``, and this one through the user's backend
groups.

**The rule is promoted, not copied.**
:php:`ConfigurationResolver::actorMayUse()` becomes public and stays the only
implementation; :php:`getActiveByIdentifierForActor()` calls it and turns
``false`` into its :php:`AccessDeniedException` exactly as before. Its eight
existing branch tests are the characterization and are unchanged. The rule
:ref:`ADR-070 <adr-070>` states is untouched — what changes is that it can now
be asked as well as enforced.

The simulator takes the entity it already holds straight to the predicate rather
than routing through the throwing entry point. Re-querying by identifier to
answer a question about an object in hand, then sorting three exception types to
learn which of them meant "refused", is machinery in service of not adding a
method. And a second group comparison written in the simulator would be the
fourth copy of this rule — the copy :php:`EditorActionCatalogue` already warns
about in the comment above its own call.

**The actor is built from the RESOLVED backend user.** This is the trap the
change had to avoid, and it is not hypothetical: the simulator already
constructs :php:`AiActorContext::backendUser($actorUid)` one line earlier, as
the *argument* to :php:`ActingBackendUserResolverInterface::resolve()`. That
context carries the constructor defaults ``isAdmin=false`` and
``backendGroupIds=[]``, which is correct there — the resolver answers with the
real record — and would be a lie if handed to an access rule. Every restricted
configuration would read :guilabel:`Refused` for every actor, administrators
included: a false negative in place of a false positive, and one that a test
suite asking only "is the non-member refused" would not notice.

:php:`ToolExecutionContext::fromBackendUser()` is the existing seam that turns a
live :php:`BackendUserAuthentication` into a context — uid, admin flag, group
uids — so the extraction is not written a second time here either.

**An unresolved actor is refused a restricted configuration.** A uid that no
longer resolves yields :php:`AiActorContext::anonymous()`, which belongs to no
group and holds no scope, so the rule refuses a restricted configuration and
permits an unrestricted one. That is the same fail-closed treatment the other
axes give the case: the gates are asked with no user, never with the operator's
rights.

.. _adr-167-not:

What this does not do
=====================

**It does not add a budget row, and that is a decision rather than an
omission.** Budget is the remaining pairing an operator might expect here, and
it cannot be reported honestly today. :php:`BudgetCheckResult::allowed()` carries
``currentUsage`` and ``limit`` as zero: the numbers exist only on the denial
path. A row would therefore be unable to distinguish "well under the limit",
"one call away from it" and "nothing measured", and printing one answer for
three states is a fabricated measurement on a page whose value is that it does
not guess. Whether the budget service should expose a headroom readout is a
separate decision, taken separately.

**It does not filter the configuration picker.** The picker still lists every
active configuration. Filtering it would answer the question by hiding it, and
an operator asking "why can this editor not use this configuration" needs the
pairing to be selectable in order to be told.

**It does not touch the guardrail pipeline**, which decides on the text of a
prompt the picker does not supply, and it changes nothing about the audit:
:ref:`ADR-157's audit decision <adr-157-audit>` stands, and a simulation still
writes nothing.

Consequences
============

A group-restricted configuration paired with a non-member now reads
``Blocked`` and names the restriction as the reason. The page no longer reports
an ``Allowed`` that the runtime refuses.

The scope column is wider: an operator reading it sees that two answers change
with the picked user, not one. A readout that answered identically for every
actor on four of five axes without saying so would imply a dimension that is not
there.

:php:`ConfigurationResolver::actorMayUse()` is public. The class is not
``@api``, so the frozen surface in ``Tests/Unit/Api/api-surface.txt`` is
unchanged; the method is nevertheless a supported way to ask the rule without
triggering it, which is what a readout needs.
