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
*  **Private hosting**: class and frames; the hint says it was made without
   the message.
*  **External (EU or global)**: nothing; no model is asked, and the page or
   the command says so. Move the provider to another zone in its record to
   change this. A configuration in criteria mode has no provider of its own
   and counts as external; use a configuration with a fixed model.

Reading a hint
==============

A hint has a likely cause, a next step and, where the model named one, the
frame the cause sits in. It is headed *Hint* only when that frame exists in
the entry's stack and lies in the project's own code (not under
``vendor/``, and in a classic-mode installation not under
``typo3/sysext/``). Every other hint is headed as a guess. The extension decides
this from the stack, not from the model's own claim.

Backend module
==============

*AI > Operation > Error hints* lists the ten newest error entries with time,
level, class, message and throw site. :guilabel:`Ask for a hint` sends one
entry to the default LLM configuration; viewing the list sends nothing.

Console command
===============

..  code-block:: bash

    vendor/bin/typo3 nrllm:exception:hint
    vendor/bin/typo3 nrllm:exception:hint --index 2
    vendor/bin/typo3 nrllm:exception:hint --search Doctrine --configuration local-model

``--index`` steps back through older errors (0 is the newest), ``--search``
filters by message, class or component, and ``--configuration`` names an
LLM configuration by identifier instead of the default one. The command
exits with 1 when there is no entry, no configuration, or no hint. It is not
offered as a scheduler task.
