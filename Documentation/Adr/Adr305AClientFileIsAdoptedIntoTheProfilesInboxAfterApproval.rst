.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-305:

==============================================================================
ADR-305: A client's file is adopted into the profile's inbox after an approval
==============================================================================

:Status: Accepted
:Date: 2026-11-16
:Amends: :ref:`ADR-284 <adr-284>` (adoption: who writes, where, under which approval, with which collision rule)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-284 <adr-284>` decided that adopting a file an A2A client sent into
FAL is an authorised write of its own: it names the target storage, folder,
file name and collision rule, and an actor allowed to write there performs it.
It did not say which actor, which target or which approval.

The FAL export of A2A-FILE6 (``send_fal_file``) was built without the
adoption, for one reason: a ``sys_file`` row has no workspace version
(``sys_file`` has no ``ctrl.versioningWS``), so a file adopted from a run in a
draft workspace is a live record at once. Spec 051 A2A-MT5 allows a writing
profile drafts only, in the client's pinned draft workspace, and
:ref:`ADR-265 <adr-265>` lets the field fill neither plan nor write a table
without versions in a draft workspace, because core would write it live.
Read together, an A2A run could not adopt at all.

Two facts bound when an adoption can happen. A task's input files give up
their bytes in the transaction that ends the task, whatever its outcome
(:php:`A2aTaskStore`, A2A-FILE4), and a file is deleted once it expires
(``a2a.files.retentionHours``, :php:`A2aFileStore::purgeExpired()`).

The owner decided the adoption on 2026-11-16, restricted to the five points
below.

Decision
========

**1. The target is the profile's inbox, and only that.** A publication profile
names one folder as a combined identifier, such as ``2:/a2a-inbox/`` (the new
field "Inbox folder for client files", ``fal_inbox_folder``). Neither the
client nor the model chooses a folder or a storage; the tool
``adopt_client_file`` takes only the number of the client's file. A profile
without a folder adopts nothing. The folder must exist and must not be public.
A target is public when any of these holds:

- its storage record is marked public (``sys_file_storage.is_public``);
- its storage uses the ``Local`` driver with a relative base path: core takes
  that from the web root (``Environment::getPublicPath()``), so it lies there
  however the directory links;
- its storage uses the ``Local`` driver with an absolute base path that lies
  inside the web root as written or as resolved, compared with the web root
  as written and as resolved. Core's Local driver derives the public URL of a
  public storage from the written path; a link can bring the resolved path
  into the web root as well;
- the folder itself, resolved, lies inside the web root: a folder of the
  storage that links there;
- the folder is or lies below a processing folder of the storage, where core
  skips the file mount check, or is a ``_recycler_`` folder. Processing
  folders count as core counts them (``ResourceStorage::getProcessingFolders()``
  and ``isWithinProcessingFolder()``, the same in TYPO3 13.4.39 and 14.3.11):
  the storage's own ``processingfolder`` (default ``_processed_``), whose
  path core compares here even when the field names another storage, and the
  folder any storage's ``processingfolder`` assigns into this storage
  (``<uid>:<path>``);
- its storage has no record, storage 0 (core's fallback for the web root)
  included.

A storage of another driver is taken as its record declares it, because it
has no path in the web root to compare. Every check reads the identifier in
its canonical form, as the Local driver resolves it: repeated slashes and
``.`` segments dropped (``2://a2a-inbox/./`` is ``2:/a2a-inbox/``), a ``..``
segment refused; the save hook stores that form. :php:`A2aFalInbox::refusal()`
decides this. :php:`A2aServerRecordSaveHook` asks it when a profile is saved and does
not store a refused folder; the tool asks it again at every call, because the
storage record can change without the profile being saved.

**2. The client's technical backend user writes.** The file is added through a
storage that :php:`FalStorageGate::folderToAddFilesTo()` builds for the run's
acting user, which is the client's technical user (A2A-SEC3), with that user's
file mounts and file permissions. The user needs a mount on the folder that is
not read-only, and the permissions ``readFolder``, ``addFile`` and
``writeFolder``; core asks them again when it adds the file. The approver's rights do not count, and an
administrator who approves lends none. The gate's storage allow-list is not
asked: it bounds the storages a model may name, and here an administrator
named the one folder in the profile.

**3. Every adoption waits for the normal backend approval.** The tool declares a
write (``NON_IDEMPOTENT_WRITE``), so :php:`ToolApprovalRule` suspends the run
for a person of this installation, as for every other writing tool. The
decision goes through the approvals inbox or the durable decision intake of
:ref:`ADR-281 <adr-281>`, with the checks of A2A-MT2 and A2A-MT4; the A2A task
stays WORKING meanwhile (A2A-MT1). Nothing adopts automatically. Because the
tool writes, only a profile that may write offers it (:php:`A2aProfileToolList`),
and such a profile serves only clients pinned to a draft workspace (A2A-MT5).
A call that cannot be written is refused before a card is raised
(:php:`RefusesBeforeApprovalInterface`): adoption off, a public target, no
such file, a file the task no longer holds, a user who may not add a file to
the folder, A2A files switched off.

The card shows what the approver decides on: the client's file name, the name
the file will be stored as, its checked type, its size, the start of its
SHA-256 digest and the inbox folder (:php:`ToolPreviewInterface`). Only a
viewer who may read the inbox folder the card names sees them, an
administrator or an editor whose file mount reaches it; any other gets the
withheld card of :ref:`ADR-136 <adr-136>`.

With the lines the tool names its plan as structured data: the canonical inbox
folder and the exact name the file is stored as (:php:`PreviewPlanInterface`).
The loop stores the plan with the preview as a field of its own. Who may see
the card is decided on the plan's folder, never on the lines, which carry the
client's file name, and not on the profile, which may name another folder by
the time the card is shown. Lines and plan are the reservation of
:ref:`ADR-184 <adr-184>`: both are produced again before the approved call
runs, and when either differs (another folder, the name taken meanwhile,
another file, a user who lost the right to write there) the call is not
carried out and the run waits for a new approval. The plan is compared on its
own because a long name cuts the name line at 500 characters, where two names
can read the same.

**4. Nothing is overwritten.** A name that is taken gives the new file another
name, chosen when the card is built with core's suffix (``report.txt``
becomes ``report_01.txt``) and shown on it. The approved call writes into
exactly the folder and under exactly the name of the approved plan: the loop
hands the tool that plan (:php:`ToolExecutionContext::$approvedPlan`), the
tool never derives either from text, and the file is added with
``DuplicationBehavior::CANCEL``. The cards of one turn are compared before any
call of it runs, so a later call of the turn, or a change of the profile, can
still come between the comparison and the write. The call then writes nothing
and answers why: when the profile names another folder than the approved one,
when the approved name is taken (two files of the same name adopted in one
turn both show ``report.txt``, and the second finds it taken), or when there is
no approved plan. Adopting the file again raises a new card. The existing file
keeps its bytes and its record.

One order is not covered. Core's Local driver adds a file by renaming a
temporary file into place, without an exclusive create. Two approved
adoptions of the same name into the same inbox, carried out by two workers at
the same instant, could both pass the check that the name is free, and the
second rename would replace the first file's bytes. That needs two runs whose
cards named the same file name in the same folder, both approved and executed
within the same moment; every sequential order is caught by the check and by
``CANCEL``. No lock is built for it. The file is named after the client's file name without any path and
without Unicode format characters, with an extension of the type the store
checked (``.txt``, ``.json``, ``.pdf``, ``.png``, ``.jpg`` or ``.jpeg``); core
sanitises the rest.

When TYPO3 fails after its driver wrote the file, the answer says that a file
of that name may remain in the inbox, and the file is left there: deleting by
name could remove a file another writer put there meanwhile, and an
unreferenced file in the inbox changes nothing.

**5. The adopted file takes effect only through an editor.** The tool creates no
reference and changes no other record. The file reaches the site only when an
editor uses it in a record of a workspace, and that record is published through
the workspace like any other change.

**Adoption happens while the task runs.** The bytes are those of the private
store, checked again against their digest when the approved call runs. The task's end releases them and the file's expiry deletes them, so an
approval that arrives after either adopts nothing and the tool says so. A run
that waits for its approval longer than ``a2a.files.retentionHours`` loses the
file.

**The write list names the file as live.** The task's artifact of A2A-MT6 lists
the adopted ``sys_file`` in workspace 0: the tool loop now reports the
workspace of the acting user only for a table that keeps versions. This holds
for every table without ``ctrl.versioningWS`` and for every consumer of
:php:`AfterAiRecordWrittenEvent`; before, no writer wrote such a table from a
draft workspace, so no announced write changes.

Why A2A-MT5 and ADR-265 still hold
==================================

A2A-MT5 and :ref:`ADR-265 <adr-265>` keep a run in a draft workspace from
changing what the site publishes past the workspace's review. A ``sys_file``
row has no workspace version, so the adopted file is a live record. It changes
no published content: no record references it, its folder is not public, so
the web server does not serve it, and the only way to the site is a record an
editor creates in a workspace and publishes. What a writing profile may change
in published content is still drafts only, and ADR-265's rule for the field
fill and the batches (no write to a table without versions from a draft
workspace) is unchanged: it governs content that would be live the moment it
is written, which an unreferenced file in a non-public inbox is not.

Consequences
============

- ● A client can hand a file to the installation's editors, and only a person
  of the installation decides that it is stored.
- ● Where it lands is the administrator's decision, per profile, and the
  client's own technical user needs the right to write there.
- ● No adopted file overwrites one an editor may already use.
- ◐ The file is a managed asset, so the cleanup of expired A2A files leaves it
  alone (:ref:`ADR-284 <adr-284>`).
- ◑ A run waiting longer than the retention for its approval cannot adopt.
- ● The approval binds to the folder, name and file the card showed; any
  change asks again.
- ✕ An adopted file is a live ``sys_file`` row in a run of a draft workspace;
  someone has to remove files nobody uses, because nothing here does.

Net Score: +5.5

Alternatives considered
=======================

**The client or the model chooses the folder.** Rejected: a remote party would
choose where its file lands, and the model's choice is the remote party's
input.

**Replace a file of the same name.** Rejected: a replaced file changes every
record that already references it, live, without a workspace.

**Write as the approver.** Rejected: the approver may reach more than the
client's technical user; the approval says yes to the call, it does not lend
rights.

**Reference the file from a draft record automatically.** Rejected: that would
be a content change of the run's own; an editor decides whether and where the
file is used.

**Keep the bytes after the task ends until adoption.** Rejected for now: the
input files are released with the task by A2A-FILE4, and keeping them longer is
a retention decision of its own.
