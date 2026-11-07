.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

======================
Authoring local skills
======================

.. _administration-skills-backend:

Writing skills in the backend
=============================

A source of the type :guilabel:`Written in the backend` holds skills that are
authored in TYPO3 instead of fetched from GitHub (:ref:`ADR-214 <adr-214>`).

1. Create a skill source and choose the type :guilabel:`Written in the
   backend`. Set its trust level: it decides whether its skills are admitted
   (``skills.minTrustLevel``) and whether an approved version may instruct
   (``skills.instructionTrustLevel``).
2. In the :guilabel:`Sources` table, click :guilabel:`New skill` on that
   source. Write the name, an identifier unique within the source, the
   description and the body. ``Allowed tools`` takes a JSON list of tool
   names; leave it empty for no declaration, ``[]`` allows no tool.
   :guilabel:`Works on a page` is the ``page`` key of a synced skill
   (:ref:`administration-skills-page`).
3. Enable the skill and attach it like any other. Approve a version as
   described below to make it an instruction.

Skills written here are edited through FormEngine and the DataHandler, so
every change is in the record history with a diff and a rollback. Every edit
changes the version: an approved version stops instructing at the next run
until the edited version is approved. Until a version is approved the skill
grants no tools — it counts as a declared empty list — and afterwards it grants
the tools of the approved version it holds, or of its most recent approved
version while it holds an unapproved one; never the ones in the field.
Every unrevoked approval keeps vouching: an author who restores the fields of
an older approved version gets that version back, its tools included. Revoke
an older version when a newer one is meant to replace it.

A skill attached to a configuration or a task keeps restricting the tools of
its runs even when it stops instructing: an orphaned skill — one whose
identifier the sync no longer finds — grants no tools and still restricts, as
does a skill whose source is gone. A skill that is still attached cannot be
deleted; detach it on the configuration or the task first.

Only an administrator enables or disables a skill. A skill the sync disabled
— its version changed, the scanner found an injection, or it was orphaned —
keeps restricting the runs it is attached to until an administrator enables
it again or detaches it; a skill an administrator disabled drops out of those
runs.
Hiding a skill takes it out of every run like an administrator's disable: a
hidden skill neither instructs nor restricts. Like the enable flag, hiding
and unhiding an existing skill is an administrator's, even where a group is
granted the field. A new skill, a copy included, is stored disabled unless
an administrator enables it, and such a skill restricts the runs it gets
attached to — a copied page's configuration, for example — until an
administrator enables it; a new skill an administrator stores with the
administrator's mark — a copy of a skill an administrator disabled — stays
out.

A workspace publish request that would delete a page holding an attached
skill, on it or below it, is refused as a whole: no workspace action in that
request is applied. "Publish all" works in batches, so batches before the
refused one are published. The single publish and "publish all" show the
refusal; publishing or sending a selection to a stage, and the scheduled
auto-publish, write it to the system log only — a staged delete over an
attached skill then holds back every auto-publish of its workspace until the
skill is detached or the delete discarded.

A backend user who is not an administrator writes skills only with the
fields an administrator granted: the source, the allowed tools and the data
class are excluded fields (:guilabel:`Allowed excludefields` of the backend
group). Without the source grant a new skill is stored without a source and
is never composed. The enable flag is an administrator's, even when a group
is granted it: a new skill stays disabled until an administrator enables
it. The :guilabel:`New skill`
button creates the record on the root page, which only administrators may
write; a backend user who is not an administrator creates the skill with
:guilabel:`Create new record` on a page in their web mount.

A skill does not change its kind by changing its source: a skill that a sync
wrote keeps being checked against what the sync wrote, also on a backend
source, so an edit after such a move takes it out instead of becoming a new
version.

The same rule protects every skill's tool declaration: a skill whose source
is missing, hidden or disabled, and a synced skill edited after its sync count
as a declared empty list. They restrict a run's tools instead of widening them.
A process skill counts only in a run that invokes it; attached without an
invocation it adds neither tools nor a restriction.

A backend source cannot be synced.
