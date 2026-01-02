.. include:: /Includes.rst.txt

.. _adr-009:

===================================
ADR-009: Streaming Implementation
===================================

.. _adr-009-status:

Status
======
**Accepted** (2024-03)

.. _adr-009-context:

Context
=======
Streaming responses provide:

- Better UX for long responses.
- Lower time-to-first-token.
- Real-time feedback.

.. _adr-009-decision:

Decision
========
Use **PHP Generators** for streaming:

.. code-block:: php
   :caption: Example: Streaming chat responses

   public function streamChat(array $messages, array $options = []): Generator
   {
       $response = $this->sendStreamingRequest($messages, $options);

       foreach ($this->parseSSE($response) as $chunk) {
           yield $chunk;
       }
   }

   // Usage
   foreach ($llmManager->streamChat($messages) as $chunk) {
       echo $chunk;
       flush();
   }

Implementation details:

- Server-Sent Events (SSE) parsing.
- Chunked transfer encoding.
- Memory-efficient iteration.
- Provider-specific adaptations.

.. _adr-009-consequences:

Consequences
============
**Positive:**

- ●● Memory efficient.
- ● Natural iteration syntax.
- ●● Real-time output.
- ◐ Works with output buffering.

**Negative:**

- ✕ No response object until complete.
- ◑ Error handling complexity.
- ◑ Connection management.
- ✕ No caching possible.

**Net Score:** +3.5 (Positive impact - streaming UX benefits outweigh implementation complexity)
