.. _adr-155:

============================================================
ADR-155: The system prompt carries a declared data class
============================================================

:Status: Accepted
:Date: 2026-08-11
:Amends: :ref:`ADR-144 <adr-144>` (which of the injected sources are classified)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-144 <adr-144>` classified two of the things a configuration-driven
send injects — the snippets it composes and the skills attached to it — and
declined two others in these words:

    The system prompt and the task input are deliberately not classified:
    neither has a per-record home for a declaration — a system prompt is a field
    on the configuration that already knows its own provider, and task input is
    whatever the caller passed this second.

The system prompt half of that reasoning was true of a FIXED-mode configuration
and only of one. Its provider is named on the record; a class declared beside
the prompt could not constrain anything the operator had not already decided by
choosing the model. A declaration nothing reads is worse than none, so it was
right to leave out.

A criteria-mode configuration knows no provider. The model is chosen at call
time, and until :ref:`ADR-149 <adr-149>` the gate could not even read the zone
of the one that was chosen. Now that it can, the missing declaration has a
consumer: a class on the system prompt says which models the configuration may
resolve to. That is issue `#724`.

Decision
========

**One column, the ADR-144 shape.** ``tx_nrllm_configuration`` gains
``system_prompt_data_class``, a :php:`ToolDataClass` value on the scale snippets,
skills and tool output already share. Empty means UNDECLARED and constrains
nothing, so every configuration that exists today keeps reaching every provider
it reached — the migration risk was never the switch, it is guessing a value for
data that already flows, and nothing here guesses.

**Task input stays unclassified.** ADR-144's second argument is untouched by any
of this. The accepted input is a runtime string the caller passed this second;
there is no record to declare on and no operator to declare it. ``tx_nrllm_task``
gains nothing.

**The class classifies the TEXT.** A configuration with an empty
``system_prompt`` declares nothing whatever the column says, because there is no
prompt to protect and a refusal would name a source the operator cannot find in
the form.

This is deliberately *not* the reading a snippet gets, and the asymmetry is
worth naming rather than glossing. A snippet that is selected but empty still
constrains: :php:`PromptSnippet::getDataClassEnum()` is a bare
:php:`ToolDataClass::tryFrom()` with no text check, and
:php:`ConfigurationSnippetResolver::selectedSnippets()` filters on hidden and
duplicate only. The two differ because selection differs. A snippet is selected
by a tag match — an operator who attached it meant it, and an empty one is a
snippet whose text has yet to be written, not a source that is absent. The
system prompt is a field on the record being sent: blank means the configuration
contributes no text of its own, and there is nothing for the class to describe.

The consequence is small and one-directional: an operator who classifies a
configuration and then blanks its prompt loses the constraint silently. Nothing
leaks — a blank prompt sends nothing — but the column keeps a value the gate no
longer reads.

Where it binds, and why not the other place
-------------------------------------------

**The input-context gate**, in the same fold as the other two sources.
:php:`InputContextClassifier::classify()` reads the configuration's declaration
alongside the snippets and the skills, the strictest still decides, and
:php:`InputContextTrustGate` refuses the send. No new gate, no new switch, no
second implementation of the question.

The honest alternative was **routing eligibility** — teaching
:php:`EligibilityEvaluator` to reject a model whose provider sits below the
declared class, so the configuration resolves to a permitted model instead of
being refused. It is a nicer outcome when it works. It is the wrong place, for
three reasons:

1. **It would invert the dependency ADR-149 just fixed.** That record states the
   invariant plainly: the zone follows routing, it must never drive it. A
   governance declaration that filters candidates makes a data class decide which
   model serves a call. The gate reading the zone off the chosen model, and the
   router choosing without consulting the gate, is one direction of dependency;
   binding at eligibility makes it a cycle.

2. **It would not cover fixed mode.**
   :php:`ModelSelectionService::resolveModel()` returns the named model without
   consulting :php:`EligibilityEvaluator` at all when the configuration is not in
   criteria mode. A declaration that binds only in criteria mode would be silently
   inert on the majority of records — the exact failure ADR-144 avoided by not
   shipping the column at all.

3. **It would be the second implementation.** The gate still runs afterwards and
   still asks whether the declared class fits the zone. Two places answering one
   question drift, and the drift is invisible until one of them permits what the
   other refuses.

The cost of choosing the gate is stated rather than hidden: a criteria-mode
configuration whose criteria match both a local and an external model may resolve
to the external one and then be refused, where an eligibility filter would have
picked the local one and succeeded. That is a worse outcome for the operator and
a correct one for the invariant — the refusal names the source and the zone, so
the fix (narrow the criteria, or lower the declaration) is in the message.

Consequences
============

✓ An operator can declare that a configuration's system prompt must not leave a
trust zone, and on a criteria-mode configuration a send that resolves to a model
in a weaker zone is refused.

✓ Nothing new to learn: same scale, same enforcement switch
(``tools.dataClassEnforcement``), same observe mode, same audit decision
(``context_blocked``), same rule that the refusal names the source and never the
text.

◐ :php:`LlmConfiguration` is ``@api``, so its three new accessors extend the
public surface. Additive only — no existing signature changes.

◐ A configuration that declares a class and later has its prompt cleared stops
constraining anything, with no warning. That follows from classifying the text
rather than the record, and the alternative — a refusal citing a field the
operator sees as empty — is worse.

✕ Still classifying SOURCES, not content. A system prompt declared
``publicContent`` that in fact carries a credential is not caught here; that is
the guardrail screener's job (:ref:`ADR-087 <adr-087>`) and it runs regardless.

✕ The fallback hops are unchanged, as in ADR-149: a fallback configuration
contributes the zone of its own relation, so the declaration is judged against a
chain that is still read the old way.

Revisit when
============

Per-call injected context needs classifying — context a caller hands the send
rather than anything a record declares. That is issue `#731` and a different
decision: it has no per-record home either, and whether an argument may carry a
declaration is the question ADR-144 answered "no" for task input.

Also revisit if routing gains a governance-aware pre-filter for some other
reason. The three arguments above are about adding one FOR this; if one arrives
anyway, the eligibility option becomes cheap and the trade above should be
re-read.
