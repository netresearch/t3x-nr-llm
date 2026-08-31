.. include:: /Includes.rst.txt

.. _adr-184:

============================================================================
ADR-184: An approval binds to the state the preview showed
============================================================================

:Status: Proposed
:Date: 2026-08-31
:Authors: Netresearch DTT GmbH

.. _adr-184-context:

Context
=======

:ref:`ADR-136 <adr-136>` decided the opposite of this record, deliberately and
with reasons, and named the one thing that would reopen it:

   "**Revisit when a writing tool writes a RELATIVE change.** An append, an
   increment, a 'remove the third paragraph' — for those, the 'before' is not
   decoration, it is an operand, and a snapshot stops being sufficient."

**That tool shipped ten days later.** ``attach_file_to_content_element``
(2026-08-21, `#834`) appends a file reference to a content element's ``image``,
``assets`` or ``media`` field. Its own preview says so in as many words::

    field image: 2 reference(s) → 3, appended last

The count is read at suspend. The write happens after the approval, against
whatever the list holds then. Both halves of that line — what the field
contained and where the new reference lands — are operands of the write, not
decoration on it. ADR-136's premise, "a writing tool here sets absolute
values", stopped being true for the whole set and nothing said so.

That is the trigger, and it is the reason this record exists rather than a
general preference for optimistic locking.

.. _adr-184-decision:

Decision
========

**A run that resumes writes against the state its approver was shown, or it
does not write.**

At suspend, alongside the preview lines :ref:`ADR-136 <adr-136>` already
persists, the loop stores what the preview was computed FROM: the identity of
the subject and a fingerprint of the values that were displayed. At resume,
before the approved call executes, the subject is re-read and the fingerprint
recomputed. Equal, and the call runs exactly as today. Different, and **no
mutation happens at all**: the run produces a distinct stale outcome, a fresh
preview is built from the current state, and the run suspends for approval
again.

**The fingerprint covers what was shown, not the record.** Not ``tstamp``: a
hook moves it without touching a relevant field, and a FAL or relation write is
not covered by a record timestamp at all. An edit to a field the preview never
displayed does not block — that would fence the approval on state nobody
reasoned about, which is the failure ADR-136 rightly refused.

**Workspace and language are part of the identity, not of the fingerprint.** A
subject read in a different workspace or language is a different subject, not a
changed one.

**The check lives in the shared approval path.** A tool contributes the subject
it can name; it does not compute a hash, compare one, or decide what happens
when the comparison fails. That is the runtime's job, once, for every tool —
the same reason :ref:`ADR-136 <adr-136>` put preview production in the loop
rather than in each tool.

.. _adr-184-repair:

The objection ADR-136 raised, and what answers it
==================================================

ADR-136's strongest argument was not about correctness:

   "**A resource fence has no repair path.** Refusing the approval on a changed
   row leaves the run suspended with no way forward: the model cannot re-issue
   the call (it is not running), and the approver cannot edit the pending
   arguments."

It is right about a fence that only refuses. It is wrong about this one,
because refusing here is not terminal: the run **re-suspends with a fresh
preview**, so the approver sees the current before-state and decides again. The
call is unchanged — it never needed re-issuing — and what aged was the picture,
which is exactly what gets rebuilt.

The remaining cost is a loop: an approval on a page busy enough to change
between two renders can bounce more than once. That is a worse experience than
today and a better outcome than today, and it is bounded by the same thing that
bounds every other approval — a human deciding.

.. _adr-184-not:

What this does not change
=========================

**ADR-132's turn digest stays what it is.** It binds a decision to the tool CALL
that was reviewed, and it is exact because the loop owns both sides. This binds
to the target resource, which TYPO3 owns and people edit. Two guarantees, two
failure modes, deliberately not merged — ADR-136 was right that folding the
second into the first would make the first lossy.

**No optimistic locking for TYPO3 at large.** ADR-136 observed that two editors
in the backend overwrite each other, last write wins, and that a tool is not the
place to invent a regime the rest of the system lacks. Still true, and this is
not that: the scope is one pending call, in one suspended run, against the
values one approver was shown.

**No new authority.** Who may approve is :ref:`ADR-130 <adr-130>`,
:ref:`ADR-133 <adr-133>` and :ref:`ADR-172 <adr-172>`. This record decides only
what a granted approval is a decision ABOUT.

**Approval remains no quality signal.** :ref:`ADR-176 <adr-176>` keeps that
separation; a stale-refused approval says nothing about the model.

.. _adr-184-consequences:

Consequences
============

- ✅ The write that executes is the write a human agreed to, for relative
  changes as well as absolute ones.
- ✅ The stale case is a named, machine-readable outcome rather than a silent
  divergence, so it can be counted instead of guessed at.
- ⚠️ An approval can now bounce back to the approver. That is new, and the card
  has to say why in words an editor understands.
- ⚠️ :php:`SuspendedRunState` grows a field. It degrades the way ADR-136's
  preview does: a suspended run persisted before this record carries no subject,
  and resumes exactly as it does today rather than being refused.
- ⚠️ Every writer that wants the guarantee has to name its subject. One that
  names none keeps today's behaviour, which is the honest default — a tool
  cannot be made safe by a runtime that does not know what it reads.

.. _adr-184-revisit:

Revisit when
============

- **A tool's subject is not one record.** The append already reads a LIST to
  decide where the new row lands; a writer whose operand spans several records
  makes the subject a set, and the fingerprint has to say what a partial change
  means.
- **The bounce becomes common.** If approvals start re-suspending routinely, the
  answer is not a weaker fingerprint but a shorter path from preview to
  decision.
