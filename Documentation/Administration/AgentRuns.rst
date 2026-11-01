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
wants to call a tool that opts in to human approval, or when a guardrail
demands it. The card lists every tool call in the pending turn — the tool name
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

.. _administration-agent-runs-async:

Running queued runs asynchronously
==================================

A consumer of the API that calls ``enqueue()`` gets an **in-process**,
synchronous run by default, with no setup. For genuinely asynchronous execution,
route the queue message to the Doctrine transport and run a consumer, or leave
the routing alone and queue with ``defer()``, which
:ref:`administration-agent-runs-background` describes:

.. code-block:: php

   // settings.php / additional.php
   $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']
       [\Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage::class] = 'doctrine';

.. code-block:: bash

   vendor/bin/typo3 messenger:consume doctrine

.. warning::

   Once you route the message to ``doctrine`` you **must** run a consumer or
   ``nrllm:agent:work``. Without one, queued runs are never picked up, and the
   stale-run reaper below has no worker to hand reclaimed runs back to.

.. _administration-agent-runs-background:

Background tasks from the command line and the scheduler
========================================================

An AI Task can run without anyone waiting for it. Two commands do that
(:ref:`ADR-234 <adr-234>`). Both appear in the scheduler under
:guilabel:`Execute console commands`.

.. code-block:: bash
    :caption: Queue a task, then work the queue

    vendor/bin/typo3 nrllm:task:enqueue release-notes --input="the text to work on"
    vendor/bin/typo3 nrllm:agent:work --limit=20 --time-limit=240

``nrllm:task:enqueue <identifier>`` stores one queued run and returns; no
provider is called. A task with manual input takes it from ``--input``. A task
that reads a source, such as the system log, reads it at this moment. To repeat
a task, schedule this command with the task's identifier as its argument.

``nrllm:agent:work`` executes queued runs, oldest first. ``--limit`` (default
``20``) and ``--time-limit`` (seconds, default ``240``) bound one tick; a run that
has started finishes. Set the scheduler frequency above the time limit. Runs left
over wait for the next tick. A run that fails is listed as failed and the command
still succeeds; the command fails only when the queue cannot be read. A run that
fails for a temporary reason, such as a rate limit, returns to the queue and is
tried again at the next tick, three times at most.

Before it claims a run, the command asks the budget. A run the user's budget or
the configuration's daily limit would refuse stays queued, and the runs behind it
continue, so a limit that resets at midnight delays work without losing it. A
Messenger consumer does not ask: routed to ``doctrine``, a run it picks up meets
the refusal and ends there.

The worker and a Messenger consumer can run side by side: a run is claimed
atomically, so exactly one of them executes it. With the default routing no
consumer is involved. Routed to ``doctrine``, a queued task also wakes the
consumer.

The run acts as a service account that may use the configuration
(:ref:`ADR-110 <adr-110>`), not as the ``_cli_`` user, and no per-user budget
applies to it; the configuration's own limits do. A task calls no tool, so it
writes nothing. Its configuration is the task's own or the active default. Its
usage appears under the task in the usage figures.

:guilabel:`Background runs` in the Agent Runs module lists these runs in every
status, queued ones included, with what queued them. A run a service account
queued has no owner: an administrator sees and opens it, a holder of the *Approve
suspended AI runs* grant sees it without its detail page, and an editor sees
neither.

An installation that runs only ``nrllm:agent:work`` has no consumer heartbeat.
Pass ``--minimum-workers=0`` to ``nrllm:agent:status``, and use
``--max-queue-wait`` to learn that no tick is running.

Its detail page shows the answer. A background run keeps its answer in
``tx_nrllm_agentrun.answer``, encrypted like a queued request, until the purge
removes the row. Unlike the step log, this does not follow the default
``privacy.level = metadata``, because the answer is the result nobody watched
arrive: ``none`` keeps nothing and ``redacted`` keeps the redacted text. See
:ref:`administration-data-retention`. A run that went through an approval keeps no
answer, because what it did is the write.

.. _administration-agent-runs-batch:

A batch over a folder or a page tree
====================================

An editor action can run over a whole folder or page tree
(:ref:`ADR-236 <adr-236>`). In the Editor Action Center, open
:guilabel:`Run this on several records` under the action, and name a folder or a
page. From the
command line or the scheduler:

.. code-block:: bash
    :caption: Alternative texts for a folder, from the image content

    vendor/bin/typo3 nrllm:editor-action:batch set_file_alternative_text \
        --user=jane --folder=1:/products/
    vendor/bin/typo3 nrllm:agent:work

**A batch writes nothing.** Each record becomes one ordinary run, and each run
stops at its approval card with the generated text. The field keeps its value
until somebody approves that card, so a folder of 100 images is 100 cards, each
decided on its own. There is no switch for writing directly.

``--user`` names the backend user the runs act as. Their rights decide which files
and pages are reached, a file they may not edit stays out, and the cards appear in their inbox. It must be an active
user and cannot be ``_cli_``. A user disabled later makes the run fail.

The image is read when the worker picks the run up, as that user. A file that
cannot be sent any more fails its run, and no model is asked. See
:ref:`configuration-vision` for folders to exclude.

A batch can be repeated. A card waiting for approval, a rejected card and a model
that declined keep their record out until the run is purged. A run that failed,
was cancelled or ran out of budget decided nothing, so its record is tried again.
A vision action
skips a file that holds a text in one of the fields it fills, and it leaves out
fields switched off in :ref:`configuration-vision`. A daily schedule therefore
picks up new files and never replaces a text. ``--only-empty=<column>`` adds a
field to that check, for example ``--page-tree=12 --only-empty=description`` on
``update_page_metadata``.

For a page the model gets the text of the page and its content elements, as the
page stood when the batch was queued. A tree holds the pages ``--user`` may edit
inside their mounts, without folders and recyclers.

At most 100 runs of one action are open at a time, the number of cards the inbox
shows. A file the action cannot handle, such as a PDF for a vision action, takes no
place among them. The command reports the rest, and a later batch picks them up
once cards are decided.

Worker operating profile
========================

Deploy web processes separately from Doctrine consumers. Each consumer executes
one message at a time; start a bounded number of processes against the same
transport to control concurrency. Atomic run claims prevent duplicate ownership
when two consumers receive the same run. Size concurrency against model rate
limits, memory per request and database capacity.

TYPO3 14.3 supports the Symfony-style consumer limits:

.. code-block:: bash
    :caption: Bounded TYPO3 14.3 consumer

    vendor/bin/typo3 messenger:consume doctrine --time-limit=3600 \
        --memory-limit=256M --limit=100

On TYPO3 13.4, use the existing ``messenger:consume doctrine`` command with a
supervisor and the core's configured stop-worker listeners; the Symfony-style
options above are available from TYPO3 14.2. A supervisor should restart exited
consumers, stop them gracefully and leave enough time for a running model call
to finish. The existing non-idempotent write fence still governs recovery when
a process is killed during a write.

Run the reaper periodically, for example once per minute with ``--limit=50``.
Its lease duration must exceed the maximum provider/tool request duration plus
the scheduling margin. A reaper retry is bounded by the runtime's existing
retry budget; it is not an alternative message broker.

.. code-block:: bash
    :caption: Aggregate worker and queue health

    vendor/bin/typo3 nrllm:agent:status --json --transport=doctrine \
        --minimum-workers=1 --worker-max-age=120 --max-queue-wait=300

The command returns success when no run lease is expired, all queued timestamps
are known, queue wait is within the limit and the requested transport has enough
live consumers. It returns failure for an unhealthy snapshot, or invalid for
malformed options. ``--minimum-workers=0`` explicitly disables the consumer
requirement for a synchronous-only installation. Counts of running runs include
interactive runs and must not be interpreted as a count of consumer processes.

Consumers renew a random process heartbeat at most once per 30 seconds, including
when idle. Only consumers serving the transport's ``default`` queue count; a
consumer restricted to other queue names cannot establish its health. Stopped
consumers remove their entry; active heartbeat updates prune entries older than
24 hours. Transport names contain no user or host identity.

Heartbeat storage and stop cleanup are best effort. A telemetry failure does not
stop consumption; failed heartbeat attempts retry at most once per 30 seconds
with the same process identity. Failed cleanup can leave a heartbeat until it
ages out. Warnings contain only the operation and exception class, without
connection details. Missing or stale observations remain visible to monitoring;
a failed status read is an error and does not produce a healthy snapshot.

A long blocking model/tool call delays the next consumer heartbeat. Set
``--worker-max-age`` above the configured call timeout and polling margin. A
heartbeat proves that the consumer event loop ran recently; it cannot prove
that a remote provider is available. Monitor provider failures separately.

Queue-entry time is recorded for each enqueue/requeue, rather than guessed from
run age. Legacy or future timestamps contribute to ``unknown_queue_wait``.
``oldest_queue_wait_seconds`` is null when no measured queued row exists, and
zero when a measured row has just entered the queue. JSON contains aggregate
counts and durations only, without prompts, document data, actors or secrets.

.. _administration-agent-runs-reaper:

Reclaiming stale runs
=====================

When a run is executed asynchronously, the worker holds a 15-minute lease that
it renews at every step. If the worker dies, the run is left ``RUNNING`` with a
lease nobody renews. The reaper reclaims those runs — it puts them back on the
queue, or, after three failed attempts, dead-letters them so they stop
occupying the running set:

.. code-block:: bash

   vendor/bin/typo3 nrllm:agent:reap

``--limit`` (default ``50``) bounds how many stale runs one invocation handles.
Schedule it from cron or the scheduler's :guilabel:`Execute console commands`
task. The scan includes every ``RUNNING`` run whose positive lease expired,
including interactive starts and resumed segments. Runs without a saved queued
request, runs abandoned during a non-idempotent write and runs whose retry
budget is exhausted are dead-lettered. Retryable runs with a saved request are
requeued and need a running consumer or ``nrllm:agent:work`` to execute again. Each mutation rechecks
staleness, so a renewed lease wins over the reaper's earlier snapshot.

.. _administration-agent-runs-retention:

Retention and privacy
======================

A waiting run stores the transcript it needs to resume — the pending tool
calls and the conversation so far — **verbatim**, so it can pick up exactly
where it paused. Unlike the per-step event log, this resumable state is kept in
full regardless of the configured privacy level, and is cleared when the run
settles to a terminal status.

Nothing is deleted until a purge runs. Finished runs are removed on the
``privacy.retention.agentRun`` window; runs still waiting for a decision use
the separate, deliberately longer ``privacy.retention.approval`` window, so a
purge never destroys work an approver has not got to yet. Set the ``approval``
window generously if approvers may take days.

See :ref:`administration-data-retention` for the retention settings and the
purge command that covers agent runs along with every other content-bearing
table.
