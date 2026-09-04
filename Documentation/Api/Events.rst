.. include:: /Includes.rst.txt

.. _api-events:

======
Events
======

PSR-14 events this extension dispatches, and what you may rely on about them.
Each is ``@api``: within a major version it is not removed, its properties do
not disappear and their types do not change. Listeners are registered the
ordinary TYPO3 way, in your own extension's :file:`Configuration/Services.yaml`.

.. _api-events-after-ai-record-written:

AfterAiRecordWrittenEvent
=========================

:php:`Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent`

Dispatched once per successful editorial write, after the write has landed and
been read back by the tool that made it. It answers three questions and no
fourth.

.. confval:: correlationId

   :type: string

   The agent run's uuid, which is the correlation id of everything that run did.
   It is what :sql:`tx_nrllm_agentrun.uuid` holds, so a listener that needs the
   run — its actor, its configuration, its trace — resolves it by this value.
   Never empty.

.. confval:: record

   :type: :php:`Netresearch\NrLlm\Domain\ValueObject\RecordReference`

   The row the write produced or changed: a table name and a uid, and
   :php:`__toString()` renders them as ``pages:42``.

.. confval:: kind

   :type: :php:`Netresearch\NrLlm\Domain\Enum\WriteKind`

   ``WriteKind::CREATED`` when the record did not exist before the call, and
   ``WriteKind::UPDATED`` when it did. There is no deletion case, because no
   builtin tool deletes.

A listener
----------

.. code-block:: php

   final readonly class LabelAiWrittenPages
   {
       public function __invoke(AfterAiRecordWrittenEvent $event): void
       {
           if ($event->record->table !== 'pages') {
               return;
           }

           $this->labels->record($event->record->uid, $event->kind, $event->correlationId);
       }
   }

.. code-block:: yaml

   services:
     Vendor\Extension\EventListener\LabelAiWrittenPages:
       tags:
         - name: event.listener
           identifier: 'vendor/label-ai-written-pages'

What it deliberately does not carry
-----------------------------------

**No record payload.** Not the field values, not a before/after, not a rendered
excerpt, not the tool's name. A payload is a copy, and a copy of editorial
content is a second place for it to leak from and a second place for it to go
stale. :confval:`record` names the row; read it yourself, under your own
permissions, at the moment you need it.

**No listener of ours.** Nothing in this extension listens to this event, and
nothing is planned to. An Article 50 transparency label, a badge in the page
module, a line in an existing editorial audit trail and a nightly compliance
report are four different artefacts with four different owners; which of them
your site wants is your decision (:ref:`ADR-187 <adr-187>`).

**No actor.** Which backend user the run acted for is persisted on the run;
resolve it by :confval:`correlationId` rather than expecting a second copy of an
identity on every event.

Two properties worth knowing before you write a listener
--------------------------------------------------------

**It fires before the run trace persists its step for the same call.** Do not
try to join the run's trace for this write — it is not there yet. Everything the
event promises is on the event.

**A listener that throws is logged, not propagated.** The write has already
landed and a human has already approved it, so a broken listener does not fail
the run — it produces an ``error``-level log entry naming the record. Do not
rely on an exception to signal anything back to the extension.

**It can fire twice for one record.** The agent queue is at-least-once: a run
whose lease is lost may be re-executed, and an idempotent write that runs twice
dispatches twice with the same :confval:`correlationId` and :confval:`record`.
Deduplicate on that pair if your listener must act once per record. A
non-idempotent write is never auto-retried, so it cannot double this way.
