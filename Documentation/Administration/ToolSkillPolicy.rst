.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=================
Skill tool grants
=================

.. _administration-tools-allowed:

Gating tools with ``allowed-tools`` in a skill
==============================================

A skill's ``SKILL.md`` front-matter may carry an ``allowed-tools`` key that
gates which tools the skills attached to a configuration (or task) grant for a
run. The resolution is **fail-closed on declaration**, computed over the
configuration's *effective* skills (enabled, non-orphaned, at or above the
instance trust floor, deduped):

- **Absent** (no skill declares ``allowed-tools``) — no opinion; all
  registered tools are offered.
- **Declared list** — the **union** of the declared lists across the effective
  skills; only those tools are offered (intersected with what is actually
  registered, so an unknown name is dropped).
- **Declared empty** (``allowed-tools: []``) — declares **zero** tools; if no
  other effective skill widens the set, the run gets no tools and is a single
  plain completion.

A disabled or orphaned skill never grants tools. The allow-list is enforced
both when the tools are offered to the model and again when a tool call is
executed, so a prompt injection cannot reach a tool the skills did not grant.

The list is resolved **once, when the run starts**, over the configuration's
skills and the skills forced on the run (the playground's skill selection, or
a queued run's), and stored with the run; a queued run resolves it when it is
enqueued. A run that waits in the queue, for an approval or for input is
continued against that list intersected with the list resolved from the
skills as they are then: a change while the run waits can take tools away,
never add one. Disabling the only declaring skill therefore does not
open a waiting run to every tool. A run queued or suspended by an earlier
version has no stored list and continues with the list as it is then.

The effective set is *not* the same as what ends up in the prompt. The
skill-block byte budget (``skills.maxBytes``, see
:ref:`Managing skills <administration-skills-isolation>`) is applied after the
allow-list has been computed, while the block is assembled. A skill dropped
for the budget therefore still contributes its ``allowed-tools``, but its
usage rules never reach the model. That order is deliberate: a budget-aware
union would let the drop of the last *declaring* skill collapse the allow-list
to "no restriction" — every registered tool — which is a looser gate, not a
tighter one. Keep ``skills.maxBytes`` above the composed size of the skills
you rely on if you want their prose to arrive alongside the tools it
describes; every drop is logged as a warning.

See :ref:`ADR-038 <adr-038>` for the runtime design and security rationale.
