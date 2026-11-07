.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-242:

====================================================================
ADR-242: A provider or a task tightens the privacy level
====================================================================

:Status: Accepted (run-provider resolution and ceilings amended by
    :ref:`ADR-270 <adr-270>`)
:Date: 2026-11-02
:Amends: :ref:`ADR-064 <adr-064>` (the level was global)
:Amended: 2026-11-07 by :ref:`ADR-270 <adr-270>` (actual-provider run policy and
    durable audit/answer ceilings)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-064 <adr-064>` has one level for the installation. An installation with
a provider it trusts and one it does not has to choose the lower level for both,
or store more of the second than it wants.

Decision
========

**A provider and a task can name a level, and it can only tighten.** Both
records get a ``privacy_level`` field, empty by default. The level a content is
stored at is the strictest of the global level, the level of the provider and,
for a queued run of a task, the level of the task, through
:php:`PrivacyLevel::strictest()`. A value looser than the global level does
nothing, so editing a record cannot open what an administrator closed. The field
offers no ``full`` for that reason.

**Where it applies.** Three stores keep content, and :php:`RunPrivacyLevelResolver`
answers for each:

- the events of a run, through :php:`RunStepPrivacyFilter`;
- the answer a queued run keeps, in :php:`QueuedRunCoordinator`;
- evaluation details, where the result names its generator's provider.

The audit rows of :php:`SkillAuditRepository` keep the global level: they belong to
a skill, and no provider is involved. A segment resolves the level of its run once.

**The provider of a run** is the one its configuration's model names. A level that
cannot be read is ``none``, because the question is what may be stored.

Consequences
============

- ●● An installation can keep run content for a local model and none for an
  external one.
- ◑ A configuration in criteria mode names no model, so only the global level and
  the task apply to it. Every preset of a Use Case Pack is one.
- ◑ A fallback that serves a call on another provider does not change the level of
  the run.
- ◑ A task at ``none`` or ``metadata`` keeps no answer, so a queued result cannot be
  read. The global ``metadata`` keeps it (:ref:`ADR-234 <adr-234>`), and a level
  tightened to ``metadata`` does not, because the field says no content.
- ◑ A copy made by an editor without access to the field follows the global level.
- ◑ Evaluation details of a run that does not name its generator stay at the
  global level.
- ◑ :guilabel:`Governance` shows the global level.

Net Score: +2

Alternatives considered
=======================

**A level that can also loosen.** Rejected: it would make an edit of a provider
record a privacy decision the global setting no longer bounds.
