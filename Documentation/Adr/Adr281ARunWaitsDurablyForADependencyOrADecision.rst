.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-281:

=====================================================================
ADR-281: A run waits durably for a dependency or a decision
=====================================================================

:Status: Accepted
:Date: 2026-11-10
:Amends: :ref:`ADR-081 <adr-081>` (the run status vocabulary gains a waiting
    state that no person decides); :ref:`ADR-276 <adr-276>` (a decision can
    also be stored and continued by the worker); :ref:`ADR-135 <adr-135>` and
    :ref:`ADR-237 <adr-237>` (a write the worker carries out after a stored
    approval gets the backend environment around that call)
:Authors: Netresearch DTT GmbH

Context
=======

A task delegated to a remote agent (:ref:`ADR-278 <adr-278>`) may take
minutes. A tool is synchronous: :php:`ToolInterface::execute()` returns a
:php:`ToolResult`, which has no pending variant, and the loop waits for it while
holding a request or a worker and its lease (:ref:`ADR-141 <adr-141>`).

:php:`AgentRunStatus` has two waiting states, ``waiting_for_approval`` and
``waiting_for_input``, and :php:`AgentRunStatus::awaitingValues()` returns
exactly those; both mean a person decides. :ref:`ADR-081 <adr-081>` introduced
the enum as "the full lifecycle the later epics need". The reaper
(:ref:`ADR-104 <adr-104>`, :php:`ReapStaleAgentRunsCommand`) acts on RUNNING
rows with an expired lease only.

nr_mcp_agent reads the status. At its ``main`` 8e301436,
``ChatService::reconcile()`` handles the two waiting states, RUNNING and
QUEUED, and treats every other value as settled: it marks the conversation
failed with "This run finished outside the chat". A new status reaches that
branch. nr_mcp_agent sets no ``origin`` on the runs of its chat.

Sending a message to a remote agent creates state on the other side. A
response can be lost after the request arrived, and A2A makes duplicate
detection by ``messageId`` optional (section 3.3.1 of the specification).

A decision on a waiting run is executed in the deciding request:
:ref:`ADR-276 <adr-276>` keeps the continuation there and streams its progress,
and rejected handing it to the worker partly because a recorded, unexecuted
decision needed a state of its own. A decision that arrives over A2A (stage 4)
has no backend request to continue in.

Decision
========

**A new run status:** ``AgentRunStatus::WAITING_FOR_DEPENDENCY``
(``waiting_for_dependency``). The run waits for something outside it that no
person of the installation decides; a remote A2A task is the first such
dependency. It is not terminal and not in ``awaitingValues()``: nothing in the
inbox can decide it. A run in it holds no lease, so the reaper leaves it alone,
as it leaves the human waiting states. :php:`AgentRunOutcome` gains
``AWAITING_DEPENDENCY`` for the segment that suspends. Both are additive to the
``@api`` surface: ``Stability.rst`` classes a new enum case as additive, and the
:php:`AgentRunOutcome` docblock reserves new cases for minor releases.
:php:`AgentRunStatus` carries no such note. The existing synchronous signatures
stay; new behaviour comes through additive interfaces.

**Every consumer understands the value before it is written.** The inbox, the
run views, ``nrllm:agent:status``, the reaper and nr_mcp_agent learn the status
first; nr-llm writes it only after that. Since nr_mcp_agent's chat runs cannot
be told apart, no delegation tool is offered at all while nr_mcp_agent is
loaded in a version that does not handle the status, and the remote-agent
module says why. The release that activates the status names the nr_mcp_agent
version under "Upgrading".

**The suspension is a checkpoint, and it resolves once.** A suspended segment
stores, sealed, the tool-call id the dependency answers, the external operation
reference, the actor, the workspace, the deadlines and the budget state. The
resume answers exactly that tool-call id and never executes the delegation tool
again. A model turn that calls a delegation tool together with another tool is
rejected before any of them runs, so at most one dependency is pending per
segment.

**Resume is fenced.** The run is claimed for resume with the atomic claim and
lease of an approval resume. A worker that lost its lease writes nothing; of two
workers that see the same remote result, one resolves the dependency.

**Budgets persist.** Deadline, delegation count, transferred bytes and the
local token and cost budgets survive every suspension. The delegation limit
covers only delegations nr-llm makes; what a remote agent delegates further is
outside its control.

**Every send is journalled before it leaves, and an unknown outcome is never
resent automatically.** A row in ``tx_nrllm_a2a_send`` is committed before the
network call, holding the owner, the remote agent, the run, the ``messageId``
and the SHA-256 of the request; the same owner, id and digest are the same
operation, the same id with another digest is refused. A lost response leaves
the row ``outcome_unknown``; the run shows it, and only an operator resends,
with a new id. A duplicate task on the remote side is worse than a run that
asks a person.

**The run is resumed from the worker.** A coordinator polls ``GetTask`` for
waiting runs at the remote record's interval inside :php:`WorkerVaultScope`;
push (:ref:`ADR-285 <adr-285>`) only makes it look sooner. At the deadline the
run resumes with a timeout result and the remote task is cancelled. Cancelling
a waiting run sends ``CancelTask`` once and settles the run CANCELLED whatever
the answer.

**A decision can be stored and continued by the worker.** An additive
interface takes in an input, an approval or a denial, checks it and stores it,
and returns a storage receipt instead of continuing the run. It checks that the
pause is still pending with the pause generation the decision was made for, the
run's owner and the deciding actor, the input against its schema, the approved
content against the turn digest, and the decider's current permission. The run
goes to QUEUED with a reference to the stored decision; the worker checks the
rights again before the first effect. The synchronous path of ADR-276 stays and
uses the same checks and the same atomic claim: exactly one of the two wins a
pause. The recorded, unexecuted decision is the QUEUED run with its continuation
reference; it needs no further status.

**A write the worker carries out gets the backend environment around that
call.** :ref:`ADR-135 <adr-135>` makes a writing tool refuse in a process
without ``$GLOBALS['BE_USER']`` and ``$GLOBALS['LANG']``, on the ground that its
writes happen after an approval in a backend request, and :ref:`ADR-237
<adr-237>` narrowed that for the field fill alone. A stored approval is carried
out by the worker, so every approved write there was refused with "writing needs
a full backend environment". :php:`WorkerWriteEnvironment` sets both globals
around the execution of a tool that does not declare a read, as the run's acting
user, and restores them in ``finally``, as the field fill does. It does so only
in a process that has no backend user of its own: the command line's booted user
counts as none, a request's user is left alone. The leak ADR-135 feared, the
globals staying behind for later hooks, is closed the same way as for the fill.

Consequences
============

- ●● A delegated task holds no request, no worker and no lease while it runs.
- ●● A lost response cannot create a second remote task, and a remote result
  cannot run the delegation twice.
- ● A decision that arrives over A2A is executed by the worker, with the same
  checks as one from the inbox.
- ◑ A run can sit in the new status until its deadline; the inbox and the run
  list show it as waiting, not as needing a decision.
- ◑ On an installation with nr_mcp_agent, delegation waits for an
  nr_mcp_agent release.
- ✕ Any other consumer that maps "not waiting, not running, not queued" to
  "settled" misreads the status, although the enum change is additive.
- ✕ Polling adds requests to the remote for the duration of a task.

Net Score: +4

Alternatives considered
=======================

**Reuse** ``waiting_for_input``. Rejected: the inbox would offer a person a
decision nobody can make, and nr_mcp_agent would show a question.

**A status named after A2A.** Rejected: the state describes waiting on any
external operation; a second kind of dependency would otherwise need another
value and another round through every consumer.

**Keep the run RUNNING with a long lease.** Rejected: it holds a worker or
makes the reaper requeue a run that is fine, and a requeue would resend.

**Retry an unknown outcome with the same** ``messageId``. Rejected: duplicate
detection is optional in A2A, so a retry may start a second task.

**Continue A2A decisions in the A2A request, as the inbox does.** Rejected: the
request would execute the run (:ref:`ADR-283 <adr-283>`), and the remote client
does not wait for it.
