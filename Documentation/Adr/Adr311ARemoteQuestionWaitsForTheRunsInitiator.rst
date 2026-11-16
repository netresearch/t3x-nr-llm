.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-311:

========================================================
ADR-311: A remote question waits for the run's initiator
========================================================

:Status: Accepted
:Date: 2026-11-16
:Amends: :ref:`ADR-281 <adr-281>` (a delegation whose remote task asks a
    question is no longer resumed with the question as its result)
:Authors: Netresearch DTT GmbH

Context
=======

A run that delegates a step to a remote A2A agent waits in
``waiting_for_dependency`` until the remote task settles (:ref:`ADR-281
<adr-281>`). A task that stopped in ``TASK_STATE_INPUT_REQUIRED`` or
``TASK_STATE_AUTH_REQUIRED`` counted as settled: the run went on with the
remote's text as the tool's result, the model read it, and nothing could
answer the remote task, which stayed open on the remote side.

Three things must not happen when the question is answered. The model must
not answer it, because the delegation was approved for what the run sent, not
for what the model would say next. The remote side must not pick the task the
answer goes to. And a request for authentication must never lead this
installation to send a credential of its own.

Decision
========

1. **A question is a pause of the run.** When the poller sees the remote task
   in ``INPUT_REQUIRED``, it claims the run like a resume and suspends it in
   ``waiting_for_input``. The pending call is the delegation, the input is one
   required text ``answer`` whose title is the remote's question marked as the
   remote agent's, and the dependency checkpoint stays in the sealed state
   beside it. No model call is made. The delegation tool no longer treats an
   interrupted task as its result; the poller is the one place that converts.
2. **Only the initiator answers.** The answer is taken in by the existing
   input paths: the backend's input form, the durable intake for the worker
   and, for a run started by an A2A client, that client's message. Beyond
   their checks, the answer must come from the backend user who started the
   run (``isInitiatorOf()``); an administrator or a holder of the approval
   grant is refused, in the request and again in the worker. A service account
   cannot answer.
3. **The binding is local.** The remote task id, context id, agent and owner
   come from the checkpoint and the original journal row. The answer is sent
   with that task and context id through the send journal, as an intent that
   continues the first one; its message id is derived from the run, the
   checkpoint and the question's marker, never from the claim, so a repeated
   execution or a replay after a crash gets the existing intent back.
   A response about another task is not followed. Every rewrite of the pause,
   such as the release after a refused answer, keeps the checkpoint. The
   checkpoint is bound to the endpoint that asked (a digest of the record's
   stored card), and an answer is sent only while the record still points
   there and the record object in memory matches the stored row; an answer
   is also bound to the pause its checks read, so a question answered and
   asked again before the answer's claim refuses it.
4. **The pending call is never executed again.** The answer goes to the
   remote task; a settled task answers the pending call as a dependency
   resume does, and any other state puts the run back into
   ``waiting_for_dependency`` on the same task, with a fresh wait of the
   record's maximum from the send and push registered again.
5. **The deadline holds.** While nobody answers, the delegation's deadline
   still applies: the poller resumes the run with the timeout result and
   cancels the remote task once. A remote task whose question is left open
   because its run ended another way is cancelled once by the next tick.
6. **Authentication is not answered.** A task in ``AUTH_REQUIRED`` is waited
   out: the poller keeps reading it and sends nothing else, and the timeout
   result says that the remote asked for authentication. The credential of
   the record is the one nr-vault adds to every request, as before; no other
   credential, token or message is ever sent in reply.
7. **An answer is not a delegation.** The record's delegations per run count
   first sends only; an answer's bytes count towards the run's total.

Consequences
============

● A remote agent can ask, the person who started the run answers in the
place where every input pause is answered, and exactly the remote task that
asked goes on.

● Nothing the remote says chooses where an answer goes or who may give it.

◐ The answer is the initiator's own text, passed through the shared secret
redactor. It is not approved again, even where the delegation needed an
approval: the approval was for the model's message, the answer is a person's.

◑ An inbound A2A client answers its run's remote question only where its
technical user may delegate at all; the delegation tools are admin-only, so
in a usual setup such a run cannot delegate.

◑ A question's pause is not listed among the waits for a remote agent but
among the input pauses, because that is where it is answered.

◑ The cancel of a question whose run ended another way waits for the next
poller tick.

◑ A remote task that stays in ``AUTH_REQUIRED`` holds the run until the
deadline; the initiator learns of it only from the timeout result.
