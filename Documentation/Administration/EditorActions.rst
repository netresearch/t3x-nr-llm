.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

===================================
Editor action catalogue and batches
===================================

.. _administration-tools-editor-actions:

What editors see
================

The declaration is what the **Editor Action Center**
(:ref:`ADR-158 <adr-158>`) renders. It lives in the editor module
:guilabel:`AI > AI Tasks` and appears in two places: as an :guilabel:`AI
actions` catalogue reachable from that module, and as an :guilabel:`AI actions`
entry in the context menu of a record — a page, a content element, or a file or
folder in the file list — which opens the catalogue narrowed to the actions that
address that record.

An editor is offered an action only when all of the following hold, and every
one of them is an administrator's decision:

* the writing tool is enabled in this module (all twenty-three ship
  **disabled**);
* its group — ``editing`` — is enabled, and where the default LLM
  configuration restricts tool groups, ``editing`` is among them;
* the tool's data class is within the configured provider's trust-zone ceiling;
* the backend user holds ``tasks_use`` and has the module ticked in their
  group;
* the backend user may use the default LLM configuration itself — where that
  configuration restricts :guilabel:`Allowed backend groups`, the user is in
  one of them (see :ref:`administration-permissions`).

That last point is checked again when the action is started, so an editor
outside those groups cannot start a run by naming the action directly either.

Starting an action creates an ordinary agent run restricted to that one tool.
Because the tool declares a write, the run suspends before it touches anything
and the change appears on an approval card with its preview — the editor is
redirected straight to that inbox. Nothing is written until someone approves.

The record an action is offered on is the record its arguments name, which is
not always the record it writes: :guilabel:`Create content element draft` is
offered on a **page**, because the page is what it must be told, and the
element it creates is the result. Where an action needs something the selected
record cannot supply — :guilabel:`Move content element` needs a target page —
the editor names it in the note, and the approval card shows the destination
that was resolved from it.

In the file list (:ref:`ADR-267 <adr-267>`) the entry appears on a file when
the editor may change that file's metadata, in a storage the FAL tools may
use; the catalogue then opens for that file as for any record. On a folder the
editor may read, the entry opens the actions on files, each with
:guilabel:`Run this on the files in this folder`, which leads to the batch page
with the folder already chosen as its scope
(:ref:`administration-agent-runs-batch`). A file or folder in a
storage outside that set, or outside the editor's file mounts, gets no entry.

Several records at once
-----------------------

Once a record is selected — that is, when the catalogue was opened from a
record's context menu — each action there also offers :guilabel:`Run this on
several records` (:ref:`ADR-162 <adr-162>`). The catalogue opened from the
module menu has no record and therefore no bulk entry point either; an action
needs a subject, and this module picks none. On that page the editor adds
records of the same table with :guilabel:`Choose records`, which opens TYPO3's
record browser, or for files with :guilabel:`Choose files`, which opens the file
browser, starting with the record that was selected. Each chosen record is listed with its title and number and can be
removed again. Before anything starts, the page shows which of them the action
can run on, which are skipped and why, and what the batch is expected to cost
in requests, tokens and money. After :guilabel:`Recalculate` the list shows the
records by number only, because the titles came from the browser.

At most 100 entries of that list are read at all. A longer list is cut there
and the page says so, because everything past the cut would otherwise become a
table row and a record number in a message no one can read.

Starting it creates **one ordinary run per record**. There is no bulk mode: each
record gets its own approval card with its own preview, and an approver decides
them one at a time. At most 20 records are started in one press, because the runs
execute inside the one backend request.

The AI budget is checked once per run, so a batch can run out of budget partway
through. When that happens the batch stops and names the records the stop kept
from starting — the record it stopped on was run, and is reported separately.
Nothing is left half-written: the runs that did start are proposals awaiting
approval, not changes.

Runs that ended for some other reason are reported by kind — failed, stopped by
a guardrail, cancelled, or simply finished without proposing a change — so a
batch in which everything failed does not read like one in which nothing needed
changing.

The estimate on that page is deliberately rough and says so: it does not count
the system prompt and skills the runtime adds, and its upper price assumes every
request returns the configured token ceiling. It shows no price range at all
unless the model record carries both an input and an output price and the
configuration sets an output ceiling — an absent range means "unknown", which
``0.00`` would not. Treat it as an order of magnitude, not an invoice.
