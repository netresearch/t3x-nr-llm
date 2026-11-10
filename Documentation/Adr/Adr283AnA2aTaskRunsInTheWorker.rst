.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-283:

=====================================================================
ADR-283: An A2A task runs in the worker, and the request only waits
=====================================================================

:Status: Accepted
:Date: 2026-11-10
:Amends: :ref:`ADR-219 <adr-219>` (a sweep tick records a heartbeat too);
    :ref:`ADR-234 <adr-234>` (a sweep-only installation has a heartbeat)
:Authors: Netresearch DTT GmbH

Context
=======

In A2A 1.0, ``SendMessage`` blocks by default: with
``SendMessageConfiguration.return_immediately`` unset or false, the server must
wait until the task reaches a terminal or an interrupted state before it
answers (:ref:`ADR-278 <adr-278>`). An agent run takes seconds to minutes.

A PHP-FPM request is cut off by any proxy in front of it whose read timeout
passes without a byte (:ref:`ADR-276 <adr-276>` measured that), and it holds a
PHP-FPM worker for as long as it lasts. Executing the run inside the request,
as the playground and the inbox do, would make the A2A endpoint as slow as its
slowest run and let remote clients occupy every PHP-FPM worker.

:php:`AgentRuntimeInterface::defer()` stores a QUEUED run and dispatches a
message only when :php:`QueueRouting::leavesTheProcess()` is true, that is when
the run's sender is not named ``default``; otherwise the run waits for a sweep
(``nrllm:agent:work``) or a consumer (:ref:`ADR-234 <adr-234>`).
``enqueue()`` dispatches always and, on the synchronous ``default`` transport,
executes in the calling process. A worker heartbeat exists only for
``messenger:consume`` (:php:`WorkerHeartbeatListener`, read through
``WorkerOperationsReaderInterface::snapshot()``); the sweep has none, which is
why ``nrllm:agent:status`` needs ``--minimum-workers=0`` for it.

Decision
========

**A task is bound to a publication profile.** A profile publishes one skill
and binds a registered domain handler, the LLM configuration, the allowed tools
and resources, the execution identity (:ref:`ADR-280 <adr-280>`), an optional
pinned workspace, the input and output schema, quotas, the retention and
whether the full text must be checked before release. One task has one run.

**Acceptance is one transaction.** The dedup record of the client's
``messageId``, the task, the run and the binding between them commit together,
and the response is sent only after the commit. A failed commit leaves none of
them.

**The run is queued with** ``defer()``. The request creates the task and the
run, as the client's technical backend user, and never executes the agent loop.
A worker runs it.

**A task is accepted only when a worker can be shown to run.** Before creating
anything, the server requires a live ``messenger:consume`` heartbeat or a sweep
tick within the last 120 seconds. The sweep gets a heartbeat for this: each
tick of ``nrllm:agent:work`` records itself in the worker operations table,
which ``nrllm:agent:status`` then reads as well. Without either, the request is
refused with JSON-RPC ``-32000``, HTTP 503, ``Retry-After``, an ``ErrorInfo``
with reason ``WORKER_UNAVAILABLE`` and a ``google.rpc.RetryInfo`` detail.
Nothing is stored.

**A blocking request waits, and never answers early.** It reads the task view
(:ref:`ADR-282 <adr-282>`) until a terminal or interrupted state. It does not
answer with a WORKING task in place of that wait: the proto says "must wait",
and a client that relies on the default cannot tell an early WORKING from a
result. The request has its own deadline, ``a2a.server.blockingWaitSeconds``
(default 20, at most 100), which the documentation requires to be lower than
every proxy read timeout in front of PHP-FPM. When it passes, the client gets a
system error (HTTP 504, JSON-RPC ``-32603``, ``ErrorInfo`` reason
``BLOCKING_DEADLINE_EXCEEDED`` with the task id). The task is untouched and
retrievable by ``GetTask``, and through ``ListTasks`` even by a client that
lost the body. A proxy that cuts the request earlier has the same effect on the
task.

**Waiting requests and long streams have separate capacities.** Waiting
blocking requests may not exceed ``a2a.server.maxWaitingRequests`` (default 4);
streams have limits per task, per client and per installation of their own. A
client's queued and running tasks may not exceed the limit on its record. A
request over a limit is refused like a missing worker, with a reason naming the
limit.

**A stream reads the journal, from one revision on.** ``SendStreamingMessage``
and ``SubscribeToTask`` (stage 5a) take the task snapshot and its journal
cursor at the same revision, send the snapshot first and then only newer
events. The snapshot replaces whatever partial artifact the client composed.
A2A promises no replay, so recovery is a new snapshot or ``GetTask``; a
``Last-Event-ID`` header creates no further contract. A stream closes at a
terminal state or INPUT_REQUIRED, and stays open after AUTH_REQUIRED, whose
credential arrives out of band (section 7.6.1 of the specification). It also
closes after ``a2a.server.streamMaxSeconds`` (default 300), sends a comment
line at least every 15 seconds, caps its frame size and buffer, and disables
proxy buffering with ``X-Accel-Buffering: no``. Closing a stream never cancels
the task.

**Content is held back until a full-text check has passed.** This is a
product decision. When a profile requires it, and by default whenever its
configuration has an output guardrail, no content fragment leaves the
installation before the whole text has passed the check: the stream carries
status progress only and the artifact follows in one event. A guardrail that
judges whole texts cannot be applied to fragments already sent, and a fragment
cannot be recalled. A failed check ends the task FAILED without content.

**Push notifications are delivered by the worker only**
(:ref:`ADR-285 <adr-285>`). No request makes a webhook call.

Consequences
============

- ●● A remote client cannot make a request execute a run, so it cannot
  occupy PHP-FPM workers beyond the capacity limits.
- ● A refusal names its reason and when to try again; nothing is half-created.
- ● The sweep's new heartbeat also lets ``nrllm:agent:status`` see a
  sweep-only installation.
- ◑ A run longer than the blocking deadline gives a blocking client an error,
  not a result; it has to retrieve the task.
- ◑ A profile with an output guardrail streams progress, not text.
- ✕ An installation without a running worker cannot offer A2A at all.
- ✕ A blocking request still holds one PHP-FPM worker for up to the deadline.

Net Score: +3

Alternatives considered
=======================

**Execute the run in the request, as the inbox does.** Rejected: the run
length would become the request length, behind a proxy timeout, and remote
clients could exhaust PHP-FPM.

**Use** ``enqueue()``. Rejected: on the synchronous ``default`` transport it
executes in the calling process, which is the request.

**Answer with the WORKING task when the wait runs long.** Rejected: it looks
like a valid blocking answer, so a client that relies on the default would take
an unfinished task for a result.

**Wait without a deadline of its own.** Rejected: the proxy then decides, and
the client receives a 504 without the task id.

**Stream text fragments and check afterwards.** Rejected: a fragment cannot be
recalled once a later check fails.
