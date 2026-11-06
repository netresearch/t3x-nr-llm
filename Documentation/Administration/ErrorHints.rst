.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _administration-error-hints:

===========
Error hints
===========

A model can name the likely cause of a logged error and the first thing to
check (:ref:`ADR-258 <adr-258>`). It works on the error entries of the TYPO3
log files, the same ones ``get_last_exception`` reads, in two places: the
backend module *AI > Operation > Error hints* and the console command
``nrllm:exception:hint``. Both are for administrators only; editors and
visitors never see them.

What the model receives
=======================

The exception class, the message with credentials in URLs masked, the
component, and up to 20 stack frames as file, line and function name.
Argument values are cut from the frames and no source code is sent.
TYPO3's own exception handlers log no stack trace by default; for their
entries the class and the throw site are taken from the entry's data.

The trust zone of the configuration's provider decides what may leave the
installation:

*  **Local**: class, frames and message.
*  **Private hosting**: class, component and frames; the hint says it was
   made without the message.
*  **External (EU or global)**: nothing; no model is asked, and the page or
   the command says so. Move the provider to another zone in its record to
   change this. A configuration in criteria mode has no provider of its own
   and counts as external; use a configuration with a fixed model.

Reading a hint
==============

A hint has a likely cause, a next step and, where the model named one, the
frame the cause sits in. It is headed *Hint* only when that frame exists in
the entry's stack and lies in the project's own code (not under
``vendor/``, ``var/`` or ``typo3temp/``, and in a classic-mode installation
not under ``typo3/sysext/``). Every other hint is headed as a guess. The extension decides
this from the stack, not from the model's own claim.

Backend module
==============

*AI > Operation > Error hints* lists the ten newest error entries with time,
level, class, message and throw site. :guilabel:`Ask for a hint` sends one
entry to the configuration chosen at the top of the page; viewing the list
sends nothing.

Choosing the configuration
--------------------------

The selection at the top lists every active LLM configuration and marks what
a hint may send it: class, stack and message; everything but the message; or
nothing, because the provider or a fallback it can reach is in an external
trust zone. :guilabel:`Use this configuration` keeps the choice for your
backend user; other administrators keep their own. Without a choice, and when
the chosen configuration is deleted or deactivated, the default configuration
is asked, and the page says so in that case.

When a hint is refused for the trust zone, choose a configuration whose
provider is local or privately hosted, or, if the provider may receive error
details, move it to one of those zones in its record under *Providers*.

Refusals and hints are shown in your backend language; the model is asked to
write the cause and the next step in that language too.

Switching the module off
------------------------

The module sends nothing unless an administrator asks for a hint, and only
administrators see it. To remove it from the module menu, set in the user or
group TSconfig:

..  code-block:: typoscript
    :caption: User or group TSconfig

    options.hideModules = nrllm_exceptions

This also applies to administrators. It hides the menu entry; an
administrator who knows the URL can still open the module. The console
command is not affected.

Console command
===============

..  code-block:: bash
    :caption: Asking for a hint on the command line

    vendor/bin/typo3 nrllm:exception:hint
    vendor/bin/typo3 nrllm:exception:hint --index 2
    vendor/bin/typo3 nrllm:exception:hint --search Doctrine --configuration local-model

``--index`` steps back through older errors (0 is the newest), ``--search``
filters by message, class or component, and ``--configuration`` names an
LLM configuration by identifier instead of the default one. The command
exits with 1 when there is no entry, no configuration, or no hint. It is not
offered as a scheduler task. A deactivated configuration is not asked.
