.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _developer-custom-providers:

=========================
Creating custom providers
=========================

Implement a custom provider by extending :php:`AbstractProvider`. This
example implements chat and single-prompt completion. It inherits vault
authentication and request handling, discovers models through a real HTTP
request, and explicitly rejects embeddings. Optional capabilities such as
streaming require their own interfaces and implementations.

.. code-block:: php
   :caption: Example: Custom provider implementation

   <?php

   declare(strict_types=1);

   namespace MyVendor\MyExtension\Provider;

   use Netresearch\NrLlm\Domain\Model\CompletionResponse;
   use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
   use Netresearch\NrLlm\Provider\AbstractProvider;
   use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;

   final class MyCustomProvider extends AbstractProvider
   {
       /** @var array<string> */
       protected array $supportedFeatures = [
           self::FEATURE_CHAT,
           self::FEATURE_COMPLETION,
       ];

       public function getName(): string
       {
           return 'My Custom Provider';
       }

       public function getIdentifier(): string
       {
           return 'my-custom';
       }

       protected function getDefaultBaseUrl(): string
       {
           return 'https://api.example.com/v1';
       }

       public function getDefaultModel(): string
       {
           return $this->defaultModel !== ''
               ? $this->defaultModel : 'example-chat';
       }

       /**
        * @param list<ChatMessage|array<string, mixed>> $messages
        * @param array<string, mixed> $options
        */
       public function chatCompletion(
           array $messages,
           array $options = [],
       ): CompletionResponse {
           $model = $this->getString(
               $options, 'model', $this->getDefaultModel(),
           );
           $response = $this->sendRequest(
               'chat/completions',
               [
                   'model' => $model,
                   'messages' => array_map(
                       static fn(ChatMessage|array $message): array =>
                           $message instanceof ChatMessage
                               ? $message->toArray() : $message,
                       $messages,
                   ),
               ],
               timeout: $this->resolveRequestTimeout($options),
           );
           $choices = $this->getList($response, 'choices');
           $choice = $this->asArray($choices[0] ?? []);
           $message = $this->getArray($choice, 'message');
           $usage = $this->getArray($response, 'usage');

           return $this->createCompletionResponse(
               content: $this->getString($message, 'content'),
               model: $this->getString($response, 'model', $model),
               usage: $this->createUsageStatistics(
                   $this->getInt($usage, 'prompt_tokens'),
                   $this->getInt($usage, 'completion_tokens'),
               ),
               finishReason: $this->getString(
                   $choice, 'finish_reason', 'stop',
               ),
           );
       }

       /** @return array<string, string> */
       public function getAvailableModels(): array
       {
           return $this->testConnectionViaModelsList()['models'];
       }

       /**
        * @param string|array<int, string> $input
        * @param array<string, mixed> $options
        */
       public function embeddings(
           string|array $input,
           array $options = [],
       ): never {
           throw new UnsupportedFeatureException(
               'This adapter does not support embeddings.',
               1770581100,
           );
       }
   }

Registering your provider
=========================

Register your provider in :file:`Services.yaml`:

.. code-block:: yaml
   :caption: Configuration/Services.yaml

   services:
     _defaults:
       autowire: true
       autoconfigure: true
       public: false

     MyVendor\MyExtension\Provider\MyCustomProvider:
       tags:
         - name: nr_llm.provider
           priority: 50

The inherited constructor receives the PSR request and stream factories,
logger, :php:`VaultServiceInterface`, and :php:`SecureHttpClientFactory`
through autowiring. The adapter is private; the provider registry holds
the tagged service. An external namespace requires this explicit tag
(:ref:`developer-provider-registration`). Configure its credentials using
``apiKeyIdentifier``, a vault identifier, rather than a raw key.

:php:`CustomProviderExampleTest` executes these exact PHP and YAML blocks
in bounded child processes. It checks construction, registration,
typed/array messages, the request and response, inherited completion,
model discovery, connectivity, and the unsupported embedding contract.

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
