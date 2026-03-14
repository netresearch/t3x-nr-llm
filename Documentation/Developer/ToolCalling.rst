.. include:: /Includes.rst.txt

.. _developer-tool-calling:

=====================
Tool/function calling
=====================

Tool calling (also known as function calling) allows the LLM to request
execution of functions you define. The model decides when to call a tool
based on the conversation context.

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

.. code-block:: php
   :caption: Example: Handling tool call responses

   $response = $this->llmManager->chatWithTools($messages, $tools);

   if ($response->hasToolCalls()) {
       foreach ($response->toolCalls as $toolCall) {
           $functionName = $toolCall['function']['name'];
           $arguments = json_decode($toolCall['function']['arguments'], true);

           // Execute your function
           $result = match ($functionName) {
               'get_weather' => $this->getWeather($arguments['location']),
               default => throw new \RuntimeException("Unknown function: {$functionName}"),
           };

           // Continue conversation with result
           $messages[] = [
               'role' => 'assistant',
               'content' => null,
               'tool_calls' => [$toolCall],
           ];
           $messages[] = [
               'role' => 'tool',
               'tool_call_id' => $toolCall['id'],
               'content' => json_encode($result),
           ];

           $response = $this->llmManager->chat($messages);
       }
   }

Providers that implement :php:interface:`ToolCapableInterface` support
tool calling.
