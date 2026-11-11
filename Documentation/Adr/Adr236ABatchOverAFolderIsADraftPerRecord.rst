.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-236:

=====================================================
ADR-236: A batch over a folder is a draft per record
=====================================================

:Status: Accepted (its live-only reading of the scope is amended — see :ref:`ADR-265 <adr-265>`; an approved card holds its record only when its write took — see :ref:`ADR-287 <adr-287>`)
:Date: 2026-11-01
:Amends: :ref:`ADR-162 <adr-162>` (a second bound, for a batch that does not run in the request)
:Amended: 2026-11-05 by :ref:`ADR-265 <adr-265>` (the scope is read in the batch's workspace, whose runs are kept as ``batch@<workspace>:…``; a schedule runs in live); 2026-11-11 by :ref:`ADR-287 <adr-287>` (an approved card whose write failed decides nothing)
:Authors: Netresearch DTT GmbH

.. _adr-236-context:

Context
=======

:ref:`ADR-162 <adr-162>` runs a bulk editor action as N ordinary runs inside one
backend request and stops at 20 records, because a request can time out. It named
the way past the cap: move the loop onto the queue, a separate decision.
:ref:`ADR-234 <adr-234>` made a queue that does not run in the caller, and
:ref:`ADR-235 <adr-235>` made the alternative text come from the image. What is
left is the scope: a folder of two hundred images, a section of a site, and a
scheduler that goes through them without anybody opening a page.

The guided tours of this project hold to the rule that nothing is saved without
confirmation. A scheduled batch has nobody present, so it has to say where it
stands on that rule.

.. _adr-236-decision:

Decision
========

**A batch is still N ordinary runs.** Everything ADR-162 derives from that holds:
N turns, N digests, N verdicts, one catalogue call per record. Only the loop
moves, from :php:`run()` to :php:`defer()`.

**It is a draft, per record.** A batch writes nothing. Each run ends at its tool
call, which suspends for approval (:ref:`ADR-134 <adr-134>`), so the generated text
stands on an approval card and the field keeps its value until somebody approves
that card. That is the rule of the tours, kept: nothing is saved without
confirmation. There is no direct-write switch. A declared write is approved
whatever the configuration says (:ref:`ADR-202 <adr-202>`), and a batch verdict is
what :ref:`ADR-133 <adr-133>` refuses, so a switch would have to reverse both for
a surface that writes to many records at once.

**Whose rights.** A writing tool acts for a person and refuses a service account.
The page queues under the editor's own frozen actor, as every background run does
(:ref:`ADR-234 <adr-234>`). ``nrllm:editor-action:batch`` needs ``--user``, an
active backend user whose rights the runs have and who finds the cards in their
inbox. It is never ``_cli_``, whose admin flag says nothing about who asked. A
user disabled after the batch was queued yields no acting user, and the run fails.

The catalogue asks whether the actor may use the default configuration through
:php:`ConfigurationResolver::actorMayUse()`, not through the ambient user: on the
command line that would be ``_cli_``, and everyone would pass.

**The image is read in the worker.** A queued request names its file in
``imageFileUid``. Scaling two hundred images inside a request is the timeout this
record leaves behind, and two hundred data URIs in the run table are a queue full
of copies. The worker reads the image as the acting user, through the same gate
(:ref:`ADR-235 <adr-235>`), so a file that moved or a right that went away is
decided when the run starts. A run whose image cannot be sent fails, because no
model should describe an image it does not have.

**A repeated batch is harmless.** The origin of a run is
``batch:<tool>:<table>:<uid>``, per record. A record is skipped when it has a run
that is open or ended in a decision: approved, rejected, or declined by the model.
A person who said no must not get the same card on every schedule, and an image
approved as decorative holds an empty text on purpose. A run that failed, was
cancelled, ran out of budget or was cut short says nothing about the record, so
the record is tried again. A decision lasts as long as its run is kept.

A record is also skipped when it holds a value in any field a vision action fills:
the prompt asks for all of them, so a file with one written would get a proposal to
replace it. A file with a description and no title therefore stays as it is. A
field switched off is not counted. A daily schedule picks up what arrived since,
and a batch never overwrites a text. The ``--only-empty`` option adds a column to
that check. A decision covers the record for that action: a second batch of the
same action with another ``--only-empty`` column skips what the first one decided.

**A queued run on a page reads the page.** A run has only the writing tool it was
started for, so the prompt carries the text of the page and its content elements,
read when the batch is queued. A vision run has its image instead.

**A second bound.** :php:`EditorActionBatchPlanner::MAX_BACKGROUND_RECORDS` is 100
open runs of one action. It is not about timeouts. It bounds the cards an action
hands an approver, the second constraint ADR-162 named, and it is the number of
cards the inbox shows, oldest first. Open runs count, so a daily schedule does not
stack cards faster than somebody decides them. What the scope holds beyond it is
reported and picked up by the next batch. A record the catalogue refuses takes no
place, or a folder that starts with documents would never reach its images.

.. _adr-236-consequences:

Consequences
============

- A folder of 100 images is 100 cards, each decided on its own. That is the price
  of ADR-133, and it is the reason the bound exists. With a full inbox a later
  card of another action stays out of view until older ones are decided. An
  installation that wants
  this to be less work needs a different approval unit, which is its own decision.
- A scheduled batch needs a user who reads an inbox, and something that works the
  queue: ``nrllm:agent:work`` or a consumer.
- A run in the queue carries a request, not an image. A run waiting for approval
  does carry it, in ``suspended_state``, until it settles (:ref:`ADR-235 <adr-235>`).
- The cost estimate of the batch page does not count the image, as for the
  synchronous page.
- The budget stops a batch per user in the sweep, and the runs stay queued
  (:ref:`ADR-234 <adr-234>`).
- A page tree is read with the acting user's page permissions. A folder is listed
  with the rights of the process and filtered per file as the acting user.

.. _adr-236-alternatives:

Alternatives considered
=======================

**Direct write behind an explicit opt-in.** It would make a scheduled run finish a
folder unattended, and it is what a large archive would want. It needs a write
path outside the tool loop, since a tool write always suspends, and it would save
generated text under an editor's name without that editor seeing it. Left to a
decision of its own, with an approval unit to match.

**A proposal table holding the generated texts.** A list an editor works through
in one view is a better surface for 100 texts than 100 cards. It is a second place
that holds a pending write, with its own lifecycle, next to the suspended run that
already does.

.. _adr-236-revisit:

Revisit when
============

- Measured use shows approvers giving up on the cards.
- :ref:`ADR-133 <adr-133>` is reopened for a verdict over several runs.
