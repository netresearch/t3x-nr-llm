.. include:: /Includes.rst.txt

.. _adr-139:

============================================================================
ADR-139: Context assembly is a seam, not a provider registry
============================================================================

:Status: Accepted
:Date: 2026-08-09
:Authors: Netresearch DTT GmbH

.. _adr-139-context:

Context
=======

A programme proposal asked for a :php:`ContextProviderInterface` with
``supports()`` / ``provide()``, a DI-tagged registry, a :php:`ContextRequest`
carrying actor, site, language, page and requested types, a
:php:`ContextResult` carrying items, classification, source, freshness and a
token estimate, and two reference providers in the core.

The motivation is sound: consuming extensions want to feed editorial context —
brand voice, audience, legal instructions — into a request without nr_llm
knowing what any of that means.

The shape is not. Building it now would produce an interface with zero
implementers and zero readers, which is the pattern :ref:`adr-120` removed
once already: an argument that looked like enforcement, was read by nothing,
and bought false trust.

Four things about the code decided this.

**There is no vocabulary problem to solve.** Two mechanisms already carry
editorial context. Skills (:ref:`adr-035`) carry instructions. Snippets
(:ref:`adr-031`) carry fragments and are addressed by free-form tags that
:ref:`adr-031` deliberately keeps as a *convention* between editors and
consumers, not a core enum. A core-side vocabulary of context types — ``brand``,
``audience``, ``editorial`` — would move editorial semantics into a layer whose
whole point is not to have them.

**There is no entry point that could fill the request.** A
:php:`ContextRequest` with site, language and page presumes callers that carry
those. None does. The reference providers the proposal names
(``CurrentPageContextProvider``, ``CurrentSiteContextProvider``) would have to
find the page themselves — which is a generic read-everything accessor, a
standing non-goal.

**``freshness``, ``validUntil`` and ``source`` have no reader.** Nothing caches
context, nothing invalidates it, nothing displays where it came from.

**The budget problem is real, and it is not shaped like a registry.** The two
budgets that exist do not negotiate: the skill block is bounded in bytes by
:php:`SkillComposer`, the transcript is bounded in tokens by
:php:`ContextWindowManager`, and until recently the second did not know about
the first. That was a defect and was fixed as one. It did not need a budget
object with ``max provider count``; it needed the two existing budgets to meet
in the one calibrated estimator.

.. _adr-139-decision:

Decision
========

Context assembly stays a seam between the parts that already exist. No
:php:`ContextProviderInterface`, no registry, no context-type vocabulary in the
core.

Three consequences follow, and they are the whole decision.

**One estimator arbitrates.** Everything that reaches the wire is counted by
:php:`TranscriptEstimator` through :php:`ContextWindowManager::fit()`, including
payload that is not in the message list when the fit runs. That is why ``fit()``
takes tool specs and the injected skill block as parameters rather than growing
a budget object: the arbitration point is the estimator, and it is already
calibrated against real usage.

**The tag vocabulary stays consumer-owned.** :ref:`adr-031` is extended, not
superseded. A configuration selecting snippets by tag is the supported way to
attach editorial context, and the tags remain a convention documented for
editors — new fragment kinds still need no nr_llm release.

**Deferred, with named triggers.** Two pieces of the proposal are not rejected,
they are waiting for a reader — the same discipline :ref:`adr-122` applied to
the side-effecting tool contract.

.. _adr-139-deferred:

What is deferred, and what would trigger it
===========================================

Context provider registry
-------------------------

Revisit when a consuming extension has a context source that can be expressed
neither as a skill nor as a tagged snippet. At that point the interface is
designed against that source, not against a guess — and the trigger is a real
consumer, not a hypothetical one.

A data classification for injected context
------------------------------------------

Only tool *output* is classified today
(:php:`ToolDataClassResolver`), and the trust-zone ceiling is enforced in one
place: the tool gate. Skills, snippets, the system prompt and task input carry
no :php:`ToolDataClass` and are checked against no zone. A configuration in the
least-trusted zone can receive any of them.

This is deferred rather than ignored, and the reason is specific: all of that
content already passes the input-guardrail screener on the way out
(:ref:`adr-087`), and secret redaction is a mandatory guardrail a configuration
cannot select away. Screening runs on the assembled list, after the skill block
has been injected, so the block is screened too. What is missing is the
*classification* — a declared ceiling per source — not every check.

Revisit when a consumer injects content the screener does not cover, or when an
operator needs to declare that a particular snippet or skill source must not
leave a trust zone. Building it means an operator-declared column, a migration
in the :ref:`adr-115` shape (existing rows observe, new rows enforce) and a new
governance decision case — a fail-closed axis over existing data, which is not
something to add speculatively.

.. _adr-139-open:

What this does not close
========================

Named here so the next reader does not mistake the seam for full coverage.

:php:`LlmServiceManager` binds **no** context window at all. Its chat,
completion and streaming paths inject skills and send; only
:php:`ConversationService` and :php:`ToolLoopService` call
:php:`ContextWindowManager`. A long transcript sent through the generic API is
bounded by the provider, not by us.

The string-prompt completion path injects the skill block into a prompt string
rather than a message list, so it has no assembly order to reason about and no
window binding either.

Both are known and neither is addressed here. Closing them means giving the
generic paths a configuration to bind against, which is a different decision.

.. _adr-139-consequences:

Consequences
============

●● The proposal's largest piece is not built, and nothing is worse for it. The
capability it aimed at — a consumer attaching editorial context — already
exists through skills and tagged snippets.

● The budget question is answered where it lives. One estimator, one fit, and
payload outside the message list is passed to it rather than accounted for
separately.

◐ Consumers keep owning their vocabulary. A new fragment kind is an editorial
act, not a release.

✕ Two gaps stay open and are written down rather than closed: the generic paths
have no window binding, and injected context has no trust-zone ceiling. Both
have named triggers above.
