.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=============================
Queues and background answers
=============================

.. _administration-agent-runs-async:

Running queued runs asynchronously
==================================

A consumer of the API that calls ``enqueue()`` gets an **in-process**,
synchronous run by default, with no setup. For genuinely asynchronous execution,
route the queue message to the Doctrine transport and run a consumer, or leave
the routing alone and queue with ``defer()``, which
:ref:`administration-agent-runs-background` describes:

.. code-block:: php

   // settings.php / additional.php
   $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing']
       [\Netresearch\NrLlm\Service\Agent\Queue\AgentRunQueuedMessage::class] = 'doctrine';

.. code-block:: bash

   vendor/bin/typo3 messenger:consume doctrine

.. warning::

   Once you route the message to ``doctrine`` you **must** run a consumer or
   ``nrllm:agent:work``. Without one, queued runs are never picked up, and the
   stale-run reaper below has no worker to hand reclaimed runs back to.

.. _administration-agent-runs-background:

Background tasks from the command line and the scheduler
========================================================

An AI Task can run without anyone waiting for it. Two commands do that
(:ref:`ADR-234 <adr-234>`). Both appear in the scheduler under
:guilabel:`Execute console commands`.

.. code-block:: bash
    :caption: Queue a task, then work the queue

    vendor/bin/typo3 nrllm:task:enqueue release-notes --input="the text to work on"
    vendor/bin/typo3 nrllm:agent:work --limit=20 --time-limit=240

``nrllm:task:enqueue <identifier>`` stores one queued run and returns; no
provider is called. A task with manual input takes it from ``--input``. A task
that reads a source, such as the system log, reads it at this moment. To repeat
a task, schedule this command with the task's identifier as its argument.

``nrllm:agent:work`` executes queued runs, oldest first. ``--limit`` (default
``20``) and ``--time-limit`` (seconds, default ``240``) bound one tick; a run that
has started finishes. Set the scheduler frequency above the time limit. Runs left
over wait for the next tick. A run that fails is listed as failed and the command
still succeeds; the command fails only when the queue cannot be read. A run that
fails for a temporary reason, such as a rate limit, returns to the queue and is
tried again at the next tick, three times at most.

On the command line no backend user is logged in, so nr_vault refuses the provider
key unless ``allowCliAccess`` is on. Set :confval:`worker.technicalBeUserUid
<ext-nr-llm-worker-technical-be-user-uid>` to a backend user with read access to
the secret. Without either, every run fails on its first model call.

Before it claims a run, the command asks the budget. A run the user's budget or
the configuration's daily limit would refuse stays queued, and the runs behind it
continue, so a limit that resets at midnight delays work without losing it. A
Messenger consumer does not ask: routed to ``doctrine``, a run it picks up meets
the refusal and ends there.

The worker and a Messenger consumer can run side by side: a run is claimed
atomically, so exactly one of them executes it. With the default routing no
consumer is involved. Routed to ``doctrine``, a queued task also wakes the
consumer.

The run acts as a service account that may use the configuration
(:ref:`ADR-110 <adr-110>`), not as the ``_cli_`` user, and no per-user budget
applies to it; the configuration's own limits do. A task calls no tool, so it
writes nothing. Its configuration is the task's own or the active default. Its
usage appears under the task in the usage figures.

:guilabel:`Background runs` in the Agent Runs module lists these runs in every
status, queued ones included, with what queued them. A run a service account
queued has no owner: an administrator sees and opens it, a holder of the *Approve
suspended AI runs* grant sees it without its detail page, and an editor sees
neither.

An installation that runs only ``nrllm:agent:work`` has no consumer heartbeat.
Pass ``--minimum-workers=0`` to ``nrllm:agent:status``, and use
``--max-queue-wait`` to learn that no tick is running.

Its detail page shows the answer. A background run keeps its answer in
``tx_nrllm_agentrun.answer``, encrypted like a queued request, until the purge
removes the row. Unlike the step log, this does not follow the default
``privacy.level = metadata``, because the answer is the result nobody watched
arrive: ``none`` keeps nothing and ``redacted`` keeps the redacted text. See
:ref:`administration-data-retention`. A run that went through an approval keeps no
answer, because what it did is the write.
