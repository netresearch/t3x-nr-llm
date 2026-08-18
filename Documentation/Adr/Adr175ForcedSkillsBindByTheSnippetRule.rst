.. _adr-175:

==================================================================
ADR-175: A forced skill binds by the same rule as a forced snippet
==================================================================

:Status: Accepted
:Date: 2026-08-18
:Amends: :ref:`ADR-166 <adr-166>` (whose "the skill half was already correct"
    held for the resume path it was judging and not for the two composition
    paths it did not look at)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-166 <adr-166>` split the snippet lookup in two.
:php:`PromptSnippetRepository::findByUids()` stayed active-only — "the lookup
for a prompt being assembled *now*, and a snippet an operator switched off must
not enter one" — and :php:`findExistingByUids()` was added for text that is
already in a transcript, so deactivating a snippet mid-run cannot quietly lower
the :ref:`ADR-164 <adr-164>` ceiling of content still going out.

That record then said the skill half needed no counterpart, because
:php:`ToolLoopService::augmentationFrom()` filtered :php:`findAll()` by uid
alone and so ignored ``enabled`` on both sides. **That was true, and it was
about one path.** Three places rebuild a forced skill set from persisted uids,
and only that one is a resume:

- :php:`ToolPlaygroundController::resolveForcedSkills()` — a synchronous send,
  composing now;
- :php:`AgentRunRequestCodec::skillsByUids()` — a queued run being dequeued,
  composing now;
- :php:`ToolLoopService::augmentationFrom()` — a resume, re-gating text already
  sent.

On the first two, snippets went through the active-only lookup and skills did
not. So a forced **snippet** switched off between enqueue and start was gone
when the run began, while a forced **skill** disabled in the identical
situation survived (issue `#781`). The run then did not do what the person who
queued it asked for.

The three copies also disagreed about order. The two composition copies iterate
the persisted uid list. The resume copy iterated :php:`findAll()` and therefore
returned :php:`SkillRepository::$defaultOrderings`, ``name ASC``. Order is not
cosmetic:
:php:`InputContextClassification::withStricter()` keeps the *later* source on an
equal data class, and it is that source's name the refusal message and the
governance row carry. A run started with two equally-classified skills
therefore blamed one before it suspended and the other after it resumed —
same ceiling, same outcome, different name in the audit (issue `#777`).

Decision
========

**Skills get the pair snippets already have.**
:php:`SkillRepository::findByUids()` resolves enabled skills only;
:php:`findExistingByUids()` drops the ``enabled`` clause and nothing else. Both
preserve the caller's order and both keep the deleted restriction, so a deleted
record still resolves to nothing either way.

**The two composition paths use the enabled-only lookup.** ADR-166's own words
decide this rather than a new principle: a source an operator switched off must
not enter a prompt being assembled now, and "a fresh run that forces it gets it
through the active-only lookup like any other". A queued run has composed
nothing at enqueue time; dequeuing it *is* that assembly.

**The resume path uses the existence lookup**, which keeps ADR-166's resume
semantics exactly as written.

**One ordering rule: the caller's uid order, on all three paths.** The order a
run was started with is the order every later lookup reproduces, so the fold
names the same source at every point in that run's life.

What this corrects
==================

:php:`AgentRunRequestCodec::skillsByUids()` carried a docblock stating that
forcing a skill overrides its global toggle, "the same semantics the
playground's force-inject control has". No record decided that, and the
playground does not offer it: :php:`availableSkills()` lists enabled skills
only, so a disabled skill can reach the forced set only from a stale form or a
hand-built request body. The sentence described neither an intended rule nor
the behaviour it claimed to copy, and it is replaced rather than kept.

What this does not do
=====================

**It does not change what the ceiling reads.** The forced set is the set
:ref:`ADR-164 <adr-164>` defined; this changes which rows resolve on which
path, not which rows are asked for.

**It does not make a disabled skill usable.** Nothing composes it, and the
picker does not offer it.

**It does not surface the drop.** A forced source that disappears before a run
starts is silent — now uniformly, where before it was silent for snippets and
absent for skills. That is issue `#809`, split out deliberately: making it
visible needs a place to show it and a decision about whether a queued run
should refuse instead, and neither follows from this one.

**It is not an API change.** :php:`SkillRepository` is ``@internal``
(:ref:`ADR-127 <adr-127>`), so the two new methods change no frozen surface.

Consequences
============

A run queued with a skill that is disabled before it starts runs without that
skill, as it already did for a snippet.

The resume path returns uid order where it returned name order. On a
classification tie the source named by a refusal can differ from what the old
code would have named — which is the defect this closes, not a new one: the
name is now the same before and after the suspension.

An installation that forces nothing, or that has classified nothing, is
unaffected.
