.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-234:

================================================
ADR-234: Background work is a stored run and a sweep
================================================

:Status: Accepted
:Date: 2026-11-01
:Amends: :ref:`ADR-102 <adr-102>` (a run may be queued without a wake-up message), :ref:`ADR-153 <adr-153>` (the detail page of a background run shows its answer)
:Authors: Netresearch DTT GmbH

.. _adr-234-context:

Context
=======

The worker side of a queue exists: the handler (:ref:`ADR-102 <adr-102>`), the
reaper (:ref:`ADR-104 <adr-104>`) and the status command
(:ref:`ADR-219 <adr-219>`). Nothing produces work for it.
:php:`AgentRuntimeInterface::enqueue()` has no caller in this extension, and
AI Tasks run only inside a backend request.

:ref:`ADR-162 <adr-162>` names the reason ``enqueue()`` was not used: the
transport is the operator's choice and defaults to synchronous, so on a default
installation it executes the run inside the caller. Three coming producers
cannot live with that. A batch over a folder would hit the timeout that capped
ADR-162 at 20 records, a fill on save would block the save, and a scheduled task
on an installation with a scheduler and no Messenger consumer has nothing to
wake.

.. _adr-234-decision:

Decision
========

**One queue, the run table, and a second way to work it.** There is no table of
its own. ADR-162 rejected that for batches, and the reason holds for tasks: a
second lifecycle would need its own claim, lease, retry, cancel and retention.

:php:`AgentRuntimeInterface::defer()` stores the same QUEUED row as
``enqueue()`` and never executes in the caller. It dispatches the wake-up
message only where the routing sends :php:`AgentRunQueuedMessage` to a transport
other than ``default``, because on ``default`` the dispatch IS the execution. A
dispatch that fails is logged and the row stays queued, unlike ``enqueue()``,
which fails the run: here the sweep still finds the row.

``nrllm:agent:work`` is the sweep. It reads QUEUED rows oldest first and calls
:php:`runQueued()`, which claims atomically, so it can run beside a consumer and
beside itself. One tick is bounded by ``--limit`` and ``--time-limit``. A failed
run is that run's outcome and does not fail the command; a queue that cannot be
read does.

**The budget is asked before the claim.** A refusal after it costs the run: a
tool-free run is dead-lettered, because a budget refusal is no retryable failure
class, and a tool run settles as finished (:ref:`ADR-162 <adr-162>`). A limit that
resets at midnight would otherwise burn one queued run per tick all night. So the
sweep calls :php:`BudgetServiceInterface::check()` for the run's user and
configuration, and a run it refuses stays queued while the runs behind it
continue. This narrows the loss and does not close it: a run that passes the check
and spends the last of a budget while another does the same still meets the
refusal, and so does a run a Messenger consumer picks up, which asks nothing. A
consumer that left the row queued instead would have no way to learn when the
budget resets.

**A retry waits for the next tick.** On the synchronous transport the dispatch of
a requeued run would execute it inside the failure handling of the run before it,
and a rate limit would use up the retry budget in seconds. A run with an origin on
that transport therefore stays queued, and the sweep is its backoff.

**A scheduled job is a schedulable command, not a task class.** The repository's
precedent is a console command the scheduler lists under
:guilabel:`Execute console commands` (``nrllm:agent:reap``, ``nrllm:mcp:import``).
EXT:scheduler is not a dependency of this extension, and a task class would make
it one and tie the code to its API on two TYPO3 majors.

**An AI Task runs as an ordinary run.** ``nrllm:task:enqueue`` builds an
:php:`AgentRunRequest` from the task: an empty tool list, which the loop answers
with one plain completion; the task's configuration or the default; the task's
skills as forced skills; JSON mode for a JSON task; the task uid on the usage
row, which is why the tool-free turn now passes the response format and the task
uid. The queued task gets everything a run has and a :php:`Task` execution does
not: a row, a status, a cost and a retry.

**Whose rights.** A background run acts as the actor stored when it was queued,
which :php:`AgentRunRequestCodec` restores in the worker. For a task queued from
the command line that is a service account holding ``configuration:use``
(:ref:`ADR-110 <adr-110>`). It is never derived from ``_cli_``, whose admin flag
says nothing about who asked. A task calls no tool, so nothing here needs a
backend user. A producer that needs one, because its tool writes, stores the
frozen actor of the backend user who queued the work; a writing tool refuses a
service account. A deleted or disabled user yields no acting user in the worker
(:php:`ActingBackendUserResolver`), so such a run's tools refuse.

**A background run keeps its answer.** The event stream follows
``privacy.level`` and keeps no content at the default ``metadata``. An answer
nobody watched arrive is different: dropping it leaves a run nobody can use, so
a task would be queued and its result discarded. A run with an origin therefore
stores its answer in ``tx_nrllm_agentrun.answer``, sealed like ``queued_request``
and rotated with it. ``none`` keeps nothing, ``redacted`` keeps the redacted
text, and the purge removes the column with the row
(``privacy.retention.agentRun``). The detail page renders it for those who may
read the run; every other run stays metadata only.

**History.** The module lists runs with an ``origin`` in every status, including
queued, restricted like the other lists. A service-account run carries
``be_user = 0``, so an administrator sees it and may open it, a holder of the
approval grant sees it and may not, and an editor does not see it.

.. _adr-234-consequences:

Consequences
============

- An installation with cron or the scheduler can run background work with no
  Messenger consumer. One with a consumer needs no change.
- ``nrllm:agent:work`` and a consumer may both pick a row; the claim decides.
- An installation with only the sweep has no consumer heartbeat, so
  ``nrllm:agent:status`` needs ``--minimum-workers=0``.
- A service-account run has no backend user. The ambient ``_cli_`` user can still
  appear on telemetry and guardrail rows of the run, which read it as the caller;
  nothing is authorised on it.
- A run queued with ``defer()`` on a synchronous routing waits until the next
  tick. Nothing executes it if neither a sweep nor a consumer runs;
  ``nrllm:agent:status --max-queue-wait`` reports that.
- The retry of a reaped run still goes through the message bus. On a synchronous
  routing the reaper executes it, as it did before.
- ``AgentRunRepositoryInterface`` gained three methods and an optional parameter,
  which breaks out-of-tree implementors; the interface is in-repo plus test
  doubles by design (:ref:`ADR-102 <adr-102>`). The additions to the ``@api``
  surface are additive.
- A task's input is stored in the queued request until the run settles, sealed.
- Task input from a source is read when the task is queued, not when it runs.

.. _adr-234-alternatives:

Alternatives considered
=======================

**Require routing the message to Doctrine.** Then a producer on a default
installation would have to refuse, and the two features this record exists for
would not work out of the box.

**A task-run table executing :php:`TaskExecutionService`.** Full fidelity with
the interactive path and no new behaviour in the loop, at the price of a second
lifecycle. Rejected for the reason in ADR-162.

**Keep the answer only at** ``redacted`` **or** ``full``. The default level would
then discard every result, and the page would show a scheduled task that
succeeded and said nothing.

.. _adr-234-revisit:

Revisit when
============

- A backend form queues tasks: the actor is then the editor, and the list must
  show them their own runs, which it already does by ``be_user``.
- Queue wait measured by ``nrllm:agent:status`` shows that a tick's limits do not
  keep up. The bounds are a judgement, not a measurement.
