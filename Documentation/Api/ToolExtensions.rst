.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-tool-extensions:

==================
Tool extension API
==================

Implement :php:`Netresearch\NrLlm\Service\Tool\ToolInterface` to register a
PHP tool. Its autoconfiguration tag is ``nr_llm.tool``; names must be unique.
A custom group must be enabled together with its tool before the model can
receive the declaration. See :ref:`administration-tools-manage`.

.. _api-tool-extensions-example:

Complete minimal tool
=====================

This deliberately small tool returns a fixed greeting and reads no records,
files or external service. A tool that reads user-scoped data must additionally
check page, table and storage permissions inside ``execute()``.

.. code-block:: php
   :caption: Classes/Tool/GreetingTool.php

   <?php

   declare(strict_types=1);

   namespace MyVendor\MyExtension\Tool;

   use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
   use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
   use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
   use Netresearch\NrLlm\Service\Tool\ToolInterface;

   final class GreetingTool implements ToolInterface
   {
       public function getSpec(): ToolSpec
       {
           return ToolSpec::function('example_greeting', 'Return a greeting.', [
               'type' => 'object',
               'properties' => [
                   'language' => ['type' => 'string', 'enum' => ['en', 'de']],
               ],
               'required' => ['language'],
               'additionalProperties' => false,
           ]);
       }

       public function execute(
           array $arguments,
           ToolExecutionContext $context,
       ): ToolResult {
           if ($context->actingBackendUser() === null) {
               return ToolResult::error('Not permitted.');
           }
           return match ($arguments['language'] ?? null) {
               'en' => ToolResult::text('Hello.'),
               'de' => ToolResult::text('Hallo.'),
               default => ToolResult::error('Unsupported language.'),
           };
       }

       public function isEnabledByDefault(): bool
       {
           return false;
       }

       public function requiresAdmin(): bool
       {
           return false;
       }

       public function getGroup(): string
       {
           return 'my_extension';
       }
   }

.. _api-tool-extensions-results:

Results, effects and authorization
==================================

``ToolResult::text($content, ...$artifacts)`` sends only ``content`` to the
model. Artifacts are for the run's backend/audit views and still need the same
redaction and output handling. ``ToolResult::error($message)`` returns a
controlled error without artifacts or a write target. Do not expose exception
messages, credentials or unrestricted records through either channel.

Use ``$context->actingBackendUser()`` rather than an ambient global user.
``requiresAdmin()`` supplies the coarse runtime gate; it does not replace
per-record authorization for tools available to editors.

Writers also implement :php:`ToolEffectInterface` and the relevant approval
preview contract. A record writer reports its target and write kind through
``ToolResult::withWriteTarget()``. The records a call wrote beyond that one
are recorded by the loop itself, as a ``tool_changes`` step
(:ref:`adr-292`); a tool does nothing for it, as long as it writes through a
DataHandler. A tool that takes a target language implements
:php:`PendingLanguageInterface`, so the approval card states it as a value. A
creator implements
:php:`RecordCreatorInterface::getCreatedTables()` so the generic creator can
step back from its tables. See :ref:`tool-workspaces` and the contracts in
``Classes/Service/Tool/`` before adding writes; a plain read-tool example is
not a complete writer implementation.

.. _api-tool-extensions-availability:

Availability and unattended execution
=====================================

:php:`UnavailableToolsResolverInterface::unavailable($configuration, $user)`
returns refused :php:`ToolPolicyDecision` objects in registry order, including
globally disabled tools. It explains unavailable capabilities; it grants none.
Remote tool names are shown only to administrators. Built-in trust checks in
observe mode can still offer a tool; remote checks stay enforced.

:php:`UnattendedToolFilterInterface::unattended($toolNames)` narrows a list to
registered tools that need neither approval nor typed input. First obtain the
policy-allowed names with ``ToolCallPolicyInterface::filterOfferable()``.
The unattended filter does not establish authorization. Preserve ``[]`` as an
empty offer; ``null`` means resolve the enabled set.

For managed execution and resumable decisions use :ref:`api-agent-runtime`.
For manual protocol handling see :ref:`developer-tool-calling`.
