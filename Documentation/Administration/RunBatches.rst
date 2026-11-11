.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=============
Batch actions
=============

.. _administration-agent-runs-batch:

A batch over a folder or a page tree
====================================

An editor action can run over a whole folder or page tree
(:ref:`ADR-236 <adr-236>`). In the Editor Action Center, open
:guilabel:`Run this on several records` under the action, and choose a folder
(:guilabel:`Choose folder`, for files) or the page at the top of the tree
(:guilabel:`Choose page`, for pages) in TYPO3's element browser. In the file
list, :guilabel:`AI actions` in the context menu of a folder lists the actions
on files, and :guilabel:`Run this on the files in this folder` under one of
them opens this page with that folder already chosen
(:ref:`ADR-267 <adr-267>`). From the command line or the scheduler:

.. code-block:: bash
    :caption: Alternative texts for a folder, from the image content

    vendor/bin/typo3 nrllm:editor-action:batch set_file_alternative_text \
        --user=jane --folder=1:/products/
    vendor/bin/typo3 nrllm:agent:work

**A batch writes nothing.** Each record becomes one ordinary run, and each run
stops at its approval card with the generated text. The field keeps its value
until somebody approves that card, so a folder of 100 images is 100 cards, each
decided on its own. There is no switch for writing directly.

``--user`` names the backend user the runs act as. Their rights decide which files
and pages are reached, a file they may not edit stays out, and the cards appear in their inbox. It must be an active
user and cannot be ``_cli_``. A user disabled later makes the run fail.

The image is read when the worker picks the run up, as that user. A file that
cannot be sent any more fails its run, and no model is asked. See
:ref:`configuration-vision` for folders to exclude.

A batch can be repeated. A card waiting for approval, a rejected card and a model
that declined keep their record out until the run is purged. A run that failed,
was cancelled or ran out of budget decided nothing, so its record is tried again.
So is the record of a card that was approved but whose write failed: the run
completes, because the model is told, and the record is as it was.
A vision action
skips a file that holds a text in one of the fields it fills, and it leaves out
fields switched off in :ref:`configuration-vision`. A daily schedule therefore
picks up new files and never replaces a text. ``--only-empty=<column>`` adds a
field to that check, for example ``--page-tree=12 --only-empty=description`` on
``update_page_metadata``.

For a page the model gets the text of the page and its content elements, as the
page stood when the batch was queued. A tree holds the pages ``--user`` may edit
inside their mounts, without folders and recyclers.

A batch started in the Editor Action Center while the editor works in a draft
workspace runs in that workspace: the tree, the empty fields and the page text
are read as the workspace shows them, and each run records the workspace, so an
approved text is stored there as a draft, wherever the editor works when the
card is decided. Whether a tool writes into a workspace at all is the tool's
own decision; one that does not refuses there. A card decided in a draft
workspace does not keep its record out of a live batch, nor the reverse. The
command and the scheduler run in the live workspace, whatever workspace
``--user`` last worked in, and need a user who may work in live
(:ref:`ADR-265 <adr-265>`).

At most 100 runs of one action are open at a time, the number of cards the inbox
shows. A file the action cannot handle, such as a PDF for a vision action, takes no
place among them. The command reports the rest, and a later batch picks them up
once cards are decided.

Worker operating profile
========================

Deploy web processes separately from Doctrine consumers. Each consumer executes
one message at a time; start a bounded number of processes against the same
transport to control concurrency. Atomic run claims prevent duplicate ownership
when two consumers receive the same run. Size concurrency against model rate
limits, memory per request and database capacity.

TYPO3 14.3 supports the Symfony-style consumer limits:

.. code-block:: bash
    :caption: Bounded TYPO3 14.3 consumer

    vendor/bin/typo3 messenger:consume doctrine --time-limit=3600 \
        --memory-limit=256M --limit=100

On TYPO3 13.4, use the existing ``messenger:consume doctrine`` command with a
supervisor and the core's configured stop-worker listeners; the Symfony-style
options above are available from TYPO3 14.2. A supervisor should restart exited
consumers, stop them gracefully and leave enough time for a running model call
to finish. The existing non-idempotent write fence still governs recovery when
a process is killed during a write.

Run the reaper periodically, for example once per minute with ``--limit=50``.
Its lease duration must exceed the maximum provider/tool request duration plus
the scheduling margin. A reaper retry is bounded by the runtime's existing
retry budget; it is not an alternative message broker.

.. code-block:: bash
    :caption: Aggregate worker and queue health

    vendor/bin/typo3 nrllm:agent:status --json --transport=doctrine \
        --minimum-workers=1 --worker-max-age=120 --max-queue-wait=300

The command returns success when no run lease is expired, all queued timestamps
are known, queue wait is within the limit and the requested transport has enough
live consumers. It returns failure for an unhealthy snapshot, or invalid for
malformed options. ``--minimum-workers=0`` explicitly disables the consumer
requirement for a synchronous-only installation. Counts of running runs include
interactive runs and must not be interpreted as a count of consumer processes.

Consumers renew a random process heartbeat at most once per 30 seconds, including
when idle. Only consumers serving the transport's ``default`` queue count; a
consumer restricted to other queue names cannot establish its health. Stopped
consumers remove their entry; active heartbeat updates prune entries older than
24 hours. Transport names contain no user or host identity.

Heartbeat storage and stop cleanup are best effort. A telemetry failure does not
stop consumption; failed heartbeat attempts retry at most once per 30 seconds
with the same process identity. Failed cleanup can leave a heartbeat until it
ages out. Warnings contain only the operation and exception class, without
connection details. Missing or stale observations remain visible to monitoring;
a failed status read is an error and does not produce a healthy snapshot.

A long blocking model/tool call delays the next consumer heartbeat. Set
``--worker-max-age`` above the configured call timeout and polling margin. A
heartbeat proves that the consumer event loop ran recently; it cannot prove
that a remote provider is available. Monitor provider failures separately.

Queue-entry time is recorded for each enqueue/requeue, rather than guessed from
run age. Legacy or future timestamps contribute to ``unknown_queue_wait``.
``oldest_queue_wait_seconds`` is null when no measured queued row exists, and
zero when a measured row has just entered the queue. JSON contains aggregate
counts and durations only, without prompts, document data, actors or secrets.
