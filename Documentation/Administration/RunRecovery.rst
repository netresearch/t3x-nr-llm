.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=======================
Recovery and run expiry
=======================

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
=====================

A waiting run stores the transcript it needs to resume — the pending tool
calls and the conversation so far — **verbatim**, so it can pick up exactly
where it paused. Unlike the per-step event log, this resumable state is kept in
full regardless of the configured privacy level, and is cleared when the run
settles to a terminal status.

Nothing is deleted until a purge runs. Finished runs are removed on the
``privacy.retention.agentRun`` window; runs still waiting for a decision use
the separate ``privacy.retention.approval`` window. Both windows inherit the
global retention period (30 days by default) unless overridden. Waiting runs
can therefore expire before anyone decides them. Set a longer ``approval``
window explicitly when approvers need more time.

See :ref:`administration-data-retention` for the retention settings and the
purge command that covers agent runs along with every other content-bearing
table.
