.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _administration-a2a-server:

==========
A2A server
==========

nr-llm can answer other agents that speak the `Agent2Agent protocol
<https://a2a-protocol.org/v1.0.1/specification/>`__ (A2A) 1.0 over JSON-RPC.
It publishes selected LLM configurations as skills, and a registered remote
client sends a message to one of them. The message becomes an agent run that a
worker executes; the client receives the result as an A2A task
(:ref:`ADR-280 <adr-280>`, :ref:`ADR-282 <adr-282>`, :ref:`ADR-283 <adr-283>`).

This page covers stages 3 and 4 of spec 051: tasks, and a client that
answers a task waiting for input. Streaming, push notifications and file
parts are not offered (stages 5a to 5c).

Switch it on
============

#. Set :guilabel:`Answer A2A requests (server role)`
   (``a2a.server.enabled``) in the extension configuration. Off, no site
   answers an A2A request, whatever its settings say.
#. Name the base URL in the settings of each site that should answer, for
   example in :file:`config/sites/<site>/settings.yaml`:

   ..  code-block:: yaml

       nrllm:
         a2a:
           baseUrl: 'https://www.example.org/a2a'

   The JSON-RPC endpoint is this URL; the agent card is published at
   :file:`https://www.example.org/a2a/.well-known/agent-card.json`. A site
   without the setting answers neither. Every URL in the card and in a
   response is built from this setting, never from the request's ``Host``
   header; a request to another host, scheme or port is not an A2A request
   and passes on to the site.
#. Run a worker. A task is accepted only while a worker can be shown to run:
   a ``messenger:consume`` consumer with a heartbeat, or a tick of
   ``nrllm:agent:work`` within the last 120 seconds. Schedule
   ``nrllm:agent:work`` every minute on an installation without a consumer.
   ``nrllm:agent:status --transport=sweep --minimum-workers=1`` checks the
   sweep. Without a worker, ``SendMessage`` answers HTTP 503 with
   ``WORKER_UNAVAILABLE`` and stores nothing.

Publish a skill
===============

Create an :guilabel:`A2A Publication Profile` record (administrators only).

Skill id, name, description, tags, examples
   What the agent card lists for the skill. The skill id is how a client
   names it.

Domain handler
   What turns the client's message into the run and the run's answer into
   the result. ``nr_llm.text`` sends the message text to the LLM
   configuration and returns the answer as one text artifact: a text check or
   a summary, with the instructions of the configuration's system prompt.

LLM configuration
   The configuration the runs use. If its answer level keeps no result
   (``metadata`` or ``none``, set on its provider), the profile cannot be
   public, and a call is refused before a task exists with
   ``RESULT_NOT_RETAINABLE`` (:ref:`ADR-282 <adr-282>`). A configuration that
   is switched off, or restricted to backend groups the client's technical
   user is not in, refuses a call with ``PROFILE_UNAVAILABLE``. The agent card
   lists a skill only while its handler is available and its configuration
   is switched on and keeps a result.

Tools
   Comma-separated tool names the run may use. Without :guilabel:`May write`,
   a profile offers only tools that declare that they only read: a list with
   any other tool is not stored, and an existing list stays as it was. Empty:
   no tools. A run can ask the client a question with
   ``ask_choice`` when the tool is listed here and enabled in the tool
   management; the task then waits for the client's answer (see below).

May write
   The run may use the profile's writing tools too. They write drafts only,
   into the pinned workspace of the calling client (:ref:`ADR-260 <adr-260>`);
   a writer that does not support workspaces refuses there. A client pinned
   to live cannot call a writing skill: the record is not saved that way, and
   a call is refused with ``-32004``. A write that needs an approval waits for
   a person of this installation, as in every run; the task stays
   ``WORKING`` meanwhile. The result of a writing task carries a second
   artifact, ``writes``, that lists every write the run executed (table,
   record uid, workspace, kind), taken from the write events, not from the
   model's answer; a run that wrote nothing says so.

Active task quota
   How many tasks of this skill, from all clients together, may be queued or
   running at once. ``0``: no limit of its own.

Public
   Listed on the card and callable. A profile that is not public cannot be
   called by any client.

Messages and results are ``text/plain`` only (files are stage 5c).

Register a client
=================

Create an :guilabel:`A2A Client` record (administrators only):

Technical backend user
   Every task of the client runs as this backend user, with exactly its
   permissions. It must be enabled and must not be an administrator; the
   record cannot be enabled otherwise, and a request is refused if the user
   changed since. Prepare a user for this purpose.

Workspace
   The workspace the client's runs act in; ``0`` is live. A client that may
   call a writing skill needs a draft workspace.

Skills it may call
   The publication profiles the client may use. A client allowed one skill
   needs to name none; a client allowed several names the skill in each
   message, as ``SendMessageRequest.metadata`` member
   ``https://nr-llm.netresearch.de/a2a/ext/profile/v1`` with the skill id as
   value. Otherwise the call is refused with ``PROFILE_REQUIRED``.

Task limit
   How many of the client's tasks may be queued or running at once.

Enabled, expires
   A disabled or expired client is refused like an unknown one.

Then issue its token:

..  code-block:: bash

    vendor/bin/typo3 nrllm:a2a:client:token <client uid>

The command prints the token once. The record keeps only its public prefix
and its SHA-256; nobody can read the token from the database. Running the
command again rotates the token: the old one stops working at once, and the
client keeps its tasks, which belong to the record, not to the token. The
client sends the token as ``Authorization: Bearer <token>``.

What a client gets
==================

``SendMessage``
   Creates a task and a queued run. The request never executes the run.
   Without ``returnImmediately``, the request waits until the task is
   finished, failed, cancelled, rejected or waiting for input, at most
   ``a2a.server.blockingWaitSeconds`` (default 20, at most 100). After that
   it answers HTTP 504 with JSON-RPC ``-32603``, reason
   ``BLOCKING_DEADLINE_EXCEEDED`` and the task id; the task goes on and is
   read with ``GetTask``. A repeated ``messageId`` of the same client returns
   the task the first one created.

Answering a task (``SendMessage`` with ``taskId``)
   A task whose run asks a question is ``INPUT_REQUIRED``; its status
   message holds the question and, where the question offers a choice, the
   answers it accepts. The client answers with a message that carries the
   task's ``taskId`` (and its ``contextId``, or none: the task's is used).
   The text is the answer to a question with one free or chosen answer; a
   question with several values takes a JSON object. The answer is checked
   like one from the backend (:ref:`ADR-281 <adr-281>`): it must fit the
   question, and the client's technical user must be allowed to run the
   tool that asked. It is then stored, the run is handed to the worker and
   the task is ``WORKING``; nothing runs in the request. A task that does
   not wait for the client, or has ended, takes no message (``-32004``); a
   finished task is never reopened. Only the client's own task can be
   answered, in its own context and its own skill. Before the run goes on,
   and at every later resume, the client record, its technical user, its
   workspace and the skill are checked again; a client disabled or expired,
   a skill withdrawn from it or no longer public, a changed technical user or
   workspace, or a tool the run was given that the skill no longer grants
   (removed from it, or a writing tool after :guilabel:`May write` was
   cleared) fails the task. A repeated ``messageId`` returns the task.

   ``AUTH_REQUIRED`` is never set: no run of this installation waits for a
   credential that a client supplies out of band.

``GetTask``, ``ListTasks``, ``CancelTask``
   Only the client's own tasks. Another client's task and a task that does
   not exist are the same ``-32001``. ``ListTasks`` sorts by status time,
   newest first, pages with an opaque token bound to the client, its skills
   and the filter, and leaves out artifacts unless ``includeArtifacts`` is
   true.

Task states
   ``SUBMITTED`` while queued, ``WORKING`` while running (also while the run
   waits for an approval in the backend or for a remote agent, and after the
   client answered), ``INPUT_REQUIRED`` while the run waits for the client's
   answer, ``COMPLETED``
   with the answer, ``FAILED`` with a fixed message that names no internal
   detail, ``CANCELED``, and ``REJECTED`` when the input was refused before
   the run started. The result is kept at the run's answer level and purged
   with the run (:ref:`ADR-282 <adr-282>`).

Limits and protection
=====================

- **Proxy timeouts.** A waiting request holds a PHP-FPM worker. Keep
  ``a2a.server.blockingWaitSeconds`` below the read timeout of every proxy in
  front of PHP-FPM; a proxy that cuts the request earlier leaves the client
  with a transport error and the task unchanged.
- **Waiting requests.** At most ``a2a.server.maxWaitingRequests`` (default 4)
  blocking requests wait at the same time on the installation; one more is
  refused with HTTP 503, ``WAITING_CAPACITY_EXHAUSTED``. A request with
  ``returnImmediately`` takes no waiting slot.
- **Request size.** A body above ``a2a.server.maxRequestBytes`` (default
  1 MiB) is refused with HTTP 413 before it is parsed; a message has at most
  16 parts. A task's history keeps the most recent 100 messages.
- **Failed logins.** A failed authentication is logged with the source
  address, never with the token, and counted per address: after 10 failures
  in 15 minutes a failed request from that address gets HTTP 429 until the
  window passes. A request with a valid token is not limited. The rate
  limiter is ``nrllm-a2a-auth``; override it like TYPO3's own limiters in
  ``$GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['nrllm-a2a-auth']``.
  Behind a reverse proxy, name it in
  ``$GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP']``; otherwise every
  client counts as the proxy's address and shares one limit.
- **Refusals** name their reason (``error.data`` holds a
  ``google.rpc.ErrorInfo``) and, where a retry may help, a ``Retry-After``
  header.
