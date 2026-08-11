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

The admin inbox lives in :guilabel:`Admin Tools > LLM > Agent Runs`;
the same actions are also reachable through the editor module
:guilabel:`Web > AI Tasks` (:ref:`ADR-131 <adr-131>`). Visibility is
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
  (configuration, status, created, finished, cost when non-zero, and a
  :guilabel:`Timeline` link to the run's detail page).

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

You can only open a run you are allowed to see. A run belonging to someone else,
without the approval grant, is indistinguishable from one that does not exist —
both send you back to the list.

.. note::

   The timeline is **metadata only**. Prompts, model answers, reasoning, tool
   arguments and tool results are never rendered here, whatever
   ``privacy.level`` is set to, and neither is the verbatim resumable state of a
   waiting run. What you see are counts, sizes, names, timings and token
   figures. For live prompt and answer inspection use the playground.

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
not a single call. Denying ends the run.

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
current schema; invalid input re-renders the form in place, keeping what you
typed and pointing at the error, rather than losing the run.

.. _administration-agent-runs-async:

Running queued runs asynchronously
==================================

By default a queued run executes **in-process**, synchronously, with no setup —
suitable for interactive and small workloads. For genuinely asynchronous
execution, route the queue message to the Doctrine transport and run a
consumer:

.. code-block:: php

   // settings.php / additional.php
   $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']
       [\Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage::class] = 'doctrine';

.. code-block:: bash

   vendor/bin/typo3 messenger:consume doctrine

.. warning::

   Once you route the message to ``doctrine`` you **must** run a consumer.
   Without one, queued runs are never picked up, and the stale-run reaper below
   has no worker to hand reclaimed runs back to.

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
task. It only concerns asynchronous runs — interactive runs hold no lease — and
does nothing useful without a running consumer.

.. note::

   Interactive runs abandoned by a dying client are not reaped here; they are
   cleaned up by age through the retention purge below.

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
