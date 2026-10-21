.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-tasks:

===========
Task fields
===========

Tasks combine a configuration with a user prompt
template for one-shot AI operations.

.. figure:: /Images/backend-tasks.png
   :alt: Task list page
   :class: with-border with-shadow
   :zoom: lightbox

   Task list with assigned configurations.

Each task references an LLM configuration and adds
a user prompt template. The same configuration can
power multiple tasks with different prompts.

Input sources
=============

The ``input_type`` selects how automatic task input is resolved.
``input_source`` stores JSON options for sources that support them:

``manual``
   The run form supplies input. ``input_source`` has no effect.

``syslog``
   Reads ``sys_log`` from newest to oldest. Use ``limit`` (default ``50``)
   and ``error_only`` (default ``true``). For example:
   ``{"limit":100,"error_only":true}``.

``table``
   Reads rows from the configured ``table`` with ``limit`` (default ``50``).
   For example: ``{"table":"sys_log","limit":100}``. The record-picker
   exclusion policy applies. The editor list and form do not offer table
   tasks; the record-picker endpoints require administrator rights.
   A ``where`` option does not apply a SQL filter.

``deprecation_log``
   Reads the last 100 lines from ``var/log/typo3_deprecations.log``.
   ``input_source`` has no effect.

``file``
   A reserved persisted value retained for compatibility. It has no reader
   or established source schema and currently produces empty automatic
   input. Paths, URLs and file identifiers in ``input_source`` are ignored.
   Supply text through ``manual`` input when a task needs file contents.

Use a positive ``limit`` for syslog and table sources. Numeric strings are
converted to integers. Unrecognised JSON options are ignored; invalid JSON
falls back to the source defaults.
