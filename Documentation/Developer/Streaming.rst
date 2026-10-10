.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _developer-streaming:

=================
Streaming support
=================

Streaming allows you to receive LLM responses incrementally as they are
generated, rather than waiting for the complete response. This improves
perceived performance for long responses.

..  figure:: /Images/diagram-streaming-flow.svg
    :alt: Streaming: the prompt is screened, the model and adapter are resolved,
        the budget is checked, the dispatcher opens the provider stream with
        fallback, and each chunk passes a sliding redaction window before the
        Generator yields it to the caller.
    :class: with-border

    Request path down the left, chunk path back up the right. Redaction happens
    per chunk through a sliding window, so a secret split across two chunks is
    still caught without buffering the whole response.

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

Providers that implement
``Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface`` support
streaming. Check provider capabilities before using:

.. code-block:: php
   :caption: Example: Checking streaming support

   use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;

   $provider = $this->llmManager->getProvider('openai');
   if ($provider instanceof StreamingCapableInterface) {
       // Provider supports streaming
   }

Transport failures
==================

The bundled adapters' streaming methods send each request once. They propagate
an exception raised by the PSR-18 transport without wrapping it as an nr_llm
exception. In particular, :php:`Psr\\Http\\Client\\NetworkExceptionInterface`
does not implement nr_llm's marker merely because it passed through an adapter.
This differs from the buffered adapters' transport-error normalization.

The manager's streaming dispatcher classifies a PSR-18 network exception as a
retryable connection failure. It can try the configured fallback before the
first chunk is yielded. After output has begun, it propagates the failure;
switching providers would combine two different answers. The dispatcher also
propagates the last retryable failure when no fallback can serve the call.
Callers that handle streaming transport failures should catch the PSR-18
network interface in addition to their nr_llm exception cases.
