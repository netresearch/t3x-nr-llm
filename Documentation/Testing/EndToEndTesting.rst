.. include:: /Includes.rst.txt

.. _testing-e2e:

===============
E2E testing
===============

.. _testing-integration-example:

Integration test example
========================

.. code-block:: php
   :caption: Example: Integration test

   <?php

   namespace Netresearch\NrLlm\Tests\Integration\Provider;

   use Netresearch\NrLlm\Provider\OpenAiProvider;
   use PHPUnit\Framework\TestCase;

   class OpenAiProviderIntegrationTest extends TestCase
   {
       private ?OpenAiProvider $provider = null;

       protected function setUp(): void
       {
           $apiKey = getenv('OPENAI_API_KEY');
           if (!$apiKey) {
               $this->markTestSkipped('OPENAI_API_KEY not set');
           }

           $this->provider = new OpenAiProvider(
               httpClient: new \GuzzleHttp\Client(),
               requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
               streamFactory: new \GuzzleHttp\Psr7\HttpFactory(),
               apiKey: $apiKey
           );
       }

       public function testChatCompletionWithRealApi(): void
       {
           $response = $this->provider->chatCompletion([
               ['role' => 'user', 'content' => 'Say "test" and nothing else.'],
           ], [
               'max_tokens' => 10,
           ]);

           $this->assertStringContainsStringIgnoringCase('test', $response->content);
           $this->assertGreaterThan(0, $response->usage->totalTokens);
       }
   }
