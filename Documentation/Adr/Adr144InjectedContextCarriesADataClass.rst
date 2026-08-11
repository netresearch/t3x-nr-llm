.. _adr-144:

==========================================================
ADR-144: Injected context carries a declared data class
==========================================================

:Status: Accepted (the criteria-mode zone now comes from the resolved model —
    see :ref:`ADR-149 <adr-149>`; the system prompt is classified after all —
    see :ref:`ADR-155 <adr-155>`)
:Date: 2026-08-10
:Amends: :ref:`ADR-094 <adr-094>` (the axis now binds in both directions)
:Amended: 2026-08-11 by :ref:`ADR-149 <adr-149>`, and 2026-08-11 by
    :ref:`ADR-155 <adr-155>`
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-094 <adr-094>` classifies what a tool RETURNS and refuses to offer a
tool whose class exceeds the trust zone a run can reach. Nothing classified what
a run SENDS. A configuration in the least-trusted zone could receive any snippet
and any skill, and an operator had no way to say "this one must not leave this
zone". That is issue `#689`.

The issue is careful about what this is not: all of that content passes the
mandatory input-guardrail screener (:ref:`ADR-087 <adr-087>`), and secret
redaction is not selectable away. What was missing is the *declaration* — a
per-source ceiling — not every check.

Decision
========

**The same scale, reused.** Snippets and skills carry a
:php:`ToolDataClass` value, the enum tool output already uses. A parallel enum
with identical cases would be the duplication the module seams exist to prevent,
and :php:`TrustZone::permits()` already answers the comparison.

**One switch for one question.** The gate reads
``tools.dataClassEnforcement`` — the ADR-113 switch — rather than adding a
sibling. The question is the same: does a declared data class bind against a
provider's trust zone. What differs is direction: ADR-094 asks it about what a
tool may READ for a run, this asks it about what the run may SEND.

**Undeclared is not a class.** An empty value means no statement was made, and a
source that made no statement places no constraint. This is what makes the axis
safe to ship enforcing: an installation that has classified nothing behaves
exactly as before. The migration risk was never in the switch — it is in
guessing a value for data that already flows, and nothing here guesses.

Note the deliberate asymmetry with :ref:`ADR-116 <adr-116>`: an undeclared MCP
server is INERT, an undeclared snippet is unconstrained. A remote server is new
capability an operator opts into and can be asked to classify first; snippets
have shipped for months, and refusing them the moment a column appeared would
break working installations for a value nobody was ever asked for.

**Two sources, and only two.** The snippets a configuration composes and the
skills attached to it. The system prompt and the task input are deliberately not
classified: neither has a per-record home for a declaration — a system prompt is
a field on the configuration that already knows its own provider, and task input
is whatever the caller passed this second. A column for them would be a
declaration with nowhere to live and no one to set it.

**Amended by** :ref:`ADR-155 <adr-155>` for the system prompt: that argument
holds for a fixed-mode configuration, whose provider is named on the record, and
not for a criteria-mode one, which knows no provider until routing runs. Once
ADR-149 made the zone follow the resolved model, a class on the system prompt
gained a consumer — it constrains which models the configuration may resolve to.
The task-input half stands unchanged.

**The strictest declaration decides**, because that is what the send carries.
One confidential snippet makes the whole prompt confidential regardless of what
accompanies it.

**The refusal names the source, never the text.** An operator told only
"forbidden" has to go looking. The message and the audit row carry the snippet
identifier or the skill name and the zone — and no content, because the content
being sensitive is the entire premise.

Where the resolvers had to move
-------------------------------

Building this hit `ModuleSeamTest::testCoreDoesNotDependOnTheToolModule`:
:php:`TrustZoneResolver` and :php:`DataClassEnforcementResolver` sat in the tool
namespace, where the tool gate — their first consumer — had put them.

Neither is about tools. A trust zone is a property of a provider; the
enforcement switch governs an axis. Core needs both to gate the send path, so
the misfiling became load-bearing rather than cosmetic. They moved to
:php:`Service\\Governance`, and the exception `EffectivePolicyReadout` held in
that rule — which existed only because of the same misfiling — disappeared with
them. The rule now passes with a SHORTER exception list than before.

Consequences
============

✓ An operator can declare "this snippet must not leave this trust zone", and the
declaration is enforced on every configuration-driven send.

✓ The refusal is explainable: which source, which zone, which class.

✓ The governance audit gains a `context_blocked` decision, separate from
`tool_denied` — collapsing them would make "which direction leaks" unanswerable.

✓ The module seam is cleaner than before, with one fewer exception.

◐ A criteria-mode configuration resolves to `EXTERNAL_GLOBAL`, because the zone
comes from the model's provider and a criteria-mode record has no model
relation. That is fail-closed and therefore the safe direction, but it means a
criteria-mode configuration that only ever selects local models is still treated
as external. Resolving the model first would make the gate depend on routing;
:ref:`ADR-142 <adr-142>` has just built the decision point that would make that
answerable, and it is the natural follow-up. **Amended by**
:ref:`ADR-149 <adr-149>`: the serving model is threaded in from the manager, so
a criteria-mode configuration now takes the zone of the model routing selected.
The fail-closed answer stays for the case where routing selects nothing.

◐ Two entities gained a column and a TCA field. `Skill` is `@api`, so its two
accessors extend the public surface.

✕ This classifies SOURCES, not content. A snippet declared PUBLIC_CONTENT that
in fact contains a credential is not caught here — that is the guardrail
screener's job, and it runs regardless.

Revisit when
============

A criteria-mode configuration needs its real zone rather than the fail-closed
one. The routing decision from ADR-142 is what would supply it. **Answered by**
:ref:`ADR-149 <adr-149>` for the configuration's own provider; the fallback
hops are still read from their own relations.

Also revisit if a consumer injects context through a path neither snippets nor
skills cover — that is the ADR-139 revisit trigger, unchanged.
