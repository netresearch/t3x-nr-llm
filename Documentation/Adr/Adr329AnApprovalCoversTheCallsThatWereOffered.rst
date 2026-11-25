.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-329:

=====================================================================
ADR-329: An approval covers the calls that were offered at the pause
=====================================================================

:Status: Accepted
:Date: 2026-11-25
:Amends: :ref:`ADR-084 <adr-084>` (approving a turn runs its pending calls)
:Authors: Netresearch DTT GmbH

.. _adr-329-context:

Context
=======

A run that pauses for approval holds its whole turn. The approval scan passes
over a call whose tool the run is not offered, because the loop refuses such a
call a moment later. The card shows no preview of it.

The resume resolves the offered tools anew from the live configuration. A tool
that is switched on while the run waits is offered then, so the pending call
ran on the approval of the turn. The approver had seen its arguments and no
preview, and the scan had never asked for it.

The resume after a question closes the same gap already: it refuses every
approval-bound call, because an answer approves nothing.

.. _adr-329-decision:

Decision
========

An approval pause stores the positions of the pending calls whose tool was not
offered (:php:`SuspendedRunState::$unofferedCallIndexes`). At the resume an
approval-bound call among them is refused. Where its tool is offered by then,
the refusal is recorded as ``write_unapproved`` with the reason
``approvalResumeNotOfferedAtPause``, and the model can call the tool again in a
new turn, which asks for its own approval. Where the tool is still not offered,
the call is refused as no longer permitted, as before.

A run that pauses again because a card is stale (:ref:`ADR-184 <adr-184>`)
keeps the positions.

The absence of a stored preview cannot stand for the positions: an offered tool
without a preview has none either, and its approved call must run.

.. _adr-329-consequences:

Consequences
============

- A call that needs no approval is not affected. It runs at the resume when its
  tool is offered by then, as it does after a question.
- A run that paused before this change has no stored positions and resumes as
  before.
