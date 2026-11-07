.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-authoring:

============================
Writing and maintaining ADRs
============================

.. _adr-lifecycle:

Record lifecycle
================

An ADR is a record of a decision at a point in time. It is expected to become
historically wrong; what it must never do is *look* current when it is not. The
``:Status:`` field is how a reader tells the difference.

``Proposed``
   Not yet a decision. The record states the evidence and recommends, and every
   recommendation in it is open. Nothing may cite it as settled, and it writes
   no ``:Amends:`` field — a proposal that amended something would have decided
   it. It becomes ``Accepted`` in the change that accepts it, which also writes
   the amendments it recommended.

``Accepted``
   Current. The decision and the facts it reasons from still hold.

``Accepted`` with an ``:Amended:`` field
   Still current as a whole, but a later ADR overturned, widened or expired
   part of it. The status line names which part in parentheses; the
   ``:Amended:`` field names the date and the amending record.

``Superseded`` with a ``:Superseded:`` field
   No longer current. The field names the date and the replacing record.

``Deprecated``
   What it decided is being removed, with no successor decision.

The link is written from both ends. The newer record declares ``:Amends:`` or
``:Supersedes:``; the older one declares ``:Amended:`` or ``:Superseded:`` with
the date. ``Tests/Unit/AdrLifecycleTest.php`` fails when only one end of an
**ADR-to-ADR** link is written.

Not every successor is an ADR. :ref:`ADR-012 <adr-012>` was superseded by the
nr-vault integration, which no record decided, so its field names that in prose
and has no counterpart. A field whose body references no ADR is outside the
pairing check by construction — which is also why prose there must stay
specific enough to follow.

Two rules follow from the pairing:

**An amended record keeps its reasoning.** :ref:`ADR-122 <adr-122>` declined to
build a side-effecting tool contract because no tool wrote. That premise expired
with :ref:`ADR-135 <adr-135>`, but the reasoning — do not design a contract
ahead of its first consumer — is why the writer shipped without a framework. The
record stays; the status says the premise is gone.

**Amending is the amender's job.** An ADR that overturns part of an earlier one
edits that earlier record's ``:Status:`` and ``:Amended:`` in the same change.
An accepted ADR with an expired premise and a clean ``Accepted`` status is a
defect, not history.

.. _adr-citations:

How a record cites code
=======================

**Records do not cite line numbers.** A line number is invalidated by any edit
above it in the cited file, and nothing announces that. ADR-171 cited
``ResumeCoordinator.php:204`` for an authorisation check a later commit pushed
to :205; it cited ``AgentRunController.php:267`` for "the list viewport", which
by then was that file's closing brace for an unrelated method. Both still read
as verified citations, and the second is the dangerous shape: precision is what
makes a reviewer trust a citation.

Three forms replace it, in order of preference:

- **A symbol** — :php:`ResumeCoordinator::approve()`, or a class docblock named
  as such. Survives every edit that does not rename it.
- **A** ``:ref:`` **into the record being quoted**, with the quote in the prose.
  ``Tests/Unit/AdrLifecycleTest.php`` already fails on a target that does not
  resolve. Quoting an ADR by line rotted three times here, all three by the
  same ten lines: ``9b902993`` corrected the module inventory ADR-119 opens
  with, and every citation into that record from two others moved with it.
- **A** ``path#anchor`` — the file plus a unique string it must still contain,
  for configuration arrays, Fluid templates and XLIFF, which have no symbols.
  ``Tests/Unit/AdrCodeCitationTest.php`` asserts the string is still there.

A countable claim carries the command that counts it, next to the number:
:ref:`ADR-169 <adr-169-q6>` prints its grep beside "40". A bare count is a
positional claim with no way to fail — ADR-171 said "nine enforcement points"
and the grep now returns ten.

.. _adr-symbol-legend:

Symbol legend
=============

Each consequence in the ADRs is marked with severity
symbols to indicate impact weight:

+--------+------------------+-------------+
| Symbol | Meaning          | Weight      |
+========+==================+=============+
| ●●     | Strong Positive  | +2 to +3    |
+--------+------------------+-------------+
| ●      | Medium Positive  | +1 to +2    |
+--------+------------------+-------------+
| ◐      | Light Positive   | +0.5 to +1  |
+--------+------------------+-------------+
| ✕      | Medium Negative  | -1 to -2    |
+--------+------------------+-------------+
| ✕✕     | Strong Negative  | -2 to -3    |
+--------+------------------+-------------+
| ◑      | Light Negative   | -0.5 to -1  |
+--------+------------------+-------------+

Net Score indicates the overall impact of the decision (sum of weights).
