.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=================
Registering tools
=================

.. _administration-tools-register:

Registering a tool
==================

See :ref:`api-tool-extensions` for a complete current implementation, typed
results, authorization and optional writer contracts.

A tool is a PHP class that implements
:php:`Netresearch\\NrLlm\\Service\\Tool\\ToolInterface`:

``getSpec(): ToolSpec``
   Returns the declaration the model receives — a name, a description, and a
   JSON-Schema ``parameters`` block. Build it with
   ``ToolSpec::function($name, $description, $parameters)``.

``execute(array $arguments, ToolExecutionContext $context): ToolResult``
   Runs with model-provided arguments and an explicit acting-user context.
   Returns a typed result: provider-facing ``content``, an error flag and
   optional backend-only artifacts. Validate arguments and authorize all
   user-scoped reads and writes against ``$context->actingBackendUser()``.
   Refuse access when that user is missing; do not use the ambient global
   backend user in a worker.

``isEnabledByDefault(): bool``
   Whether the tool is available before an administrator sets an override.
   Return ``false`` for tools that need deliberate enablement.

``requiresAdmin(): bool``
   Whether only administrators may use the tool. Returning ``false`` does not
   replace record-level authorization inside ``execute()``.

``getGroup(): string``
   The tool's *group* — a short, stable identifier used to enable or disable
   whole families of tools at once. Built-ins use ``content``, ``editing``,
   ``structure``, ``system``, ``accounts`` and ``configuration``; third-party
   tools declare their own group (recommended: the providing extension's key).
   See :ref:`administration-tools-groups`.

The interface carries ``#[AutoconfigureTag('nr_llm.tool')]``, so a class is
**auto-registered simply by implementing it** — no central registration file
to edit. :php:`ToolRegistry` collects every tagged tool through a DI iterator
and indexes it by spec name; two tools with the **same** ``name`` is a
developer error and fails fast at container build.

A tool that **creates records** also implements
:php:`Netresearch\\NrLlm\\Service\\Tool\\RecordCreatorInterface` and lists the
tables its records land in from ``getCreatedTables()``. The generic
``create_record_draft`` then steps back from those tables and names your tool
instead (:ref:`ADR-197 <adr-197>`).

When you write a tool, honour the security contract: treat ``$arguments`` as
attacker-influenced (the model is steerable by injected skill prose),
**validate and scope** every input (cap volumes, scope identifier lookups),
and never return secrets — the result leaves the instance.
