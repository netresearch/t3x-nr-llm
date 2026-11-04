.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-258:

=====================================================
ADR-258: A model hints at the cause of a logged error
=====================================================

:Status: Accepted
:Date: 2026-11-04
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-044 <adr-044>` gave the assistant ``get_last_exception``: an
administrator who asks in the chat gets the newest logged error with its
stack and source, and the model explains it. Outside the chat nothing did:
an administrator on the command line, or one who opens the backend to look
at what failed, had the log and no hint. The gap analysis of 2026-09-23
listed "hints for exceptions by AI, for administrators and on the command
line" as missing.

Three constraints shape it. The hint must not show anyone more than the
person already sees: the log files are readable by whoever runs the CLI and,
in the backend, only by administrators. An exception message sits next to
secrets (connection strings, tokens; :ref:`ADR-094 <adr-094>` classes it
``SECRET_ADJACENT``), and a provider may be allowed less than that. And a
model asked for a cause always names one, so a reader must be able to tell a
hint anchored in the stack from a guess.

Decision
========

One service, :php:`ExceptionHintService`, two thin surfaces.

What is sent
   The exception class, the message after the credential sanitizer, the
   component, and up to 20 frames as file, line and function name. Call
   arguments are cut from each frame (PHP writes argument values into a
   trace unless ``zend.exception_ignore_args`` is on). No source line is
   sent; ``get_last_exception`` remains the way to read code with a model.

What the provider may receive
   The configuration's trust zone decides, through the same ladder the tool
   loop applies (:php:`TrustZoneResolver::ceilingFor()`). Class and frames
   are system diagnostics, the message is secret-adjacent. Below
   ``SYSTEM_DIAGNOSTICS`` no model is asked and the refusal says why; below
   ``SECRET_ADJACENT`` the message is withheld and the hint says it was made
   without it. With the default zones this means a hint is asked only of a
   local or privately hosted provider; an operator who wants another one
   moves that provider's zone, which is the documented switch.

When a hint is a guess
   The model answers with a cause, a next step and the number of the frame
   the cause sits in. The extension, not the model, labels the hint: it is
   anchored only when the cited frame exists in the entry's stack and lies in
   the project's code (inside the project root, not under ``vendor/``, as
   ``get_last_exception`` counts it). Every other hint is shown as a guess.

Surfaces
   ``nrllm:exception:hint`` (``--index``, ``--search``, ``--configuration``;
   default configuration otherwise) prints the entry and the hint. It is not
   schedulable: a scheduler task would send every logged error out on a
   timer. The backend module *AI > Operation > Error hints* is
   administrator-only and lists the ten newest entries; a hint is asked for
   per entry by POST, so a page view or a prefetch never sends an error. The
   hint action checks the administrator again. The module uses the default
   configuration.

Not done
   No hook in TYPO3's exception handlers. A model call while an exception is
   rendered would add a provider's latency and an outbound request to every
   error page, and a failing provider would fail inside the handler.

Consequences
============

- An administrator gets a cause and a next step without opening the chat,
  and can tell an anchored hint from a guess by its heading.
- On an installation whose providers are all external with default zones,
  both surfaces refuse with the zone named. That is the data-class rule
  applied, not a defect.
- The failure signature of a regression in the guess rule is a hint shown
  as anchored whose frame is under ``vendor/`` or missing.
