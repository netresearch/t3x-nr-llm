.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _developer-tool-calling:

=====================
Tool/function calling
=====================

Tool calling (also known as function calling) allows the LLM to request
execution of functions you define. The model decides when to call a tool
based on the conversation context.

..  figure:: /Images/diagram-tool-calling-flow.svg
    :alt: Sequence: the application sends a chat request with tool definitions,
        the model answers with tool calls, the tool gate admits or denies each
        call, the application executes the admitted ones and returns their
        results, and the model produces the final answer.
    :class: with-border

    One round of the bounded loop. The gate runs before the model is offered
    anything, and a call whose tool declares a write effect suspends the run
    for approval before it executes.

Defining tools
==============

.. code-block:: php
   :caption: Example: Tool/function calling

   $tools = [
       [
           'type' => 'function',
           'function' => [
               'name' => 'get_weather',
               'description' => 'Get current weather for a location',
               'parameters' => [
                   'type' => 'object',
                   'properties' => [
                       'location' => [
                           'type' => 'string',
                           'description' => 'City name',
                       ],
                       'unit' => [
                           'type' => 'string',
                           'enum' => ['celsius', 'fahrenheit'],
                       ],
                   ],
                   'required' => ['location'],
               ],
           ],
       ],
   ];

Executing tool calls
====================

:php:`CompletionResponse::$toolCalls` is a list of
:php:`Netresearch\NrLlm\Domain\ValueObject\ToolCall` value objects —
:php:`$toolCall->arguments` is already a JSON-decoded associative array,
so no manual :php:`json_decode()` is needed. The two follow-up turns are
built with the :php:`ChatMessage` factories:
:php:`ChatMessage::assistantToolCalls()` echoes the assistant turn that
carries the tool calls, and :php:`ChatMessage::toolResult()` answers one
call by its id.

.. code-block:: php
   :caption: Example: Handling tool call responses

   use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;

   $response = $this->llmManager->chatWithTools($messages, $tools);

   if ($response->hasToolCalls()) {
       // Echo the assistant turn (with all its tool calls) back first
       $messages[] = ChatMessage::assistantToolCalls($response->toolCalls, $response->content);

       foreach ($response->toolCalls as $toolCall) {
           // Execute your function — $toolCall->arguments is a decoded array
           $result = match ($toolCall->name) {
               'get_weather' => $this->getWeather($toolCall->arguments['location']),
               default => throw new \RuntimeException("Unknown function: {$toolCall->name}"),
           };

           // Answer the call by its id
           $messages[] = ChatMessage::toolResult($toolCall->id, json_encode($result, JSON_THROW_ON_ERROR));
       }

       // Ask the model to answer with the tool results in context
       $response = $this->llmManager->chat($messages);
   }

Providers that implement :ref:`ToolCapableInterface <api-provider-tools>` support
tool calling.

Running the tool loop without an approval step
==============================================

:php:`ToolLoopServiceInterface::runLoop()` suspends a run when the model
calls a tool that needs a human approval, and throws
:php:`ToolApprovalRequiredException`. A caller that has no approval step,
such as an editor dialog, offers the model only the tools that never
suspend: first the tools the policy allows, then those of them that run
without an approval and without asking for typed input
(:ref:`ADR-210 <adr-210>`).

.. code-block:: php
   :caption: Example: offering only tools that run without approval

   use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
   use Netresearch\NrLlm\Service\Tool\UnattendedToolFilterInterface;

   $offerable = $this->toolCallPolicy->filterOfferable(null, $configuration, $backendUser);
   $tools = $this->unattendedToolFilter->unattended($offerable);

   $result = $this->toolLoopService->runLoop($messages, $configuration, $context, $tools);

An empty list offers no tools at all; pass it as is rather than ``null``,
which would mean "every enabled tool".

The filter applies the same rule as an agent run. A remote (MCP) tool is
judged on the operator's declaration on its server record, not on its
effect, so an undeclared remote tool counts as running without approval
here too.
