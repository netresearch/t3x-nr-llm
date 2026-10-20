.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-225:

=============================================================
ADR-225: Agent outcomes survive optional diagnostic failures
=============================================================

:Status: Accepted
:Date: 2026-10-10
:Authors: Netresearch DTT GmbH
:Amends: :ref:`ADR-101 <adr-101>` and :ref:`ADR-104 <adr-104>` (optional diagnostics)

Context
=======

The shared lifecycle promises settled results for execution outcomes, recovery
promises fail-soft decisions, and persistence promises defined fallback values
when storage is unavailable. A throwing PSR-3 logger currently escapes their
diagnostic calls. Canonical actual-consumer counterexamples demonstrate eleven
pure assertion failures with eleven passing recording-logger controls and no
framework errors or warnings. Production remains the frozen source.

A logger error can hide the original run error, cancellation or ownership stop.
It can also interrupt the recovery catch before mandatory dead-letter handling.
Optional reporting must not decide the fate of a run or grant storage success.

Decision
========

Contain optional diagnostic emission in the executor, queued failure recovery
and agent-run persister through one internal private callback guard. Invoke the
existing severity method once with its existing message, context and ordering.
Contain only its Throwable; emit no recursive fallback diagnostic.

Keep original result fields and error identity, guarded row transitions, retry
classification, dispatch and fail-closed suspension/audit/write-fence behavior.
A failed mandatory operation retains its current failure or refusal; a failed
logger cannot turn it into success. Missing loggers remain valid. No public
signature, outcome, schema, privacy, retention or logging-content change occurs.

Consequences
============

Actual consumer tests pair throwing loggers with recording controls and assert
settlement, original errors, ownership refusal and the existing exact diagnostic
contents. Storage doubles prove local fallback contracts; real Functional
ownership and fence suites remain necessary. Selected containment-removal and
missing-diagnostic faults must fail assertions after exact source restoration.

This bounded decision covers three existing consumers. Other diagnostic sites
and the whole-extension test adequacy remain open. The implementation depends
on this separately published signed decision and requires independent review,
complete gates and fresh current-head CI before merge.
