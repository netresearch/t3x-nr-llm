.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-273:

=============================================================
ADR-273: Observed outcomes require affirmative evidence
=============================================================

:Status: Accepted
:Date: 2026-11-08
:Amends: :ref:`ADR-185 <adr-185>`; :ref:`ADR-176 <adr-176>`
:Authors: Netresearch DTT GmbH

Decision
========

The observation days are a minimum settlement delay after the latest write.
Only terminal runs qualify. The result is observed at derivation time, not
at a fixed seven-day closing boundary. The outcome row timestamps identify
that observation; scheduler delay can intentionally change the evidence.

An old retained history row cannot prove continuous logging or exclude
selective deletion. With no affirmative later activity, derive UNKNOWN.
ACCEPTED_UNCHANGED remains readable for historical data but has no automatic
writer in this protocol. EDITED means subsequent recorded activity; neither
human authorship nor modification of the generated fields is established.
Only confirmed absence or deletion establishes DISCARDED. Missing extension
tables count as absent; transient database exceptions fail for retry.

Replace outcomes at grain (run UUID, source) under a TYPO3 exclusive key
lock and a database transaction. Last successful replacement wins, rollback
preserves the former value, and the other source remains independent.
All writers use the repository. Multi-node installations need a shared lock
strategy; default host-local locks do not coordinate separate hosts.

Evidence
========

ObservedOutcomeDeriverTest and WrittenRecordRepositoryTest check uncertainty
and terminal-run eligibility. WrittenRecordReadFailureTest checks transient
storage failure. CallOutcomeRepositoryTest injects a real SQLite insertion
failure and overlaps independent processes at the locking boundary.
