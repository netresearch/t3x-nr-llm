.. include:: /Includes.rst.txt

.. _adr-184:

============================================================================
ADR-184: An approval binds to the state the preview showed
============================================================================

:Status: Accepted
:Date: 2026-08-31
:Amends: :ref:`ADR-136 <adr-136>` (its staleness section, answered the
    other way)
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

**The persisted preview is the binding.** This record was proposed with an
identity-plus-fingerprint pair; reading the code settled it more simply.
:php:`SuspendedRunState::$callPreviews` already holds the bounded lines every
previewing tool produced at suspend, so nothing new is stored. At resume, before
the approved call executes, the loop re-runs :php:`previewCall()`, bounds the
result the same way, and compares it with what the approver was shown. Equal,
and the call runs exactly as today. Different, and **no mutation happens at
all**: the run suspends for approval again carrying the CURRENT preview and the
indexes of the calls that moved.

That is the literal form of this record's title — the lines ARE what was shown —
and it needs no hash, no new persisted fact, no state migration and no identity
or fingerprint contract on tools. (It does add ONE contract, further down: a
preview has to be deterministic. That is a rule about the method tools already
implement, not a second thing for them to implement.)

**Only what was displayed counts.** Not ``tstamp``: a hook moves it without
touching a relevant field, and a FAL or relation write is not covered by a
record timestamp at all. An edit to a field the preview never displayed does not
block — that would fence the approval on state nobody reasoned about, which is
the failure ADR-136 rightly refused. The same applies to the part of a value
beyond the 120-character excerpt the card renders: the excerpt was what was
shown, and the fence binds what was shown.

**A different subject arrives as a changed preview.** The proposal kept identity
(workspace, language) apart from the fingerprint. Under line comparison it does
not need to be: a call whose record was deleted, or which now reads in another
workspace or language, gets the tool's own neutral refusal string as its new
preview, so the mismatch surfaces with the tool's own explanation rather than a
generic identity error. There is no second vocabulary to maintain.

**The check lives in the shared approval path, and covers the whole turn.** No
tool computes, compares or decides anything. And every pending call is checked
before ANY of them executes: a turn is approved as one
(:ref:`ADR-132 <adr-132>`), so checking inside the execution loop would let the
first call mutate before the second was found stale.

**Two cases deliberately do not bind.** A call with no preview — nothing was
shown, so there is nothing for the approval to be a decision about, and a tool
without :php:`ToolPreviewInterface` keeps exactly the behaviour it had. And a
preview that FAILED at suspend: the card told the approver they were deciding
blind, and binding them to the text of an exception would bind them to nothing
useful. The opposite case is not symmetric — a re-preview that throws at resume
means something WAS shown and can no longer be compared, so it refuses.

**A preview must be deterministic, and that is now a written contract.** A line
carrying a clock, a random value or an unstable ordering would stale every
approval of that tool forever. :php:`ToolPreviewInterface` says so; the seven
current writers were checked and none reads such a source inside
:php:`previewCall()`.

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
- ⚠️ :php:`SuspendedRunState` grows one field — the indexes of the calls whose
  preview moved — so the card can mark them. It degrades to ``[]`` the way
  ADR-136's preview degrades to "no preview". The notice is a field rather than
  an extra preview LINE on purpose: a line would join the next comparison, and a
  second approval against an unchanged record would then find it missing and
  bounce again, forever.
- ✅ Every previewing writer gains the guarantee without any per-tool work, which
  is more than this record promised when it was proposed. All seven writers
  implement :php:`ToolPreviewInterface` today.
- ⚠️ Runs suspended before this shipped are fenced too: they already carry
  previews, so there is nothing to migrate and no reason to exempt them. Safe
  because refusing is not a dead end here.

.. _adr-184-revisit:

Revisit when
============

- **A preview stops being a faithful proxy for the subject.** The binding is
  only as good as what the lines show; a tool that previews less than it writes
  narrows the fence without saying so.
- **The bounce becomes common.** If approvals start re-suspending routinely, the
  answer is not a weaker fingerprint but a shorter path from preview to
  decision.
