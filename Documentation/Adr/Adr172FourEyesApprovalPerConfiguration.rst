.. include:: /Includes.rst.txt

.. _adr-172:

============================================================================
ADR-172: Four-eyes approval is a per-configuration switch, default off
============================================================================

:Status: Accepted
:Date: 2026-08-14
:Authors: Netresearch DTT GmbH

.. _adr-172-context:

Context
=======

:ref:`ADR-083 <adr-083>` authorises an action on an agent run as owner-or-admin,
and :ref:`ADR-130 <adr-130>` adds the ``AGENT_APPROVE`` grant so an approver can
decide runs that are not their own. Nothing in either excludes the actor who
*started* the run. The person who triggers a write-tool call can therefore
release it themselves.

For a single-operator install that is the intended convenience, and it is what
every existing record does today. It stops being convenience where several
people share a backend login, or where the approval is the audit control rather
than a confirmation dialog: the fence then records that somebody approved,
without recording that anybody else looked.

Found during the demo test of the write path (Jira NEXT-133). The mechanism
worked — the write landed live and correctly paused. The question was only who
may release it.

.. _adr-172-decision:

Decision
========

A per-configuration switch, ``require_second_approver``, default ``0``.

When it is set, :php:`ResumeCoordinator::approve()` refuses an approval from the
backend user the run records as its initiator. The refusal happens after the
configuration is loaded and **before** anything is claimed, so the run stays
``WAITING_FOR_APPROVAL`` and a colleague can still decide it.

Three boundaries make the rule predictable:

**An approval only, never a denial.** The initiator may still deny their own
run. This is the same reasoning that lets a denial pass gates 2 and 3 in
``approve()``: those controls exist to stop a *write* from executing, and a
denial never runs the pending call — it resumes the loop with the refusal so the
model learns of it. Refusing a denial would strand the turn while the person who
wants it gone is turned away.

**No exemption for administrators.** Being allowed to act on every run does not
make an administrator a second pair of eyes on their own request. An
administrator who did not start the run may approve it as before.

**Service accounts are unaffected.** ``beUser`` identifies a backend user, so a
run started by a service account records ``0`` and matches nobody. Four-eyes
constrains humans; a machine caller's authorisation stays with the scope
mechanism, which is where it already lives.

.. _adr-172-alternatives:

Why not install-wide
====================

An install-wide setting was the alternative. It was rejected because the two
populations that need four-eyes and the population that needs single-operator
convenience live on the same install: a configuration whose agent may write to
``pages`` is a different risk from one that only reads. Making the choice
per-configuration lets an operator raise the bar exactly where the writes are,
without turning every playground run into a two-person ceremony.

The switch is small enough that an install-wide default could be layered on
later without changing this record's semantics.

.. _adr-172-consequences:

Consequences
============

A refused approval surfaces the way every other approval refusal in this module
does — a typed exception caught in
:php:`AgentRunController`, rendered as a flash message
(``runs.error.selfApproval``). That is deliberate rather than a UI gap: the same
pattern already carries ``StaleApprovalTurnException`` and
``ApproverNotPermittedException``, both of which can only be known at decision
time. Hiding the button instead would require the inbox factory to resolve every
run's configuration on every render, and would still have to keep the
server-side refusal.

An install that turns the switch on without having a second person with either
admin rights or the ``AGENT_APPROVE`` grant will strand its runs at the fence.
That is the setting doing what it says; the field description states the
requirement.
