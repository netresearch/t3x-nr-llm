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

Deprecation-log input
====================

The ``deprecation_log`` input type reads the tail of TYPO3's deprecation
log. A missing or unreadable log produces a localized placeholder. A
reader failure also preserves this best-effort behavior: the original
cause and task context are passed to diagnostic logging, while the task
input receives the generic read-error placeholder rather than private paths
or exception details. If diagnostic logging itself fails, the placeholder
is preserved. The same diagnostic containment applies to failed syslog and
table reads, including record-picker policy rejection.
