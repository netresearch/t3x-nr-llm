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
has no session, and ``AbstractUserAuthentication::setAndSaveSessionData()``
does not check for one, so the hook failed with
``Call to a member function set() on null``. The record was published; the
chat said the tool failed, and the model's next move on a failed write is to
try it again.

The failure also cut the run short. After the hooks of an outermost run, TYPO3
updates the reference index, flushes the cache of every written page and
resets the run's registry (``DataHandler::process_datamap()``, identical in
13.4 and 14.3). None of that ran, so the published element stayed out of the
page cache.

The tools created their DataHandler in 27 places in 17 files. At 13 of them
the tool refuses as soon as the DataHandler's error log is not empty, before
it reads anything back.

Decision
========

**Every builtin writer runs** ``ToolDataHandler``, **a DataHandler that
tells a failure after the writes from one during them.** It looks at the
method the OUTERMOST run called when it failed:

- **After the writes** — a ``processDatamap_afterAllOperations`` or
  ``processCmdmap_afterFinish`` hook, the reference index update, which runs
  listeners and soft-reference parsers of the installation, or the cache
  flush, which runs hooks of its own. Every record of the run is written.
  The finishing steps that did not run yet — reference index update, cache
  flush, registry reset — run now; the hooks after the failing one in the
  same list do not. The failure is logged with its trace, and one line is
  recorded: the installation's code that failed, the exception class and its
  message. A database exception is named without its message, and any other
  message is stripped of every secret shape the org-wide catalogue knows,
  credentials in URLs among them, because the line reaches the language
  model. The tool loop adds the lines to the tool's answer as a note; the
  tool reads back and answers as usual.
- **During the writes** — anywhere else. Core writes a copy and a translation
  through a DataHandler of its own, so a hook that fails there fails during
  the writes of the tool's run, before core has recorded the new uid. Some
  records may be written and some not, and relations may still point at the
  source. The run finishes its steps, so the pages written so far are
  flushed, and the failure is rethrown: the call ends as failed, which is
  true, where a read-back of half a copy would report something false.

When the cache flush itself fails, it is not retried — it would fail the
same way — and its queue is emptied, or the next run in the same process
would replay it. A reference index update that failed is not retried either;
the records after the failing one stay unindexed until they are written
again.

**The DataHandler's error log stays as TYPO3 wrote it.** Written into it, a
failure after a landed write would make the writers answer "refused" at the
13 places where they refuse on any entry. Left out, each writer's own
read-back decides, as it does for every other outcome.

**A nested run rethrows.** A ``ToolDataHandler`` started from inside another
DataHandler run leaves the failure to that run, which still has its own
finishing steps ahead and a caller that must see it.

A unit test refuses ``makeInstance(DataHandler::class)``, a container lookup
of the core class and ``new DataHandler`` anywhere in ``Classes/``, so a new
writer cannot bypass the class.

Consequences
============

- On the demo, ``publish_record`` answers that the record is published, and
  the note names the translation extension's hook and its error.
- ``copy_record`` and ``create_translation_draft`` still end as failed when a
  hook fails in the run core copies through. Whether a copy or a translation
  exists then is unknown to the tool; the answer does not claim either.
- A failure during the writes of any other tool also still ends the call as
  failed. The difference to before is that the pages written so far are
  flushed and the run's registries do not leak into the next run.
- What the failed hook was meant to do after the writes — here the
  translation and its flash message — does not happen, and the note is the
  only trace of it in the chat. The note names at most three failures; the
  log has all of them.
- The code a failure is named by is the hook method the DataHandler called,
  whatever failed inside it. Where the DataHandler called TYPO3's own code, a
  hook called through ``GeneralUtility::callUserFunction()`` or a listener
  called through the event dispatcher inside it is named, also below a core
  service such as the reference index; any other core callee is named as it
  is, and library code deeper inside never is.
- The note says that the run had written its records when the code failed,
  and leaves the outcome of the call to the tool's own answer: the same note
  can follow a refusal or a write the tool took back.
- An XCLASS of the core DataHandler does not apply to the tools' own runs:
  ``ToolDataHandler`` extends the core class itself. The runs core starts for
  a copy or a translation still get it.
- ``ToolDataHandler`` is a public, non-shared service, resolved through
  ``makeInstance()`` like the core class it extends (Category E of the
  public-service policy; the audited count rises to 38, :ref:`ADR-101
  <adr-101>`).
- The class relies on protected members of the core DataHandler:
  ``referenceIndexUpdater``, ``processClearCacheQueue()``,
  ``resetElementsToBeDeleted()``, ``resetNestedElementCalls()``,
  ``$recordsToClearCacheFor`` and ``$recordPidsForDeletedRecords``, and on the
  names of the steps after the writes. These are the same in 13.4 and 14.3; a
  core release that changes a member fails the functional tests of this
  class, and one that renames a step turns a failure there into a rethrown
  one, which fails safe.
- A caller that runs a tool's ``execute()`` outside the tool loop gets no note.
  The failure is still logged.
- ``ToolLoopService::announceWrite()`` is no longer the only place in the
  write path that catches foreign code: it catches a listener after the tool
  returned, ``ToolDataHandler`` catches a hook or a listener after the last
  write of an outermost run.

Alternatives considered
=======================

**A session for the worker's user.** It would stop this one hook from
failing, but not any other failing hook, and it would make the worker behave
unlike TYPO3's own CLI, whose user has no session either.

**Catching in the tool loop only.** The loop already caught the exception. It
cannot tell whether the write landed, and it cannot run the finishing steps:
by the time the exception reaches it, the DataHandler that owns them is gone.

**Catching every failure of the run.** The first version of this change did,
and a review found where it goes wrong: a hook that fails in the run core
copies through leaves the copy in the database without its uid in core's
record of the copy, and ``copy_record`` then answered "the copy was not
made". A caught failure has to leave the tool something true to read back.

**Writing the failure into the DataHandler's error log.** Every writer would
pass it on unchanged, but at 13 places the writers refuse on any entry before
they read back, and would answer "refused" for a write that landed.

**Fixing the translation extension.** Its hook should not store a flash
message in a process without a session, and that is worth reporting to its
maintainers. It does not protect a tool against the next hook that fails.
