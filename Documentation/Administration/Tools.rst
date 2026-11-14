.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-tools:

=============
Running tools
=============

*Tools* are small, admin-curated PHP functions the model may call
mid-generation. Where a normal completion answers in one shot, a **tool run**
is a bounded *agent loop*: the model may ask to call a tool, nr-llm executes
it, feeds the result back, and re-asks — until the model answers or an
iteration cap is reached. The interactive
:ref:`Tool Playground <administration-tools-playground>` is one consumer.
Editor actions and prepared tasks can also use the managed runtime under
their grants and configuration policy.

The playground and tool management are **admin-only**. Tool execution uses
the acting user stored in the run context, including in workers.
:php:`ToolLoopService` excludes tools whose ``requiresAdmin()`` returns
``true`` for non-admins. Tools that read or write user-scoped records must
also enforce that user's page, table and storage permissions inside
``execute()``. Missing user context fails closed. Provider-facing result
text leaves the installation; backend-only artifacts need the same
redaction even though they are not sent to the model.

.. note::

   The runtime design and its security and cost rationale are recorded in
   :ref:`ADR-038 <adr-038>`. Skill ingest and injection — which can steer
   *which* tools a run may use and *what arguments* the model chooses — are
   :ref:`ADR-035 <adr-035>` / :ref:`ADR-036 <adr-036>` and the
   :ref:`Managing skills <administration-skills>` guide.

.. _administration-tools-argument-check:

Arguments that do not fit
=========================

Before anything of a turn runs, nr-llm checks the arguments of each call
against the schema the tool was offered with (:ref:`ADR-246 <adr-246>`). A
call that does not fit is answered with what does not fit, for example
``"uid" is required.`` or ``"table" must be one of: "pages", "tt_content".``,
and the model corrects it in the next round. Nothing of that turn runs: no
tool, no approval card, no question to the editor, and no external tool call
is charged to the run's budget. A fitting call beside a refused one is
answered with "Not run" and is sent again with the corrected call.

The check refuses a missing required argument, a value of the wrong kind (a
list where text is declared, an object where a list is declared), a value
outside an ``enum``, and an argument the schema excludes with
``additionalProperties: false``. It lets through what a tool commonly reads
after a cast, such as the text ``"5"`` for an integer, and it does not judge a
schema part that uses ``$ref``, ``oneOf``, ``anyOf``, ``allOf`` or ``not``,
which MCP servers may send. The run timeline shows a refused call as a failed
tool step whose answer starts with ``Refused: the arguments do not match``.

Choose the task below. Editors use :ref:`administration-tools-editor-actions`;
administrators enable tools and groups before they are offered. The
:ref:`api-tool-extensions` is the developer contract for new PHP tools.


.. toctree::
   :maxdepth: 1

   ToolManagement
   EditorActions
   ToolPlayground
   ToolWorkspaces
   EditorialProfile
   AssignmentScope
   ReadTools
   WriteTools
   SiteTools
   ToolNetwork
   ToolSkillPolicy
   ToolRegistration
