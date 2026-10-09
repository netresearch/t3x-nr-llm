.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. _adr-219:

==================================
ADR-219: Observe worker operations
==================================

:Status: Accepted
:Date: 2026-10-09
:Authors: Netresearch DTT GmbH

Context
=======

The runtime already owns claims, run leases, bounded retry and the write fence
(:ref:`adr-102`, :ref:`adr-104`, :ref:`adr-141`). A run lease says that an
executing segment owns a run. It cannot show whether an idle Messenger consumer
is alive. Run age also cannot measure the waiting time of a retried attempt.

Decision
========

Keep Symfony Messenger and the existing reaper. Record a queue-entry timestamp
on enqueue and each successful requeue. Unknown legacy timestamps remain zero
in storage and are represented as unknown in operational output.

A separate, bounded heartbeat table records each consumer process, transport
names and last heartbeat. Messenger lifecycle events update it even while the
consumer is idle; a stopped worker removes its entry. Heartbeats are pruned
after a bounded retention interval. Worker identifiers contain random process
identities rather than host names, user names or credentials.

Heartbeat writes and cleanup are best effort. Persistence or logging failures
must not interrupt message consumption. Assign the process identity before any
write and throttle attempts, including failed ones, to once per 30 seconds.
Stop releases the in-memory identity even if database cleanup fails. Diagnostics
include only fixed text, the operation and exception class; they exclude error
messages, exception objects and connection details. Failed measurements can leave
missing or stale heartbeats; a status read failure remains an error.

An internal operational repository and ``nrllm:agent:status`` command report
queued/running/dead-letter counts, oldest known queue wait, unknown queue-wait
count, expired run leases and live consumer count for a named transport. JSON
output is suitable for
monitoring. Optional thresholds give an explicit unhealthy exit status; absent
worker history is not proof of health. Run payloads and credential state are
never included.

Document a deployment with web processes, Doctrine consumers bounded with
version-appropriate options or a supervisor, and a periodic reaper. A worker's
heartbeat is evidence of its event loop, not proof
that a remote model call will succeed. An executing long call can outlast the
heartbeat threshold; operators choose thresholds against their call timeout.

Consequences
============

The status command belongs to ``nr_llm_tools`` in the future package split,
alongside the cancel/reap commands. The module seam's named ownership list
records that placement; it does not relax the core dependency boundary.

No frozen runtime or repository interface changes. Existing synchronous
execution stays available. Schema additions are optional measurements; no
backfill invents timing. Tests exercise queue timestamps, state aggregates,
idle/start/stop events, pruning and command exit behavior without a live model.
