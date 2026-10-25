.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-229:

======================================================================
ADR-229: A skill declares whether it needs a page, outside its version
======================================================================

:Status: Accepted
:Date: 2026-10-25
:Amends: :ref:`ADR-214 <adr-214>` (item 1: the fields of a version; item 3:
    the fields of a backend-authored skill)

Context
=======

A consumer of skills, such as the backend chat of nr_mcp_agent, has to decide
whether a conversation with a skill needs a page: whether it asks for one,
keeps the one it started on, or ignores the page the editor happens to show.
The skill record carries nothing that says so. The chat derives it from the
process marker of :ref:`ADR-214 <adr-214-d6>` item 6 — a process works on a
page, every other skill may — and from a per-installation suffix in its own
extension settings. A skill that works on a page without being a process, or
a process that works on no page, cannot say so.

Item 1 of :ref:`ADR-214 <adr-214-d1>` puts into the version digest every
frontmatter field that "composition, the catalogue or the tool allow-list
reads", and the approval snapshot of item 2 holds exactly those fields. A
field that a consumer's catalogue reads would therefore join the digest by
that rule. Doing so would give every stored skill a new version: every
enabled synced skill would be disabled on its next sync, and every approval
on an installed site would stop matching.

Decision
========

**A frontmatter key** ``page`` **declares whether the skill works on one
page.** Its values are ``required`` (the skill works on one page and needs
it), ``optional`` (it may work on a page) and ``none`` (it does not work on a
page). The value is read case-insensitively after trimming. An absent key, a
non-string value and any other string are "not declared".

**Not declared keeps today's behaviour, and that default belongs to the
consumer.** The documented meaning of an undeclared skill is: a process skill
behaves as ``required``, every other skill as ``optional``. nr_llm itself
reads the field nowhere. It exposes the declaration as it is, so a consumer
can still tell "declared ``required``" from "a process that declares
nothing" — nr_mcp_agent needs exactly that distinction for its quick tasks
(its ADR-025).

**The field is not part of the version.** It is user-interface metadata: it
decides whether a page is asked for, not what the model reads as instruction,
which tools a run gets or whether a version may instruct. It therefore stays
out of :php:`SkillVersionDigest` (format version 1 is unchanged), out of the
approval snapshot (:php:`SkillApprovalRepository`) and out of the
version-digest upgrade wizard. A sync that brings only a changed ``page``
updates the stored value and leaves the skill enabled and its approvals
matching. This is a deliberate exception to the "every field the catalogue
reads" rule of item 1: that rule protects the trust decision, and this field
takes part in none.

**Storage.** A column ``tx_nrllm_skill.page`` (``varchar(16)``, default ``''``)
holds the declared value; ``''`` is "not declared". The sync writes it from
the frontmatter on every create and update, as it writes the process marker.
FormEngine shows it as a select (not declared / required / optional / none),
read-only for the synced source types and editable for a backend-authored
skill (item 3), as the process marker is. The field is not ``exclude``: it
grants nothing, so a group that may edit a backend skill may set it.
Existing rows get ``''`` and therefore keep today's behaviour; the next sync
fills the column from the frontmatter. No upgrade wizard is needed.

**Exposure.** :php:`Skill::getPageNeed()` (``@api``) returns the declared
value as the new ``@api`` enum :php:`SkillPageNeed`, or ``null`` when the skill
declares none; :php:`Skill::getPage()` returns the stored string. The skill
record is what nr_mcp_agent reads today — through the configuration's and
the Task's skill relations — so the getter is the catalogue it consumes.
:php:`ProcessSkillCatalogue` and its AJAX route are not extended: no consumer
reads a page need from them.

What is not done
================

- No ``page`` in the version digest, in an approval snapshot or in the
  approval form's diff.
- No default resolution in nr_llm: no getter returns an "effective" value
  derived from the process marker.
- No validation error for an unknown value: it is "not declared", so a typo
  keeps today's behaviour instead of failing the sync.

Consequences
============

✓ A skill author states the page need once, in the skill, for every
consumer and every installation.

✓ Existing approvals and enabled skills are untouched by the new field, on
upgrade and when an author later adds ``page`` to a skill.

✕ One new column: run the database analyzer after upgrading.

✕ An edit of ``page`` on a synced row through a DataHandler write is not
caught by the integrity check, unlike an edit of a version field. The next
sync overwrites it; it decides nothing a trust check protects.

✕ A frontmatter key the consumer reads is outside the version, so an
approver does not see a change of it in the approval form.

Revisit when
============

A consumer starts to derive a trust or tool decision from ``page``, or a
second user-interface field of this kind is proposed — then the two belong to
a named group of unversioned metadata rather than to single columns.
