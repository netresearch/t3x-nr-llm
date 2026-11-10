.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-agent-runs:

==========
Agent runs
==========

An *agent run* is one execution of the tool-calling agent loop. A run can
**pause** and wait for a human before it continues: to approve a tool call it
wants to make, or to supply a piece of typed input it asked for. The
:guilabel:`Agent Runs` module is the inbox where you make those decisions and
review runs that have finished.

The admin inbox lives in :guilabel:`AI > Operation > Agent Runs`;
the same actions are also reachable through the editor module
:guilabel:`AI > AI Tasks` (:ref:`ADR-131 <adr-131>`). Visibility is
actor-scoped: an administrator or a holder of the *Approve suspended AI
runs* grant sees every run, everyone else only the runs they started.
Approving continues the run under its owner's identity; the deciding
backend user is recorded for audit. The page works fully with JavaScript off (JavaScript only adds focus and
a deny confirmation).

.. _administration-agent-runs-inbox:

The inbox
=========

The module shows two lists:

- **Awaiting your decision** — runs paused for an approval or for input, each
  rendered as a card with the controls to resolve it.
- **Recent runs** — a read-only table of the most recently finished runs
  (configuration, status, created, finished, approval, cost when non-zero, and —
  on the runs you may open — a :guilabel:`Timeline` link to the run's detail
  page).

A run whose tool call failed still ends with the status ``completed``: the
model receives the error as the tool's result and answers, so the run itself
completed (:ref:`ADR-092 <adr-092>`, :ref:`ADR-191 <adr-191>`). The
:guilabel:`Status` cell then adds *Tool calls failed: N*, in the recent runs
and in the background list, so a write that did not happen is not hidden
behind ``completed``. It counts the tool steps the run's timeline shows as
*failed* — a denied approval and a refused call among them — and not a call
that was cancelled. The timeline names each one. Dashboards and the run queue
read the status and are not changed by it.

The :guilabel:`Approval` column states who granted a run's approval
(:ref:`ADR-173 <adr-173>`), so you can see it without opening each run. Four
readings, and only *Approved by the person who started the run* is highlighted:

- **Approved by the person who started the run** — the decision and the request
  came from the same backend user. Allowed by default; whether it is refused is
  the *Require a second approver* setting (:ref:`ADR-172 <adr-172>`).
- **Approved by someone other than the person who started the run** — two people
  were involved.
- **Approved, but the record does not say who started the run** — the run was
  started by a service account or another non-backend caller, so there is no
  backend user to compare the approver against. The approver is still recorded.
- **Approved, but the record does not say by whom** — the approving user was not
  recorded, so nothing can be said about the decision either way.

A run can pass several approval fences, and the column has one cell for it. It
then shows the strongest single reading, not a summary: *Approved by the person
who started the run* if any one fence was released by the initiator, otherwise
*Approved, but the record does not say by whom* if any one approver went
unrecorded. So a run whose second fence a colleague signed still reads as
self-approved, and the run timeline is where the fences are shown one by one.

An empty cell says three different things and must not be read as "no approval
was needed": the run passed no approval fence, or it was denied, or the
approvals could not be loaded or decoded. The run's timeline tells them apart, on the runs
you may open.

The same label appears on the approval row of the run timeline, next to the
``decidedBy`` uid. A denial never carries one: denying your own run is
deliberate, not an anomaly.

The column shows the same label to everyone who can see the row. A user holding
*Approve suspended AI runs* (``agent_approve``) lists other people's runs
without being able to open them, and for those runs the label is all the column
says — it never names a user.

If the store cannot be read, the page shows a warning box rather than a
silently empty inbox — an empty list therefore means "nothing waiting", not
"load failed".

.. _administration-agent-runs-timeline:

The run timeline
================

:guilabel:`Timeline` opens one run end to end, read-only. It shows the run's
summary — correlation, status and why it ended, configuration, rounds, tokens,
cost — and below it a single time-ordered list of everything the run produced:

- **Step** — a recorded loop step (a request, a model answer, a tool execution).
- **Provider call** — a telemetry row: which provider and model served it, how
  long it took, whether a cache hit or a fallback was involved, and the error
  class when it failed. These are joined to the run because since
  :ref:`ADR-153 <adr-153>` every provider call a run makes carries the run's
  uuid as its correlation id.
- **Governance** — a decision taken during the run: a tool the gate withheld, a
  guardrail block, an approval requirement, an injected-context refusal.

When a guardrail stopped the run, the summary says why under
:guilabel:`Stopped because`, in the guardrail's own words. A background run has
nobody waiting for that message, so this is where its author finds it.

You can only open your own runs; an administrator can open all of them. The
approval grant lets you *decide* another user's waiting run, but not read its
timeline — the :guilabel:`Timeline` link is therefore offered only on the rows
you may open. Reaching a run you may not read directly by URL is
indistinguishable from reaching one that does not exist: both send you back to
the list.

.. note::

   The timeline is **metadata only**. Prompts, model answers, reasoning, tool
   arguments and tool results are never rendered here, whatever
   ``privacy.level`` is set to, and neither is the verbatim resumable state of a
   waiting run. What you see are counts, sizes, names, timings and token
   figures. For live prompt and answer inspection use the playground. The
   one answer the page shows, above the timeline, is that of a background run
   (:ref:`administration-agent-runs-background`).

A run started before this extension version has steps but no provider calls:
its calls were traced individually and cannot be attributed retroactively.

.. _administration-agent-runs-approve:

Approving a tool call
=====================

A run pauses for approval (status ``WAITING_FOR_APPROVAL``) when the agent
wants to call a tool that opts in to human approval. A guardrail verdict of
``approval_required`` follows a different path: it fails the run with a policy
reason. It does not create a resumable approval card.

The approval card lists every tool call in the pending turn — the tool name
and, in a collapsible :guilabel:`Arguments` block, the exact arguments the
model proposed. A call whose tool is no longer registered is flagged.

One :guilabel:`Approve` or :guilabel:`Deny` covers the **whole** pending turn,
not a single call. Denying refuses the pending calls and continues the model
conversation with that refusal; the model may answer or propose another turn.

After a denial nothing is executed. The model is told that the approval was
declined and by whom — the user who started the run, or another backend
user — and answers from that (:ref:`ADR-200 <adr-200>`).

When the parent page already holds a page with the proposed title, the
preview of ``create_page_draft`` carries a warning line, and so does the
preview of ``create_content_element_draft`` when the page already holds an
element of that type with the proposed header, so a second copy is not
approved by accident. The preview lines are in the language of the backend
user the run acts as (:ref:`ADR-213 <adr-213>`).

If the tool cannot determine whether another approver may read the record,
the stored preview is withheld. A failed read check keeps that approval card
and other waiting runs available, without exposing the stored preview or
the failure details. The user who started the run may still read the preview
captured under their own permissions.

.. warning::

   The decision is bound to the exact turn you are looking at. If the run has
   moved on since the page loaded — for example another admin already decided,
   or the turn changed — the approval is refused with a warning. Reload the
   inbox to see the current state and decide again. This prevents authorising a
   turn you never actually saw.

.. _administration-agent-runs-input:

Providing input
===============

A run pauses for input (status ``WAITING_FOR_INPUT``) when the agent asks for
typed data against a declared schema. The card renders a form with one field
per schema property (text, number, integer or checkbox, with the field
description shown). Submitting validates and coerces the values against the
current schema; invalid input re-renders the form in place, keeping the text
and checkbox states on that run's card and pointing at the error. Other
waiting cards retain their own empty form. The run remains waiting for input.

.. _administration-agent-runs-diagnostics:

Optional run diagnostics
========================

The run persister keeps its documented fallback when a repository operation
fails, even if the optional diagnostic logger throws. Refused writes and
failed claims retain ``false``; a failed ``begin()`` or ``findRun()``
retains ``null``.
Logging cannot make these operations succeed. Explicit validation exceptions
still propagate.
A configured logger is attempted once with the existing severity, message and
context, without a fallback logging attempt. See :ref:`adr-225`.

The executor also retains its original outcome and exception when optional
logging fails after cancellation, lease loss, a guardrail verdict, a run failure
or a refused approval/input suspension. A failed run keeps its ``FAILED``
outcome and the exception that ended it. Ownership guards continue to protect a
row reclaimed by another worker. Successful stored suspensions retain their
resumable state.

Queued failure recovery also preserves its original failure, retry
classification and ownership-refusal outcome when diagnostics fail. A failed
logger cannot replace a ``not_retryable`` reason with ``retries_exhausted`` or
interrupt dead-letter handling after a real dispatch failure. Existing write
fences and guarded requeue/settlement continue to apply.

For background execution, batches and recovery, use the operational guides:


.. toctree::
   :maxdepth: 1

   RunQueue
   RunBatches
   RunRecovery
