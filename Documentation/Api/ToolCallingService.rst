.. include:: /Includes.rst.txt

.. _api-tool-calling-service:

==================
ToolCallingService
==================

.. php:namespace:: Netresearch\NrLlm\Service\Feature

.. php:class:: ToolCallingService

   Tool-calling chat completion. Depend on
   ``ToolCallingServiceInterface`` rather than this class (or the
   service manager) — the interface exposes exactly the tool-calling
   capability, so consumer test doubles stay two methods small
   (:ref:`ADR-051 <adr-051>`).

   When no ``beUserUid`` is set on the options, the active backend user
   is resolved and populated automatically so per-user budget
   enforcement applies.

   .. php:method:: chatWithTools(array $messages, array $tools, ?ToolOptions $options = null): CompletionResponse

      Chat completion with tool calling. The provider is resolved from
      the options (or the extension's default); the configuration is a
      model-less transient one.

      :param array $messages: list of ``ChatMessage`` (or legacy ``{role, content}`` arrays)
      :param array $tools: list of ``ToolSpec`` (or legacy OpenAI-wire arrays)
      :param ?ToolOptions $options: Tool choice, provider/model pin, budget fields
      :returns: CompletionResponse (``toolCalls`` carries requested calls)

   .. php:method:: chatWithToolsForConfiguration(array $messages, array $tools, LlmConfiguration $configuration, ?ToolOptions $options = null): CompletionResponse

      Chat completion with tool calling against a specific LLM
      configuration — the adapter is resolved from the configuration's
      model (vault key, model, pricing), so budget and usage middleware
      record real cost. Prefer this entry point when a database-backed
      configuration exists.

      :param array $messages: list of ``ChatMessage`` (or legacy ``{role, content}`` arrays)
      :param array $tools: list of ``ToolSpec`` (or legacy OpenAI-wire arrays)
      :param LlmConfiguration $configuration: The configuration to run on
      :param ?ToolOptions $options: Tool choice and budget fields
      :returns: CompletionResponse (``toolCalls`` carries requested calls)

Usage
=====

.. code-block:: php

   use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
   use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
   use Netresearch\NrLlm\Service\Feature\ToolCallingServiceInterface;
   use Netresearch\NrLlm\Service\Option\ToolOptions;

   final readonly class WeatherAgent
   {
       public function __construct(
           private ToolCallingServiceInterface $toolCalling,
       ) {}

       public function ask(string $question): string
       {
           $response = $this->toolCalling->chatWithTools(
               [ChatMessage::user($question)],
               [ToolSpec::function(
                   'get_weather',
                   'Get the current weather for a location.',
                   [
                       'type' => 'object',
                       'properties' => ['location' => ['type' => 'string']],
                       'required' => ['location'],
                   ],
               )],
               ToolOptions::auto(),
           );

           // Dispatch $response->toolCalls and continue the conversation —
           // see :ref:`tool-calling` for the full loop.
           return $response->content;
       }
   }
