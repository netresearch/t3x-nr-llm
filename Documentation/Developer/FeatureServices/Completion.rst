.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

==================
Completion recipes
==================

.. _feature-services-completion:

CompletionService
=================

**Purpose**: Text generation and completion.

.. _feature-services-completion-use-cases:

Use cases
---------

- Content generation.
- Rule generation (contexts extension).
- Content summarization.
- SEO meta generation.

.. _feature-services-completion-features:

Key features
------------

- JSON response formatting (adapter-dependent native support,
  :ref:`ADR-128 <adr-128>`).
- Schema-validated structured output — a strict JSON-schema subset,
  provider-enforced, with one repair round-trip (:ref:`ADR-126
  <adr-126>`).
- Markdown generation.
- Factual mode (low creativity).
- Creative mode (high creativity).
- System prompt support.

.. _feature-services-completion-example:

Example
-------

.. code-block:: php
   :caption: Example: Using CompletionService

   use Netresearch\NrLlm\Service\Feature\CompletionService;
   use Netresearch\NrLlm\Service\Option\ChatOptions;

   $completion = $completionService->complete(
       prompt: 'Explain TYPO3 in simple terms',
       options: new ChatOptions(
           temperature: 0.3,
           maxTokens: 200,
           responseFormat: 'markdown',
       ),
   );

   echo $completion->content;

.. _feature-services-completion-methods:

Methods
-------

.. code-block:: php
   :caption: CompletionService methods

   // Standard completion
   $response = $completionService->complete($prompt);

   // JSON output
   $data = $completionService->completeJson('List 5 colors as a JSON array');

   // Markdown output
   $markdown = $completionService->completeMarkdown('Write docs for this API');

   // Factual (low creativity, high consistency)
   $response = $completionService->completeFactual('What is the capital of France?');

   // Creative (high creativity)
   $response = $completionService->completeCreative('Write a haiku about coding');

   // Structured: schema-validated JSON (strict subset, ADR-126); ->data is
   // the payload, ->response the answering call, ->usage every attempt
   $data = $completionService->completeStructured('Rate this text', [
       'type'       => 'object',
       'required'   => ['score', 'reason'],
       'properties' => [
           'score'  => ['type' => 'number'],
           'reason' => ['type' => 'string'],
       ],
   ])->data;
