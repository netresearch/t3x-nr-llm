.. include:: /Includes.rst.txt

.. _adr-136:

============================================================================
ADR-136: The write preview is produced when the run suspends
============================================================================

:Status: Accepted
:Date: 2026-08-09
:Authors: Netresearch DTT GmbH

.. _adr-136-context:

Context
=======

:ref:`ADR-122 <adr-122>` deferred the preview on two grounds, and both are
about placement rather than value:

   "The preview has no caller and no display. The one surface that could show it
   is the approval card […] It would also have to run inside the reviewing
   administrator's request rather than the run's actor context, which
   :ref:`adr-083 <adr-083>` forbids reading around."

Producing the preview **at suspend time** answers both in one move. The caller is
:php:`ToolLoopService`, at the point it throws
:php:`ToolApprovalRequiredException`. The display is the approval card, which by
then is guaranteed to exist — the pause is what creates it. And the context is
the run's own actor context, because the loop is still executing the run: no
administrator's request is involved, nothing is read around ADR-083.

The second half of the reason is what :ref:`ADR-135 <adr-135>` got half right.
It observed that ``update_page_metadata``'s arguments ARE the new values, so the
card already shows them. What the card does not show is what those values
REPLACE. "Set the description to X" and "replace this hand-written description
with X" are different decisions, and only the second one is a decision.

.. _adr-136-decision:

Decision
========

An opt-in :php:`ToolPreviewInterface` — one method, no base class, no
:php:`ActionInterface`, exactly the shape :php:`ToolEffectInterface` and
:php:`RequiresApprovalInterface` already have. A tool that implements it returns
human-readable lines describing what the pending call would do; the forty-odd
read-only builtins are untouched and render no preview line at all.

The lines are produced inside the approval scan, for every OFFERED call of the
suspending turn, and travel with the state:

- **Persisted** as an additional optional field on :php:`SuspendedRunState`. It
  is inside the same blob as the transcript, so it is encrypted at rest by the
  same codec (:ref:`ADR-114 <adr-114>`) with no new plumbing.
- **Degrading, not failing.** :php:`fromArray()` treats a missing or malformed
  ``callPreviews`` as "no preview". A running installation has suspended runs in
  its database; every one of them must still resume, and it does — the card
  falls back to the arguments alone, exactly as before this ADR.
- **Rendered** in :php:`WaitingRunViewFactory::buildApproval()`, in the Fluid
  partial, and in BOTH playground responses (the JSON payload and the streamed
  ``awaiting_approval`` event) through the single ``pendingTools()`` helper the
  two share.
- **Bounded** before it is persisted: twenty lines, 500 characters each,
  whitespace collapsed. A preview is model-triggered output like a tool result,
  and it goes into an encrypted column that is re-read on every resume.

A failed preview is a line, never a blank and never a fatal
------------------------------------------------------------

A tool whose preview throws must not kill the run — the loop catches
:php:`Throwable`, logs it, and stores a line saying the preview failed, marked
with a ``failed`` flag the card renders as *"No preview — you are deciding
without one"*. An empty return is treated the same way.

The alternative — swallow the failure and show nothing — is the dangerous one:
an approver cannot tell a tool that has no preview from a tool whose preview
broke, and would take a missing warning for the absence of anything to warn
about.

The exception TEXT is deliberately not shown, only its class. This follows
:php:`ToolLoopService::invoke()`, which withholds exception bodies for the same
reason: a DBAL failure carries ``Access denied for user X@host``, and the
preview is persisted and rendered rather than discarded. The full exception goes
to the log, where the operator can already read credentials they own.

.. _adr-136-staleness:

What if the target changed between preview and execution
=========================================================

**The preview is a snapshot of the pause, not a precondition for the write. A
target that changed in between does not block the approval.** The card says so,
in as many words: *"Captured when the run paused — the target may have changed
since."*

The reasoning, in the order it decided the question:

**A writing tool here sets absolute values.** ``update_page_metadata`` writes
``description = "…"``, not "append" or "increment". A concurrent human edit
therefore changes what the approver READ, never what the approval DOES. The
write that executes is the write that was shown; only its "before" column has
aged.

**A resource fence has no repair path.** Refusing the approval on a changed row
leaves the run suspended with no way forward: the model cannot re-issue the call
(it is not running), and the approver cannot edit the pending arguments. Every
unrelated edit to a busy page — by an editor who never heard of the run — would
dead-end an approval that a human had already decided was correct.

**The turn digest is not this.** :ref:`ADR-132 <adr-132>` binds a decision to the
tool CALL that was reviewed, so a stale tab cannot authorise a turn nobody
looked at. That is a guarantee about the agent's proposal, and it is exact
because the loop owns both sides of it. The target resource is owned by TYPO3
and edited by people; binding to it would be a different guarantee with a
different failure mode, and extending the digest to cover it would silently
convert ADR-132's precise check into a lossy one.

**TYPO3 has no such fence either.** Two editors on the same page in the backend
overwrite each other, last write wins. A tool that a human explicitly approved
is not the place to invent an optimistic-locking regime the rest of the system
does not have.

**Revisit when a writing tool writes a RELATIVE change.** An append, an
increment, a "remove the third paragraph" — for those, the "before" is not
decoration, it is an operand, and a snapshot stops being sufficient. That tool
needs a precondition token; this one does not, and building the token first
would be ADR-122's mistake repeated.

.. _adr-136-disclosure:

Who may read a preview
======================

The preview is produced under the RUN OWNER's authority and read by the
APPROVER, and those are not always the same person. It is therefore authorised
twice over:

1. **At production.** The tool checks the EXPLICIT acting user of the run
   (ADR-083) — for ``update_page_metadata``, ``doesUserHaveAccess()`` plus
   ``checkLanguageAccess()``, the same checks and the SAME neutral refusal
   string ``execute()`` uses. A preview can never show a page the run itself
   could not have written, and cannot be used to probe the page tree for
   existence.
2. **At reading.** The approvals inbox is reachable with the ``agent_approve``
   grant (:ref:`ADR-130 <adr-130>`), and :ref:`ADR-133 <adr-133>` additionally
   requires the approver to pass the same tool gate the execution would.

**The honest gap:** those gates are tool-level, not record-level. An approver
who may run ``update_page_metadata`` but holds no permission on THIS page now
sees that page's title and current metadata. That is a real disclosure, and it
is accepted for one reason: the same approver is being asked to RELEASE a write
to that page. Authority over the change is strictly larger than sight of what
the change replaces, and withholding the "before" would not remove the authority
— it would only make it blind. Two bounds keep it that shape: only the fields
the call would write are shown (never the whole row), and each value is
truncated to a 120-character excerpt.

The same limit stated the other way: a tool's preview must read only what the
run's acting user may read. That is a contract on implementors, written into
:php:`ToolPreviewInterface`, not something the loop can enforce for them.

.. _adr-136-consequences:

Consequences
============

●● An approver sees what a write would replace, in the same card that asks them
to release it. For the one shipped writer this is the difference between reading
a model's proposal and reading a diff.

● The interface is opt-in and its absence is silent by design — a writing tool
that skips it costs its approver the comparison and nothing else. That is the
same trade as :php:`ToolEffectInterface`, whose silence costs more.

◐ :php:`SuspendedRunState` grew a tenth constructor parameter. It is optional,
last, and `@api`: the snapshot test records the new public property, and no
existing caller changes.

✕ A preview runs a tool's read path at suspend time, on the loop's clock. It is
one bounded read per previewing call in the turn, but it is work the loop did
not do before, and a slow preview delays the pause the operator is waiting for.

✕ An approver may read metadata of a page they hold no permission on, bounded as
described above.

.. _adr-136-revisit:

Revisit when
============

A writing tool needs a preview of a RELATIVE change, or a second surface starts
rendering previews outside the approval card. The first breaks the snapshot
argument above; the second would be the moment to ask whether the lines should
be structured data rather than text, which they deliberately are not today —
one string list has one rendering, and three surfaces render it identically.
