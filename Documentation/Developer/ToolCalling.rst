.. include:: /Includes.rst.txt

.. _developer-tool-calling:

=====================
Tool/function calling
=====================

Tool calling (also known as function calling) allows the LLM to request
execution of functions you define. The model decides when to call a tool
based on the conversation context.

.. TODO: Add a sequence diagram showing the tool call flow:
   App -> LLM: Chat request with tool definitions
   LLM -> App: Response with tool_calls
   App -> Function: Execute requested function
   App -> LLM: Chat request with tool result
   LLM -> App: Final response incorporating tool output
   Save as /Images/diagram-tool-calling-flow.png

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

Providers that implement :php:interface:`ToolCapableInterface` support
tool calling.
