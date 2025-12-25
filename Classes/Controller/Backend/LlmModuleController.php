<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

#[AsController]
final class LlmModuleController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly LlmServiceManager $llmServiceManager,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $providers = $this->llmServiceManager->getProviderList();
        $availableProviders = $this->llmServiceManager->getAvailableProviders();
        $defaultProvider = $this->llmServiceManager->getDefaultProvider();

        $providerDetails = [];
        foreach ($providers as $identifier => $name) {
            $isAvailable = isset($availableProviders[$identifier]);
            $provider = $isAvailable ? $availableProviders[$identifier] : null;

            $providerDetails[$identifier] = [
                'name' => $name,
                'identifier' => $identifier,
                'available' => $isAvailable,
                'isDefault' => $identifier === $defaultProvider,
                'models' => $provider?->getAvailableModels() ?? [],
                'defaultModel' => $provider?->getDefaultModel() ?? '',
                'features' => $this->getProviderFeatures($provider),
            ];
        }

        $moduleTemplate->assignMultiple([
            'providers' => $providerDetails,
            'defaultProvider' => $defaultProvider,
        ]);

        return $moduleTemplate->renderResponse('Backend/Index');
    }

    public function testAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $providers = $this->llmServiceManager->getAvailableProviders();

        $moduleTemplate->assignMultiple([
            'providers' => array_map(
                fn($p) => ['identifier' => $p->getIdentifier(), 'name' => $p->getName()],
                $providers,
            ),
        ]);

        return $moduleTemplate->renderResponse('Backend/Test');
    }

    public function executeTestAction(): ResponseInterface
    {
        $body = $this->request->getParsedBody();
        $provider = $body['provider'] ?? null;
        $prompt = $body['prompt'] ?? 'Hello, please respond with a brief greeting.';

        if (!$provider) {
            return new JsonResponse(['error' => 'No provider specified'], 400);
        }

        try {
            $chatOptions = new ChatOptions(provider: $provider);
            $response = $this->llmServiceManager->complete($prompt, $chatOptions);

            return new JsonResponse([
                'success' => true,
                'content' => $response->content,
                'model' => $response->model,
                'usage' => [
                    'promptTokens' => $response->usage->promptTokens,
                    'completionTokens' => $response->usage->completionTokens,
                    'totalTokens' => $response->usage->totalTokens,
                ],
            ]);
        } catch (Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @return array<string, bool>
     */
    private function getProviderFeatures(?object $provider): array
    {
        if ($provider === null) {
            return [];
        }

        return [
            'chat' => $provider->supportsFeature('chat'),
            'completion' => $provider->supportsFeature('completion'),
            'embeddings' => $provider->supportsFeature('embeddings'),
            'vision' => $provider->supportsFeature('vision'),
            'streaming' => $provider->supportsFeature('streaming'),
            'tools' => $provider->supportsFeature('tools'),
        ];
    }
}
