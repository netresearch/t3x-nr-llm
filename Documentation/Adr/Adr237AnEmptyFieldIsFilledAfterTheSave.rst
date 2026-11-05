.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-237:

====================================================================
ADR-237: An empty field is filled after the save
====================================================================

:Status: Accepted (its rule that a save in a draft workspace queues nothing is amended — see :ref:`ADR-265 <adr-265>`)
:Date: 2026-11-01
:Amends: :ref:`ADR-135 <adr-135>` (a process without a backend environment), :ref:`ADR-234 <adr-234>` (a background answer is used, not only kept)
:Amended: 2026-11-05 by :ref:`ADR-265 <adr-265>` (a save in a draft workspace is filled there)
:Authors: Netresearch DTT GmbH

Context
=======

Editors leave the teaser and the meta description empty. An installation wants
those fields written when a record is saved, for the fields it chooses.

A save is a bad place to call a model. It would take seconds, a provider failure
would have to be handled inside the editor's request, and a failed call must not
cost the editor the save. Two questions follow, and they are product decisions:
what happens when the model fails during a save, and who waits for it.

Decision
========

**Nobody waits, and a model failure touches nothing.** A hook after the database
write stores one tool-free background run per empty, opted-in field
(:ref:`ADR-234 <adr-234>`) and the save ends. No model is called in the request.
Whatever fails in the hook is logged and stops there, and the hook builds the
agent runtime only when a field of the table is opted in. The worker runs the
model and writes the answer.

**The field is written only while it is empty.** The worker reads the field
immediately before the write. A text somebody added in the meantime stands, and
the history says so. The DataHandler has no compare-and-set, so a save in the
few milliseconds between the read and the write is overwritten by the fill; the
window is that of two editors saving the same record, which TYPO3 accepts.

**An editor who empties a field keeps it empty.** A save that changes a field
to empty is an answer, and the field is left alone in that save. A later save
that does not touch the field fills it.

**Opting in is the confirmation.** "Nothing is saved without confirmation" is
the rule of the chat tours, and :ref:`ADR-236 <adr-236>` keeps it for a batch: a
run there produces a draft. This run writes directly, because an administrator
listed the field in ``fieldFill.fields``, and the list is the explicit opt-in.
It is empty by default. The write is an ordinary edit of an empty field: it is in
the record history, and nothing was there to lose. This is no exception to
:ref:`ADR-134 <adr-134>`, which is about tools a model chooses. The run has no
tools, the model chooses neither the record nor the field, and its only output
is the text of one field.

**The writer is the editor who saved.** The worker resolves that user and writes
through :php:`ToolDataHandler` as them, so their rights decide. Live only: a user
who moved to a draft workspace since the save gets no write, because the
DataHandler would write into a version. (Amended by :ref:`ADR-265 <adr-265>`:
the run writes in the workspace of the save, wherever the user works later.)
An editor whose
group may not use the default configuration queues nothing. ``_cli_`` queues
nothing either: an import or a scheduled task saving records is nobody's choice
to spend money, and neither is a copy, a translation or an AI write. Core makes
copies and translations as new records, so a copied page tree would otherwise
queue a run per page. It writes them through a DataHandler of its own with
``dontProcessTransformations`` set, which is how they are told from a save.

**The worker sets the backend environment for this one write.**
:ref:`ADR-135 <adr-135>` refuses a write in a process without ``$GLOBALS['LANG']``
and ``$GLOBALS['BE_USER']``, and does not set them, because they would stay
behind for every later hook in the process. The tools keep that rule: their
writes happen after an approval, in a backend request. This write has no request
to wait for, so the writer sets both around the DataHandler call and restores
them in ``finally``. What ADR-135 feared is the leak, and the leak is closed.

**The model gets named text, not the row.** For a page that is its descriptive
columns and the text of its content elements in its language; for another table
the label and its prose columns. A page row also holds its TSconfig and its
author. Under :php:`FieldFillPlanner::MIN_SOURCE_LENGTH` characters there is
nothing to summarise, and a model would invent the text, so no run is queued. The
text ends at :php:`RecordTextReader::MAX_LENGTH` characters.

**Only plain text fields of content tables.** A listed field counts when its TCA
type is ``input`` or ``text`` without rich text. A slug, a relation or a rich
text field stays out whatever the list says, and so do accounts and ``sys_``
tables.

**The answer is checked, and a bad one is not written.** Tags and surrounding
quotes are removed. An empty answer, the word ``NOTHING`` the prompt offers for a
record with nothing to say, in any case, and an answer over ``fieldFill.maxLength`` or the
TCA ``max`` leave the field empty.

**The history says what became of the answer.** A model failure is a failed run.
A finished run stays completed, and a new column ``outcome`` says *written*, *the
field was filled meanwhile* or *not written*, on the detail page of the Agent Runs
module. Withdrawing the opt-in stops queued runs: they are cancelled before the
model is asked.

Consequences
============

- Filling needs a worker: ``nrllm:agent:work`` from cron or the scheduler, or a
  Messenger consumer. Without one, runs stay queued and the fields stay empty.
- The field fills some time after the save. A form left open across the fill
  still holds the empty field, and saving it empties the field again, like any
  two edits of one record. That save reads as the editor emptying the field, so
  nothing is queued; the next save fills it again.
- A save that fails to queue is not repeated. The next save tries again.
- A save that changes nothing fills nothing, and existing records are not filled
  in bulk. Pressing save on an old page without an edit does nothing.
- A queued run keeps the text of the save that queued it. Text rewritten while it
  waits is not read, and a later save queues nothing for that field until the run
  ended.
- A record whose fill keeps failing costs one model call per save that leaves the
  field empty, as long as no run for that field is open. The budget applies as for
  any run of that user.
- Content elements are read, but saving one does not fill the page. The fill
  follows the save of the record that holds the field.
- The opt-in is per installation, not per page tree. A site that needs less can
  not say so; that is a setting nobody has asked for yet.
- A field filled for a translated record follows the language of that record's
  text. A translation made by core is not filled, as a copy is not, and neither is
  a translation that only inherited a value from a save of its default record. The
  save of the translation itself fills it. A save that changes only a slug or a
  value translations inherit, such as a stop time, fills nothing, which also keeps
  the pages EXT:redirects rewrites below a changed slug out of it.
- Whether the form of a record type shows the field is not asked. Listing a field
  some types do not show fills it on those types too.
- A reply cut off by the token limit of the configuration is written if it fits.
  The default of 1000 tokens is far above 300 characters.
