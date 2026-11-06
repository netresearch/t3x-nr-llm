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
   The exception class, the message after the sanitizer that masks
   credentials in URLs, the component, and up to 20 frames as file, line
   and function name. TYPO3's exception handlers log no trace by default;
   for their entries :php:`LogExceptionReader` now takes the class and the
   throw site from the entry's own ``exception_class``, ``file`` and
   ``line`` keys; that class beats one the message quotes. An entry with no class, no frame and a message the
   provider may not receive is not sent at all. Call
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
   moves that provider's zone, which is the documented switch. A
   criteria-mode configuration has no provider of its own and counts as
   external, as it does for the tool loop; name a fixed-model configuration
   instead.

When a hint is a guess
   The model answers with a cause, a next step and the number of the frame
   the cause sits in. The extension, not the model, labels the hint: it is
   anchored only when the cited frame exists in the entry's stack and lies in
   the project's code (inside the project root, not under ``vendor/``,
   ``var/`` or ``typo3temp/``, which hold generated files, and, in a
   classic-mode installation, not under ``typo3/sysext/``). Every other hint
   is shown as a guess.

Surfaces
   ``nrllm:exception:hint`` (``--index``, ``--search``, ``--configuration``;
   default configuration otherwise) prints the entry and the hint. It is not
   schedulable: a scheduler task would send every logged error out on a
   timer. The backend module *AI > Operation > Error hints* is
   administrator-only and lists the ten newest entries; a hint is asked for
   per entry by POST, so a page view or a prefetch never sends an error. The
   form posts a key of the entry, not its position, so an error logged in
   between is never sent in its place. The hint action checks the
   administrator again. The module used the default configuration; the
   amendment of 2026-11-06 lets each administrator choose. The
   call bypasses the answer cache and is attributed as
   ``exception_hint`` in the usage data.

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

Amendment (2026-11-06): each administrator chooses the configuration
=====================================================================

Where the default configuration reaches an external provider, every hint in
the module was refused, in English inside a German backend, and the module
offered no way to ask another configuration; only the command line had
``--configuration``.

Where the choice is kept
   In the backend user's module data for ``nrllm_exceptions``, under
   ``configuration``, written by a POST to the module's ``choose`` action
   and only for an active configuration; an empty value means the default
   configuration. An extension setting, as ``decision.configuration`` is for
   the decision service, was the alternative. It was not taken: the module
   would have to write ``settings.php``, which no nr-llm module does and
   which a deployment that versions that file overwrites, and a setting is
   one answer for every administrator although the module is a per-person
   tool. The key is deliberately not declared as ``moduleData`` default in
   the module registration: core's ``BackendModuleValidator`` stores any
   declared key it finds in a query string, so a link could change the
   choice. A stored identifier is checked on every read; a deleted or
   deactivated one falls back to the default configuration and the page says
   so. The command line keeps ``--configuration`` and the default
   configuration; it reads no backend user's choice.

What the list shows
   Every active configuration with what a hint may send it, from
   :php:`ExceptionHintService::admission()`, the same answer
   :php:`ExceptionHintService::hint()` applies, fallback hops included. A
   configuration that may receive nothing stays selectable and is marked; a
   hint against it is refused with what to do (choose a configuration whose
   provider is local or privately hosted, or move the provider's zone) and
   links to *Configurations* and *Providers*.

Language
   A refusal carries an :php:`ExceptionHintRefusal` reason and its
   arguments. The module words it from ``locallang_mod_exceptions.xlf`` in the
   administrator's language; the command prints the English text. The model
   is asked to write the cause and next step in the administrator's backend
   language, English on the command line.

Switching the module off
   No extension setting: none of nr-llm's administrator modules has one, and
   the module sends nothing until an administrator clicks. User or group
   TSconfig ``options.hideModules = nrllm_exceptions`` removes it from the
   module menu, for administrators too; the route itself stays reachable for
   administrators.
