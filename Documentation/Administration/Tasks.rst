.. include:: /Includes.rst.txt

.. _administration-tasks:

==============
Managing tasks
==============

Tasks are one-shot prompt templates that combine a
configuration with a specific user prompt. They
provide reusable AI operations that editors or
extensions can execute with a single call.

.. figure:: /Images/backend-tasks.png
   :alt: Task list showing task name, linked
       configuration, description, and actions
   :class: with-border with-shadow
   :zoom: lightbox

   The task list with each task's assigned
   configuration and action buttons.

.. _administration-tasks-add:

Adding a task manually
======================

1. Navigate to :guilabel:`Admin Tools > LLM >
   Tasks`.
2. Click :guilabel:`Add Task`.
3. Fill in the required fields:

   :guilabel:`Name`
      Display name (e.g., ``Summarize Article``).

   :guilabel:`Configuration`
      Select the LLM configuration to use.

   :guilabel:`User Prompt`
      The prompt template. Use ``{placeholders}``
      for dynamic values.

4. Add a description so other admins understand
   what the task does.
5. Click :guilabel:`Save`.

.. _administration-tasks-execute:

Executing a task
================

Click :guilabel:`Run` on any task to open the
execution form. It shows the configuration, model,
parameters, input field, and prompt template.

.. figure:: /Images/backend-task-execute.png
   :alt: Task execution form showing configuration
       details, input field, and prompt template
   :class: with-border with-shadow
   :zoom: lightbox

   The task execution form for "Analyze System Log
   Errors" with the Ollama provider and Qwen 3 model.

Example tasks:

- **Summarize content** — condense long articles.
- **Generate meta descriptions** — SEO optimization.
- **Translate text** — one-click translation.
- **Extract keywords** — pull key terms from content.

.. _administration-tasks-editor-module:

The editor module
=================

Editors do not need backend administrator rights to run tasks: the
dedicated :guilabel:`Web > AI Tasks` module (:ref:`ADR-131 <adr-131>`)
lists every active task and opens a slim run form — without any of the
management affordances of this admin module. Access takes both switches
described under :ref:`administration-permissions`: the module permission
on the backend group AND the *Execute AI tasks* grant. Every run is
pre-flighted against the user's own :ref:`usage budget
<administration-user-budgets>`. Tasks with the ``table`` input type are
not offered to editors — their database record picker stays admin-only.

.. figure:: /Images/backend-aitasks-list.png
   :alt: The editor-facing AI Tasks module listing runnable tasks grouped by category
   :class: with-border with-shadow
   :zoom: lightbox

   The AI Tasks module as an editor sees it: runnable tasks grouped by
   category, plus the Approvals shortcut for users holding the approval
   grant.

.. figure:: /Images/backend-aitasks-execute.png
   :alt: The editor run form with input area and output panel, without management links
   :class: with-border with-shadow
   :zoom: lightbox

   The editor run form: input, execute, output — the configuration is
   shown as a read-only summary line.

.. tip::

   Use the :ref:`Task wizard
   <administration-wizards-task>` to generate
   a complete task (including a new configuration)
   from a plain-language description.
