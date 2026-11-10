.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-282:

=====================================================================
ADR-282: An A2A result is stored with the run's completion
=====================================================================

:Status: Accepted
:Date: 2026-11-10
:Amends: :ref:`ADR-234 <adr-234>` (an A2A task's run keeps its result in the
    completing transaction, also after an approval); :ref:`ADR-242 <adr-242>`
    (a fourth store follows the run's answer ceiling)
:Authors: Netresearch DTT GmbH

.. note::

   The decision also covers the A2A event journal that polling, streams and
   push read; the title names the part the other records amend.

Context
=======

A remote client of the server role (:ref:`ADR-278 <adr-278>`) learns the result
of its task by ``GetTask``, a stream or a push notification, any time after the
task reports COMPLETED. A COMPLETED task without its artifacts is a wrong
answer, not a missing one.

nr-llm keeps one kind of result today. :php:`QueuedRunCoordinator::keepAnswer()`
stores the final answer of a queued run with an origin that COMPLETED, in
``tx_nrllm_agentrun.answer``, sealed (:ref:`ADR-234 <adr-234>`). It runs after
the run has been settled, and a failure there is only logged, so a run can be
COMPLETED without its answer. A run that went through an approval keeps none.

What may be stored is decided by two ceilings per run
(:ref:`ADR-270 <adr-270>`): the audit level for events and the answer level for
answers, read by :php:`RunPrivacyLevelResolver::forRunAnswer()`. The answer
level follows the global level, except that the global default ``metadata``
keeps a full answer, and a provider or task tightens it
(:ref:`ADR-242 <adr-242>`). ADR-270 tightens it per actual provider attempt,
including a fallback, under a run-row lock, and scrubs a stored answer when it
tightens; a later write rereads the ceiling under that lock.

Decision
========

**An A2A task's result is written in the transaction that completes its run.**
The artifacts and the final status message go to ``tx_nrllm_a2a_task`` (sealed,
like the answer column), and the task's state and the run's status change to
COMPLETED in the same transaction, under the run-row lock ADR-270 uses for
answer writes. If the result cannot be written, the run is not completed: it
fails, and the task says FAILED. A COMPLETED task without its stored result
cannot exist.

**Everything a remote party sees comes from one journal, written after the
commit.** :php:`AgentRunExecutor` hands each step to its live observer before
it records it ("emit-before-persist"), and text deltas reach the observer only;
an A2A stream or webhook fed from there could announce what was never stored.
So ``GetTask``, ``ListTasks``, SSE and push read the task view and an A2A event
journal (``tx_nrllm_a2a_event``) instead. A projector writes both after the
completing transaction, from the committed state. It is idempotent per run
revision: the same revision projected twice yields its events once. A projector
failure is retried from the committed state and never executes the model or a
tool again. The journal holds only what may leave the installation (state,
status message, the stored artifacts), never a prompt, a tool argument, raw
provider output or an audit row.

**The store follows the answer ceiling, not the audit level.** The result is
what the task exists for, like a background answer. ``full`` keeps it,
``redacted`` keeps the redacted text, as ADR-234 does. The audit level of the
events does not matter. This does not bypass ADR-270: the answer ceiling is
read under the lock at completion, and a tightening that scrubs answers scrubs
this store as well.

**A profile that cannot keep a result is not executed.** A publication profile
whose configuration resolves to an answer level of ``metadata`` (set explicitly
on its provider or task) or ``none`` is refused before a task is created, with
``UnsupportedOperationError`` (``-32004``) and an ``ErrorInfo`` reason
``RESULT_NOT_RETAINABLE``. This applies to the blocking mode too: a blocking
call that reaches its cap (:ref:`ADR-283 <adr-283>`) is continued by
``GetTask``, which needs the store.

**A ceiling that tightens during the run fails the task, distinguishably.** A
fallback can move the answer ceiling to ``metadata`` or ``none`` after the task
was accepted. Then nothing is stored, the run ends FAILED, and the task says
FAILED with a status message saying the result could not be kept under the
installation's privacy policy, and ``Task.metadata`` carrying the reason
``RESULT_NOT_RETAINABLE``. No path returns the content, the blocking response
included. A task that COMPLETED with no artifacts is a different, legitimate
state.

**An A2A run keeps its result after an approval.** ADR-234's "a run that goes
through an approval keeps none" does not apply to a run that belongs to an A2A
task: the remote client has no other way to learn what was done.

**The task's history follows the same rule.** ``Task.history`` holds the
client's messages and the agent's status messages; a later turn in the same
context reads it, and ``GetTask`` returns it. It is sealed, kept at the answer
level like the result (``redacted`` keeps redacted text), and stored nowhere
else. A profile that cannot keep a result cannot keep a history either, so
the refusal above covers both.

**The result is purged with its run.** The task row and its sealed columns are
removed by the run purge (``privacy.retention.agentRun``); afterwards
``GetTask`` answers ``TaskNotFoundError``, which the specification allows for a
purged task.

Consequences
============

- ●● A task that says COMPLETED always has its result.
- ●● Polling, streams and webhooks show the same states in the same order,
  and none of them shows a state that was not committed.
- ● One privacy rule for every answer an installation keeps; nothing new to
  configure.
- ◑ A profile on a provider or task tightened to ``metadata`` cannot be
  published; the backend says why when the profile is saved.
- ◑ A fallback to a stricter provider fails a task that would otherwise have
  completed.
- ✕ The completion of an A2A run takes a lock and a second table write in its
  transaction.

- ✕ A stream lags the run by the projector's latency.

Net Score: +5

Alternatives considered
=======================

**Feed streams from the executor's live callbacks.** Rejected: they fire before
the step is stored, so a client could see a state the database never held.

**Use** :php:`QueuedRunCoordinator::keepAnswer()`. Rejected: it writes after
completion and only logs a failure, keeps nothing after an approval, and stores
one text where a task has artifacts.

**Keep the result regardless of the answer ceiling.** Rejected: it would undo
ADR-270 for one store, and an operator who tightened a provider would find its
output stored anyway.

**Refuse only non-blocking calls under a no-storage profile.** Rejected: a
blocking call that reaches its cap needs the store just the same.
