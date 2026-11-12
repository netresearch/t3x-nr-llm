.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-commands:

======================
Command-line operation
======================

Run commands from the TYPO3 project root. The installed command's ``--help``
shows its arguments and defaults; the ``main`` manual may describe commands
newer than your installed release.

.. code-block:: bash
   :caption: Inspect installed commands before scheduling them

   vendor/bin/typo3 list nrllm
   vendor/bin/typo3 nrllm:agent:work --help

.. _administration-commands-catalogue:

Command catalogue
=================

.. list-table:: Commands supplied by nr-llm
   :header-rows: 1
   :widths: 40 60

   * - Command
     - Purpose
   * - ``nrllm:agent:work``
     - Execute queued runs within run-count and time limits.
   * - ``nrllm:agent:status``
     - Inspect queue, worker and lease health.
   * - ``nrllm:agent:reap``
     - Reclaim or dead-letter runs with expired worker leases.
   * - ``nrllm:agent:cancel``
     - Cancel a queued, running or waiting run by UUID.
   * - ``nrllm:task:enqueue``
     - Queue a prepared task.
   * - ``nrllm:editor-action:batch``
     - Queue an action over a folder or page tree, with approval per run.
   * - ``nrllm:outcome:derive``
     - Observe what happened to AI-written records after their time window.
   * - ``nrllm:privacy:purge``
     - Delete expired rows across privacy-governed categories.
   * - ``nrllm:governance:purge``
     - Delete expired governance events only.
   * - ``nrllm:session:purge``
     - Delete inactive conversation sessions and their messages.
   * - ``nrllm:telemetry:purge``
     - Delete expired provider telemetry.
   * - ``nrllm:eval:run``
     - Run and grade a golden prompt set against a model.
   * - ``nrllm:eval:retrieval``
     - Evaluate retrieval hits against a golden question set.
   * - ``nrllm:mcp:import``
     - Import a configured MCP server's advertised tools.
   * - ``nrllm:a2a:import``
     - Import the agent card of an A2A remote agent record (:ref:`administration-a2a-remote-agents`).
   * - ``nrllm:a2a:send``
     - Send one message to an A2A remote agent, print the task id and exit.
   * - ``nrllm:a2a:get``
     - Print a task of an A2A remote agent that was sent from this installation.
   * - ``nrllm:a2a:poll``
     - Look at the remote tasks waiting runs depend on and continue the runs whose task settled or whose wait ran out (:ref:`administration-a2a-delegation`).
   * - ``nrllm:provider:set-key``
     - Read a provider key from standard input and store it through nr-vault.
   * - ``nrllm:usecasepack:install``
     - Create a pack's missing configuration, tasks and snippets.
   * - ``nrllm:exception:hint``
     - Inspect a logged exception and request a model-assisted diagnosis.
   * - ``nrllm:demo:seed``
     - Seed demonstration records and usage history; use in demo/test systems.

.. _administration-commands-outcomes:

Derive observed outcomes
========================

Schedule the outcome derivation periodically, for example daily. It reads
record history for AI writes whose observation window has closed, then stores
what happened to them. It does not call an LLM. No eligible writes is a normal
successful result.

.. code-block:: bash
   :caption: Observe records after seven days

   vendor/bin/typo3 nrllm:outcome:derive --days=7

The default window is seven days; values below one day are raised to one.
Keep sufficient TYPO3 record history to support the observations. An unknown
outcome means the available evidence cannot answer the question.

.. _administration-commands-purge:

Purge governance events separately
==================================

Prefer the central privacy purge when scheduling all retention categories.
For governance events alone:

.. code-block:: bash
   :caption: Apply configured governance retention

   vendor/bin/typo3 nrllm:governance:purge

``--days=<positive integer>`` overrides the configured governance window for
that invocation. This deletes rows; review the retention policy before
scheduling it. See :ref:`administration-data-retention`.

.. _administration-commands-cancel:

Cancel a run
============

.. code-block:: bash
   :caption: Cancel the run identified in Agent Runs

   vendor/bin/typo3 nrllm:agent:cancel '<run-uuid>'

The command uses a scoped CLI actor and reports failure for an unknown or
already finished run. It does not undo completed writes. Execution stops at a
cooperative boundary; an unsupported in-flight transfer can finish its step.
See :ref:`administration-agent-runs` for queue and worker setup.

.. _administration-commands-options:

Arguments, repetition and scheduling
====================================

All commands need the installed schema and a bootstrapped TYPO3 project.
Provider/MCP operations additionally need usable vault access. Workers use the
technical vault identity configured under :ref:`configuration-worker` while
record authorization still follows the stored run actor.

.. list-table:: Inputs and repeated-run effects
   :header-rows: 1
   :widths: 25 42 33

   * - Command suffix (``nrllm:``)
     - Arguments and options
     - Repetition and scheduling
   * - ``agent:work``
     - ``--limit=20``, ``--time-limit=240`` seconds by default.
     - Sweep repeatedly or run a supervised async consumer. The time limit
       stops new starts, not a run already executing.
   * - ``agent:status``
     - ``--json``, ``--max-queue-wait=300``, ``--minimum-workers=1``,
       ``--worker-max-age=120``, ``--transport=doctrine``.
     - Read-only health check; choose thresholds for the actual transport.
   * - ``agent:reap``
     - ``--limit=50``.
     - Inspect expired leases; retries/dead-letter follow run state and retry
       policy. It is not a blanket replay of writes.
   * - ``agent:cancel``
     - Required run UUID.
     - Guarded cancellation; terminal/unknown runs report no cancellation.
   * - ``task:enqueue``
     - Required task identifier; ``--input`` for manual text.
     - Repeating queues another run. Automatic sources are read at enqueue.
       The CLI actor has configuration-use scope, not editor write privileges.
   * - ``editor-action:batch``
     - Required action; ``--user`` username; folder or page-tree scope;
       ``--depth=99``, ``--only-empty``, ``--instruction``.
     - Requires a usable acting backend user. Repetition can queue additional
       proposals; review origin/deduplication behavior before scheduling.
   * - ``outcome:derive``
     - ``--days=7``; minimum observation window one day.
     - Suitable for a daily job; already-derived observations are not new
       provider calls. Needs retained record history.
   * - ``privacy:purge``
     - ``--days`` overrides every category window for this invocation.
     - Deletes expired governed rows. Normally schedule with per-category
       configuration, after reviewing pending-approval expiry.
   * - ``governance:purge``
     - ``--days``; positive integer, configured window by default.
     - Deletes governance rows only; repeated calls leave no older rows.
   * - ``session:purge``
     - ``--days``; configured conversation window by default.
     - Deletes inactive sessions and messages; retain/export needed history first.
   * - ``telemetry:purge``
     - ``--days``; configured telemetry window by default.
     - Deletes expired telemetry; affects the evidence available to routing.
   * - ``eval:run``
     - Required set; ``--grader``, ``--provider``, ``--model``,
       ``--max-pass-rate-drop``, ``--max-mean-score-drop``,
       ``--fail-on-regression``.
     - Calls a provider and stores another evaluation. Use an explicit
       deterministic benchmark/revision for comparable quality routing.
   * - ``eval:retrieval``
     - Required set and retriever; ``--max-top1-drop``, ``--max-top3-drop``,
       ``--fail-on-regression``.
     - Stores retrieval evidence; comparison needs compatible provenance.
   * - ``exception:hint``
     - ``--index=0``, ``--search``, ``--configuration``.
     - Reads logs and asks a model; can spend quota. Run on demand, not as
       an unrestricted periodic export of error details.
   * - ``mcp:import``
     - Server identifier or ``--all``.
     - Contacts servers and reconciles catalogue rows; does not enable every
       imported tool. An unchanged catalogue is reconciled again.
   * - ``provider:set-key``
     - Required provider identifier; secret on standard input only.
     - Replaces that provider's linked key. Run during credential provisioning,
       not as a scheduled task that cannot supply standard input.
   * - ``usecasepack:install``
     - Required pack identifier.
     - Creates missing records only; an unchanged existing pack is a no-op.
       Does not enable tool groups or editor actions for the operator.
   * - ``demo:seed``
     - ``--days=90``; ``--days=0`` skips synthetic usage; ``--force`` permits
       the otherwise-refused production context.
     - Writes demo fixtures/history. Use explicitly in test/demo systems.

A daily central purge can cover the category-specific purge commands; do not
schedule both without a reason. A daily outcome derivation is independent of
purge. Choose retention longer than the observation windows your reports need.
Check the installed command's help and exit status when wiring cron or TYPO3
Scheduler; only commands registered as schedulable appear there.
