.. include:: /Includes.rst.txt

.. _administration-data-retention:

======================
Data retention & purge
======================

The extension writes several kinds of row that can carry request content:
conversation transcripts, agent-run event payloads, evaluation output, the skill
audit trail and provider telemetry. All of them are governed by one central
privacy policy (:ref:`ADR-064 <adr-064>`): what is stored at all, and for how
long.

.. _administration-data-retention-what-is-stored:

What is stored
==============

The extension configuration setting :guilabel:`Content Privacy Level`
(``privacy.level``) decides how much request content is persisted:

``none`` / ``metadata`` (default)
   Content payloads are dropped. Metadata — timings, token counts, cost, tool
   names, sizes, error class — is kept. Agent-run steps are stored in this
   reduced form too, so persistence does not quietly build a prompt archive.

``redacted``
   Content is stored with obvious credentials and email addresses masked and the
   length capped. A heuristic, not a guaranteed PII scrubber.

``full``
   Content is stored verbatim. Choose this deliberately — for an agent run it
   means the whole transcript, including tool arguments and results.

Conversation messages are the exception: their content is the feature. A session
replays its own history on every turn, so the transcript is always stored — and
therefore governed by retention rather than by the level.

.. _administration-data-retention-how-long:

How long it is kept
===================

``privacy.retentionDays`` (default 30) is the window for every category. Each
category can override it under ``privacy.retention.*`` — set ``0`` to keep the
default:

.. list-table::
   :header-rows: 1

   * - Setting
     - Covers
   * - ``privacy.retention.conversation``
     - Conversation sessions and their message transcripts
   * - ``privacy.retention.agentRun``
     - Finished agent runs and their event payloads
   * - ``privacy.retention.approval``
     - Agent runs that never reached a terminal status, above all runs suspended
       for a human approval
   * - ``privacy.retention.telemetry``
     - Provider pipeline metadata (no prompts, no responses)
   * - ``privacy.retention.evaluation``
     - Graded evaluation output
   * - ``privacy.retention.skillAudit``
     - Skill scan findings

A zero, negative or non-numeric value never means "delete immediately" — it
means "no override".

Runs awaiting a decision are deliberately separate. A run suspended for an
approval carries the state needed to resume it, so it is only deleted on the
``approval`` window. Give it a longer value than ``agentRun`` if approvers may
take days.

.. _administration-data-retention-purging:

Running the purge
=================

Nothing is deleted until a purge runs. Schedule the central command — it covers
every content-bearing table in one pass:

.. code-block:: bash

   vendor/bin/typo3 nrllm:privacy:purge

It reports the window applied and the rows deleted per category. ``--days=N``
overrides every category at once, which is useful for a one-off cleanup:

.. code-block:: bash

   vendor/bin/typo3 nrllm:privacy:purge --days=7

Two single-table variants exist for operators who want separate schedules. They
read the same policy, so they cannot drift from it:

.. code-block:: bash

   vendor/bin/typo3 nrllm:session:purge
   vendor/bin/typo3 nrllm:telemetry:purge

All three appear in the scheduler's :guilabel:`Execute console commands` task —
no extra registration is needed.

.. note::

   Usage rows (``tx_nrllm_service_usage``) are **not** purged: they are the
   billing ledger the budget limits and the Analytics module report on. They
   hold no prompt content, only counts, cost and the acting backend user.
