.. include:: /Includes.rst.txt

.. _adr-206:

====================================================================
ADR-206: A hook that fails does not fail the write it ran after
====================================================================

:Status: Accepted
:Date: 2026-09-24
:Authors: Netresearch DTT GmbH

Context
=======

Every writing tool writes through TYPO3's DataHandler, and every DataHandler
run calls the hooks and listeners of the installation. When one of them
threw, the exception left ``process_datamap()`` or ``process_cmdmap()``, the
tool never reached its read-back, and the tool loop answered
``Error: tool "…" failed.``

On the Netresearch demo that happened to ``publish_record``. A translation
extension queues a flash message for the session in its
``processDatamap_afterAllOperations`` hook. The agent worker's backend user
has no session — TYPO3's own CLI user ``_cli_`` has none either, and
``AbstractUserAuthentication::setAndSaveSessionData()`` does not check for
one — so the hook failed with ``Call to a member function set() on null``.
The record was published; the chat said the tool failed, and the model's next
move on a failed write is to try it again.

The failure also cut the run short. After the hooks of an outermost run, TYPO3
updates the reference index, flushes the cache of every written page and
resets the run's registry (``DataHandler::process_datamap()``, identical in
13.4 and 14.3). None of that ran, so the published element stayed out of the
page cache.

The tools created their DataHandler in 27 places in 17 files, and 12 of those
places refuse as soon as the DataHandler's error log is not empty, before they
read anything back.

Decision
========

**Every builtin writer runs** ``ToolDataHandler``, **a DataHandler that
finishes a run a hook broke off.** When ``process_datamap()`` or
``process_cmdmap()`` of the OUTERMOST run throws, it:

- runs the finishing steps the failure skipped — the reference index update,
  the cache flush and the registry reset — each on its own, so one that fails
  in turn does not keep the next from running;
- logs the failure with its trace;
- records one line naming the method the DataHandler called, the exception
  class and its message. A database exception is named without its message,
  which can carry connection details.

The tool loop takes these lines around every tool call and adds them to the
tool's answer, after the tool's own text: the answer says what the tool read
back, and the note says which hook failed while it wrote.

**The DataHandler's error log stays as TYPO3 wrote it.** Written into it, a
hook failure after a landed write would make the twelve writers that refuse on
any entry answer "refused". Left out, each writer's own read-back decides, as
it does for every other outcome.

**A nested run rethrows.** A ``ToolDataHandler`` started from inside another
DataHandler run leaves the failure to that run, which still has its own
finishing steps ahead and a caller that must see it.

A unit test refuses ``makeInstance(DataHandler::class)`` and
``new DataHandler(`` anywhere in ``Classes/``, so a new writer cannot bypass
the class.

Consequences
============

- On the demo, ``publish_record`` answers that the record is published, and
  the note names the translation extension's hook and its error.
- A hook that fails in the MIDDLE of a run that writes several records leaves
  some written and some not. Each writer reads back the record it is about;
  a side record — a translation copied along, a file reference — can be
  missing without the writer noticing. The note tells the model that a hook
  failed during the write.
- An exception thrown by core's own DataHandler code is treated the same way,
  and the note names the core method.
- What the failed hook was meant to do — here the translation and its flash
  message — does not happen, and the note is the only trace of it in the chat.
- ``ToolDataHandler`` is a public, non-shared service, resolved through
  ``makeInstance()`` like the core class it extends (Category E of the
  public-service policy; the audited count rises to 38, :ref:`ADR-101
  <adr-101>`).
- The class relies on protected members of the core DataHandler:
  ``referenceIndexUpdater``, ``processClearCacheQueue()``,
  ``resetElementsToBeDeleted()`` and ``resetNestedElementCalls()``. They are
  the same in 13.4 and 14.3; a core release that changes them fails the
  functional tests of this class.
- A caller that runs a tool's ``execute()`` outside the tool loop gets no note.
  The failure is still logged.
- ``ToolLoopService::announceWrite()`` is no longer the only place in the
  write path that catches foreign code: it catches a listener after the tool
  returned, ``ToolDataHandler`` catches a hook during the write.

Alternatives considered
=======================

**A session for the worker's user.** It would stop this one hook from
failing, but not any other failing hook, and it would make the worker behave
unlike TYPO3's own CLI, whose user has no session either.

**Catching in the tool loop only.** The loop already caught the exception. It
cannot tell whether the write landed, and it cannot run the finishing steps:
by the time the exception reaches it, the DataHandler that owns them is gone.

**Writing the failure into the DataHandler's error log.** Every writer would
pass it on unchanged, but twelve of them refuse on any entry before they read
back, and would answer "refused" for a write that landed.

**Fixing the translation extension.** Its hook should not store a flash
message in a process without a session, and that is worth reporting to its
maintainers. It does not protect a tool against the next hook that fails.
