.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-tasks:
.. _configuration-task-fields:

===========
Task fields
===========

Tasks combine a usable configuration with a required user-prompt template.
Runtime grants and the selected configuration's access rules still apply.
The table lists all task form fields; a form's optional relation does not
mean execution can proceed without a usable configuration.

.. list-table:: TCA fields (0.43.20 source)
   :header-rows: 1
   :widths: 22 12 12 8 46

   * - Field
     - TCA type
     - Default
     - Required in TCA
     - Effect
   * - ``hidden``
     - check
     - ``0``
     - no
     - TYPO3 record visibility; separate from runtime is_active.
   * - ``identifier``
     - input
     - ``unset in TCA``
     - yes
     - Unique programmatic identifier.
   * - ``name``
     - input
     - ``unset in TCA``
     - yes
     - Backend display name.
   * - ``description``
     - text
     - ``unset in TCA``
     - no
     - Description for operators.
   * - ``category``
     - select
     - ``"general"``
     - no
     - Task grouping in backend lists.
   * - ``configuration_uid``
     - select
     - ``0``
     - no
     - Configuration used by this task; execution needs a usable configuration.
   * - ``placements``
     - select
     - ``empty``
     - no
     - Surfaces where a task is offered; see input and placement rules.
   * - ``prompt_template``
     - text
     - ``unset in TCA``
     - yes
     - Required user-prompt template for the task.
   * - ``input_type``
     - select
     - ``"manual"``
     - no
     - Automatic input reader; manual by default.
   * - ``input_source``
     - text
     - ``unset in TCA``
     - no
     - Reader-specific JSON options; not an arbitrary SQL expression.
   * - ``privacy_level``
     - select
     - ``empty``
     - no
     - Optional stricter persistence ceiling; empty inherits. Cannot loosen global policy.
   * - ``output_format``
     - select
     - ``"markdown"``
     - no
     - Requested task output format; markdown by default.
   * - ``is_active``
     - check
     - ``1``
     - no
     - Whether the record is eligible for runtime use.
   * - ``is_system``
     - check
     - ``0``
     - no
     - System-task marker; not an independent execution permission.
   * - ``skills``
     - select
     - ``unset in TCA``
     - no
     - Always-loaded skill attachments; version approval and trust gates still apply.

For the supported input readers, source options and placement rules, see
:ref:`configuration-task-inputs`. Empty or other-surface placements do not
make every task available in every editor surface.

.. toctree::
   :maxdepth: 1

   TaskInputs
