.. include:: /Includes.rst.txt

.. _developer-streaming:

=================
Streaming support
=================

Streaming allows you to receive LLM responses incrementally as they are
generated, rather than waiting for the complete response. This improves
perceived performance for long responses.

.. TODO: Add a diagram showing the streaming data flow:
   Client -> LlmManager -> Provider Adapter -> LLM API (SSE)
   and the chunked response path back through the Generator.
   Save as /Images/diagram-streaming-flow.png

Usage
=====

.. code-block:: php
   :caption: Example: Streaming chat responses

   $stream = $this->llmManager->streamChat($messages);

   foreach ($stream as $chunk) {
       echo $chunk;
       ob_flush();
       flush();
   }

The ``streamChat`` method returns a ``Generator`` that yields string chunks
as the provider generates them. Each chunk contains a portion of the response
text.

Providers that implement :php:interface:`StreamingCapableInterface` support
streaming. Check provider capabilities before using:

.. code-block:: php
   :caption: Example: Checking streaming support

   $provider = $this->llmManager->getProvider('openai');
   if ($provider instanceof StreamingCapableInterface) {
       // Provider supports streaming
   }
