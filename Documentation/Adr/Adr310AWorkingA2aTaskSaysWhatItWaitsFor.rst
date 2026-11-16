.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-310:

==================================================
ADR-310: A working A2A task says what it waits for
==================================================

:Status: Accepted
:Date: 2026-11-16
:Authors: Netresearch DTT GmbH

Context
=======

A task of the A2A server role mirrors its run (spec 051 A2A-SV10): a run that
waits for an approval in the backend (:ref:`ADR-084 <adr-084>`) or for a remote
agent (:ref:`ADR-281 <adr-281>`) leaves the task ``WORKING``, because neither
wait is the client's to end. A2A 1.0 has no state for a wait inside the agent;
``INPUT_REQUIRED`` and ``AUTH_REQUIRED`` ask the client for something.

The task view wrote a status message only for ``FAILED``, ``REJECTED`` and
``INPUT_REQUIRED``. The mirror also skipped every transition that kept the
state, so a running run that began to wait wrote nothing at all: no revision,
no event for a stream, no push. A client saw ``WORKING`` for a run that
computed and for one that waited days for a person, and could not tell them
apart.

Decision
========

1. **A fixed text per wait.** A run in ``waiting_for_approval`` gives its
   ``WORKING`` task the status message ``The task is waiting for an approval
   in TYPO3.``; a run in ``waiting_for_dependency`` gives it ``The task is
   waiting for a remote agent.`` The text names no run, record, workspace,
   person, agent or remote task, and links nowhere: the client is not a user
   of the backend, and which approval or agent is involved is the
   installation's information.
2. **The text is part of the state the mirror compares.** The mirror writes
   when the state or the status text differs from the task's, and only then.
   The resume after the wait, and the queued continuation of a stored
   decision, write ``WORKING`` without a message.
3. **No new path to the client.** The text is written in the transition's
   transaction like every mirror, so the projector journals it as a new
   revision, the stream sends it as a ``statusUpdate`` and push delivers it.
4. **The client's question stays apart.** ``INPUT_REQUIRED`` keeps the
   question of the run's input tool. An approval is never offered to the
   client as a question.

Consequences
============

● A client can tell a task that waits for this installation from one that is
being worked on, and sees when the wait ends, in every channel: ``GetTask``,
``ListTasks``, the stream and push.

● A transition that changes nothing visible still writes nothing, so a
stream gets no empty events and push sends no duplicates.

◐ The client learns that an approval or a remote agent is involved, and when
the wait starts and ends. It learns nothing about what is approved or which
agent is asked.

◑ The status message is content to the privacy guard (:ref:`ADR-282
<adr-282>`): a tightening of the answer level to ``metadata`` or ``none``
during the wait removes the text from the task and its events, as it removes
the fixed ``FAILED`` text. The next transition writes it again.

◑ A transition that keeps the state reads and opens the task's sealed status
message once to decide whether to write.

◑ A decision refused after its claim (a stale turn digest, an approver who may
not run the write, an approval that could not be recorded) ends the wait and
puts it back: the client sees one update without the text and one with it.
The decision paths claim before these checks on purpose (ADR-084, ADR-132); an input
pause shows the same two updates through its state.
