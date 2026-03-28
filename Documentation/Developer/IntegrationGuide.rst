.. include:: /Includes.rst.txt

.. _integration-guide:

=========================================
Build your extension on nr-llm
=========================================

This guide walks you through adding AI capabilities to a TYPO3 extension using
nr-llm as a dependency. By the end, your extension will have working AI features
without any provider-specific code.

.. contents::
   :local:
   :depth: 2

.. _integration-guide-why:

Why build on nr-llm?
====================

When your extension calls an LLM API directly, it takes on responsibility for:

- HTTP client setup, authentication, and error handling per provider
- Secure API key storage (not in
  :file:`ext_conf_template.txt` or :php:`$GLOBALS`)
- Response caching to control costs
- Streaming implementation for real-time UX
- A configuration UI for administrators

nr-llm handles all of this. Your extension focuses on *what* to ask the AI, not
*how* to reach it.

.. _integration-guide-step1:

Step 1: Add the dependency
==========================

.. code-block:: bash
   :caption: Install nr-llm

   composer require netresearch/nr-llm

Add the dependency to your :file:`ext_emconf.php`:

.. code-block:: php
   :caption: ext_emconf.php

   'constraints' => [
       'depends' => [
           'typo3' => '13.4.0-14.99.99',
           'nr_llm' => '0.4.0-0.99.99',
       ],
   ],

.. _integration-guide-step2:

Step 2: Inject the service
==========================

All nr-llm services are available via TYPO3's dependency injection. Pick the
service that matches your use case:

.. code-block:: php
   :caption: Classes/Service/MyAiService.php

   <?php

   declare(strict_types=1);

   namespace MyVendor\MyExtension\Service;

   use Netresearch\NrLlm\Service\LlmServiceManagerInterface;

   final readonly class MyAiService
   {
       public function __construct(
           private LlmServiceManagerInterface $llm,
       ) {}

       public function summarize(string $text): string
       {
           $response = $this->llm->complete(
               "Summarize the following text in 2-3 sentences:\n\n" . $text,
           );

           return $response->content;
       }
   }

No :file:`Services.yaml` configuration needed — TYPO3's autowiring handles it.

.. _integration-guide-step3:

Step 3: Use feature services for specialized tasks
===================================================

For common AI tasks, use the specialized feature services instead of raw chat:

.. code-block:: php
   :caption: Translation example

   use Netresearch\NrLlm\Service\Feature\TranslationService;

   final readonly class ContentTranslator
   {
       public function __construct(
           private TranslationService $translator,
       ) {}

       public function translateToGerman(string $text): string
       {
           $result = $this->translator->translate($text, 'de');
           return $result->translation;
       }
   }

.. code-block:: php
   :caption: Image analysis example

   use Netresearch\NrLlm\Service\Feature\VisionService;

   final readonly class ImageMetadataGenerator
   {
       public function __construct(
           private VisionService $vision,
       ) {}

       public function generateAltText(string $imageUrl): string
       {
           return $this->vision->generateAltText($imageUrl);
       }
   }

.. code-block:: php
   :caption: Embedding / similarity example

   use Netresearch\NrLlm\Service\Feature\EmbeddingService;

   final readonly class ContentRecommender
   {
       public function __construct(
           private EmbeddingService $embeddings,
       ) {}

       /**
        * @param list<array{id: int, text: string, vector: list<float>}> $candidates
        * @return list<int> Top 5 most similar content IDs
        */
       public function findSimilar(string $query, array $candidates): array
       {
           $queryVector = $this->embeddings->embed($query);
           $results = $this->embeddings->findMostSimilar(
               $queryVector,
               array_column($candidates, 'vector'),
               topK: 5,
           );

           return array_map(
               fn(int $index) => $candidates[$index]['id'],
               array_keys($results),
           );
       }
   }

.. _integration-guide-step4:

Step 4: Handle errors gracefully
================================

nr-llm throws typed exceptions so you can provide meaningful feedback:

.. code-block:: php
   :caption: Error handling with typed exceptions

   use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
   use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
   use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;

   try {
       $response = $this->llm->complete($prompt);
   } catch (ProviderConfigurationException) {
       // No provider configured — guide the admin
       return 'AI features require LLM configuration. '
            . 'An administrator can set this up in Admin Tools > LLM.';
   } catch (ProviderConnectionException) {
       // Network issue — suggest retry
       return 'Could not reach the AI provider. Please try again.';
   } catch (ProviderResponseException $e) {
       // Provider returned an error (rate limit, invalid input, etc.)
       $this->logger->warning('LLM provider error', ['exception' => $e]);
       return 'The AI service returned an error. Please try again later.';
   }

.. _integration-guide-step5:

Step 5: Use database configurations (optional)
================================================

For advanced use cases, reference named configurations that admins create in the
backend module:

.. code-block:: php
   :caption: Using named database configurations

   use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
   use Netresearch\NrLlm\Service\LlmServiceManagerInterface;

   final readonly class BlogSummarizer
   {
       public function __construct(
           private LlmConfigurationRepository $configRepo,
           private LlmServiceManagerInterface $llm,
       ) {}

       public function summarize(string $article): string
       {
           // Uses the "blog-summarizer" configuration created by the admin
           // (specific model, temperature, system prompt, etc.)
           $config = $this->configRepo->findByIdentifier('blog-summarizer');

           $response = $this->llm->chat(
               [['role' => 'user', 'content' => "Summarize:\n\n" . $article]],
               $config->toChatOptions(),
           );

           return $response->content;
       }
   }

.. _integration-guide-testing:

Testing your integration
========================

Mock the nr-llm interfaces in your unit tests:

.. code-block:: php
   :caption: Tests/Unit/Service/MyAiServiceTest.php

   use Netresearch\NrLlm\Domain\Model\CompletionResponse;
   use Netresearch\NrLlm\Domain\Model\UsageStatistics;
   use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
   use PHPUnit\Framework\TestCase;

   final class MyAiServiceTest extends TestCase
   {
       public function testSummarizeReturnsCompletionContent(): void
       {
           $llm = $this->createStub(LlmServiceManagerInterface::class);
           $llm->method('complete')->willReturn(
               new CompletionResponse(
                   content: 'A short summary.',
                   model: 'gpt-5.3-instant',
                   usage: new UsageStatistics(50, 20, 70),
                   finishReason: 'stop',
                   provider: 'openai',
               ),
           );

           $service = new MyAiService($llm);
           self::assertSame('A short summary.', $service->summarize('Long text...'));
       }
   }

.. _integration-guide-checklist:

Integration checklist
=====================

.. rst-class:: bignums

1. **composer.json** — Added ``netresearch/nr-llm`` to ``require``

2. **ext_emconf.php** — Added ``nr_llm`` to ``depends`` constraints

3. **Services** — Inject :php:`LlmServiceManagerInterface`
   or feature services via DI

4. **Error handling** — Catch typed exceptions and show user-friendly messages

5. **Testing** — Mock :php:`LlmServiceManagerInterface` in unit tests

6. **Documentation** — Tell your users they need to
   configure a provider in Admin Tools > LLM
