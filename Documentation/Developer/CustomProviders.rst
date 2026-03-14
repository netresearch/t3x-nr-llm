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
