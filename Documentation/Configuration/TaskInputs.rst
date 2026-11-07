.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-task-inputs:

==========================
Task inputs and placements
==========================

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

Deprecation-log input
=====================

The ``deprecation_log`` input type reads the tail of TYPO3's deprecation
log. A missing or unreadable log produces a localized placeholder. A
reader failure also preserves this best-effort behavior: the original
cause and task context are passed to diagnostic logging, while the task
input receives the generic read-error placeholder rather than private paths
or exception details. If diagnostic logging itself fails, the placeholder
is preserved. The same diagnostic containment applies to failed syslog and
table reads, including record-picker policy rejection.

.. _configuration-tasks-placements:

Placements
==========

``placements`` (*Offered first in*) marks the places where a task is listed
first, in a group of its own. nr-llm declares one place:

``ai_tasks``
   The editors' module *AI tasks*. Marked tasks appear under *Prepared for
   this module*; a task marked for no place follows under its category, and
   a task marked for other places only is not listed there.

Other extensions add their own places to the same field. nr-landingpage, for
example, adds *Landing page: image prompt* for the template field that
switches on image generation. A place decides whether it shows the unmarked
tasks after the marked ones or hides them; a place that hides them still
shows the task a record already holds, and shows every task while none is
marked for it. :ref:`ADR-245 <adr-245>` records the decision.

An extension that offers tasks in a TCA select names its place and lets
nr-llm order the items:

.. code-block:: php
   :caption: Configuration/TCA/Overrides/tx_nrllm_task.php

   $GLOBALS['TCA']['tx_nrllm_task']['columns']['placements']['config']['items'][] = [
       'label' => 'LLL:EXT:my_ext/Resources/Private/Language/locallang_db.xlf:placement',
       'value' => 'my_ext_place',
   ];

.. code-block:: php
   :caption: The select that offers tasks

   'config' => [
       'type' => 'select',
       'renderType' => 'selectSingle',
       'foreign_table' => 'tx_nrllm_task',
       'itemsProcFunc' => \Netresearch\NrLlm\Form\Tca\TaskPlacementItems::class . '->groupByPlacement',
       'itemsProcConfig' => ['placement' => 'my_ext_place', 'unmarked' => 'hide'],
       'itemGroups' => [
           'nrllm_placement' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.placements.group.marked',
           'nrllm_unmarked' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_task.placements.group.unmarked',
       ],
   ],

``unmarked`` is ``after`` (default) or ``hide``. Code that reads task records
itself asks :php:`Task::isPlacedIn()`.
