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

Audit and accounting
====================

After a successful stream, the dispatcher screens at most the first 50,000
raw response bytes for its guardrail audit. A large provider chunk cannot
extend this audit buffer. The audit cannot retract text already delivered;
live redaction uses its separate sliding window.

The audit limit does not truncate delivered chunks or consumption accounting.
Streaming token estimates count the complete raw output, including bytes
beyond that prefix and bytes masked by live redaction.

Providers that implement :php:interface:`StreamingCapableInterface` support
streaming. Check provider capabilities before using:

.. code-block:: php
   :caption: Example: Checking streaming support

   $provider = $this->llmManager->getProvider('openai');
   if ($provider instanceof StreamingCapableInterface) {
       // Provider supports streaming
   }
