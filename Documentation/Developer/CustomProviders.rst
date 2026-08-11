.. include:: /Includes.rst.txt

.. _developer-custom-providers:

=========================
Creating custom providers
=========================

Implement a custom provider by extending :php:`AbstractProvider`:

.. code-block:: php
   :caption: Example: Custom provider implementation

   <?php

   namespace MyVendor\MyExtension\Provider;

   use Netresearch\NrLlm\Provider\AbstractProvider;
   use Netresearch\NrLlm\Provider\Contract\ProviderInterface;

   class MyCustomProvider extends AbstractProvider implements ProviderInterface
   {
       protected string $baseUrl = 'https://api.example.com/v1';

       public function getName(): string
       {
           return 'My Custom Provider';
       }

       public function getIdentifier(): string
       {
           return 'custom';
       }

       public function isConfigured(): bool
       {
           return !empty($this->apiKey);
       }

       public function chatCompletion(array $messages, array $options = []): CompletionResponse
       {
           $payload = $this->buildChatPayload($messages, $options);
           $response = $this->sendRequest('chat', $payload);

           return new CompletionResponse(
               content: $response['choices'][0]['message']['content'],
               model: $response['model'],
               usage: $this->parseUsage($response['usage']),
               finishReason: $response['choices'][0]['finish_reason'],
               provider: $this->getIdentifier(),
           );
       }

       // Implement other required methods...
   }

Registering your provider
=========================

Register your provider in :file:`Services.yaml`:

.. code-block:: yaml
   :caption: Configuration/Services.yaml

   MyVendor\MyExtension\Provider\MyCustomProvider:
     arguments:
       $httpClient: '@Psr\Http\Client\ClientInterface'
       $requestFactory: '@Psr\Http\Message\RequestFactoryInterface'
       $streamFactory: '@Psr\Http\Message\StreamFactoryInterface'
       $logger: '@Psr\Log\LoggerInterface'
     tags:
       - name: nr_llm.provider
         priority: 50

.. _developer-custom-providers-contract:

What an adapter has to answer to
================================

Every bundled adapter passes one shared contract case,
:php:`Tests\Unit\Provider\Contract\AbstractAdapterContractTestCase`
(:ref:`adr-160`). It is the readable statement of what an adapter is
expected to do, so read it before writing one — and extend it in your own
test suite if you want the same guarantees:

Identifier
   :php:`getIdentifier()` returns the stable registration key, and that
   same key travels on every :php:`CompletionResponse`.

Capability declaration
   The capability interfaces an adapter implements and the features it
   lists in :php:`$supportedFeatures` must agree. The service layer reads
   the first, :php:`LlmServiceManager::supportsFeature()` reads the second;
   a disagreement gives two callers opposite answers about the same
   adapter.

Error normalisation
   401 becomes :php:`ProviderAuthenticationException`, 429 becomes
   :php:`ProviderRateLimitException`, any other 4xx becomes
   :php:`ProviderResponseException` *carrying the provider's own message*,
   a 5xx and an undecodable 2xx body become
   :php:`ProviderConnectionException`. Nothing leaves an adapter as a raw
   transport exception.

No credential, no request
   An adapter that needs an API key throws
   :php:`ProviderConfigurationException` before it builds a request, rather
   than sending without one and letting the provider answer 401. A keyless
   provider — a local Ollama — declares that with
   :php:`requiresApiKey(): false` and the contract skips by name.

Timeout behaviour
   A client-side timeout surfaces as a connection failure and is attempted
   exactly once. Retrying a timeout multiplies the caller's wait by the
   attempt count.

Usage reporting
   Token counts come from the provider's own counters; the total is
   derived. A response with no usage block degrades to zero rather than
   failing, because cost accounting writes a row per call.

Tool calls and structured output
   Where the adapter declares the capability: a provider tool call arrives
   as a typed :php:`ToolCall` with a non-empty id, the declared tools reach
   the request, a strict schema is enforced through the provider's native
   shape, a schema the provider cannot enforce degrades instead of earning
   a 400, and a malformed structured answer is passed back untouched so
   :php:`CompletionService` can run its one repair attempt.

A capability the adapter does not have is skipped **by name** in the run
output. That is deliberate: it keeps "cannot" distinguishable from "not
tested".

.. _developer-custom-providers-deviations:

The declared deviations
-----------------------

Two rules above are not universal, and the contract says which adapter breaks
them rather than softening the rule for everyone:

*  ``OpenRouterProvider`` maps a 5xx **other than 503** to
   :php:`ProviderResponseException`, not :php:`ProviderConnectionException`,
   because it carries its own request path for the attribution headers and the
   402 = out-of-credits mapping. 503 has its own arm there and stays
   :php:`ProviderConnectionException`, matching the shared path. Retry and
   fallback are unaffected either way — :php:`FailureClassifier` reads the
   carried HTTP status, so a 5xx classifies as ``SERVER_ERROR`` and hops. What
   differs is the class a caller catches and the message text.
*  The same adapter does not retry transport failures at all; that path has no
   retry loop, so ``maxRetries`` is inert for it.

Both are declared as overrides in
:php:`Tests\Unit\Provider\Contract\OpenRouterAdapterContractTest`, with the
reasoning in each override's docblock. See :ref:`adr-160`.
