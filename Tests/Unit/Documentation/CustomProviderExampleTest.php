<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Documentation;

use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises the actual PHP and YAML copied from the integration manual.
 */
#[CoversNothing]
final class CustomProviderExampleTest extends TestCase
{
    #[Test]
    public function documentedAdapterExecutesChatAndModelDiscovery(): void
    {
        $actual = $this->runExample('calls');
        self::assertFalse($actual['unconfigured']);
        self::assertTrue($actual['configured']);
        self::assertSame('my-custom', $actual['identifier']);
        self::assertSame([true, true, false], $actual['features']);
        self::assertSame('example answer', $actual['content']);
        self::assertSame('wire-model', $actual['model']);
        self::assertSame('my-custom', $actual['provider']);
        self::assertSame([7, 3, 10], $actual['usage']);
        self::assertSame(['wire-model' => 'wire-model'], $actual['models']);
        self::assertTrue($actual['connectionSuccess']);
        self::assertSame(
            UnsupportedFeatureException::class,
            $actual['embeddingFailure'],
        );
        self::assertSame(
            'https://api.example.com/v1/chat/completions',
            $actual['url'],
        );
        self::assertSame(
            [
                'model' => 'example-chat',
                'messages' => [
                    ['role' => 'user', 'content' => 'hello'],
                    ['role' => 'assistant', 'content' => 'earlier'],
                ],
            ],
            $actual['payload'],
        );
        self::assertSame(
            [
                ['role' => 'system', 'content' => 'instructions'],
                ['role' => 'user', 'content' => 'single prompt'],
            ],
            $actual['completionMessages'],
        );
    }

    #[Test]
    public function documentedRegistrationWiresTheCurrentConstructor(): void
    {
        $actual = $this->runExample('container');
        self::assertTrue($actual['private']);
        self::assertTrue(
            $actual['directLookupFailed'],
            'The compiler-registered adapter must remain private.',
        );
        self::assertSame('my-custom', $actual['identifier']);
        self::assertSame(
            [['name' => 'nr_llm.provider', 'priority' => 50]],
            $actual['tags'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runExample(string $mode): array
    {
        $project = dirname(__DIR__, 3);
        $manual = file_get_contents(
            $project . '/Documentation/Developer/CustomProviders.rst',
        );
        self::assertIsString($manual);
        $examples = [];
        foreach (['php', 'yaml'] as $language) {
            $matched = preg_match(
                '/^\.\. code-block:: ' . $language . '\R(?:   :[^\r\n]*\R)*\R((?:   [^\r\n]*\R|\R)+)/m',
                $manual,
                $matches,
            );
            self::assertSame(
                1,
                $matched,
                'The manual must contain its executable ' . $language . ' example.',
            );
            $examples[$language] = preg_replace('/^   /m', '', $matches[1]) ?? '';
        }

        $runner = <<<'PROVIDER_EXAMPLE_RUNNER'
            require $argv[1] . '/.Build/vendor/autoload.php';
            $examples = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            eval(preg_replace('/^<\?php/', '', $examples['php']));
            $class = 'MyVendor\\MyExtension\\Provider\\MyCustomProvider';
            $factory = new GuzzleHttp\Psr7\HttpFactory();
            $probe = new class('placeholder') extends PHPUnit\Framework\TestCase {
                public function placeholder(): void {}
                public function double(string $class): object { return self::createStub($class); }
                public function vault(): Netresearch\NrVault\Service\VaultServiceInterface {
                    $vault = self::createStub(Netresearch\NrVault\Service\VaultServiceInterface::class);
                    $vault->method('exists')->willReturn(true);
                    return $vault;
                }
            };
            $vault = $probe->vault();
            $logger = new Psr\Log\NullLogger();
            $secure = new Netresearch\NrVault\Http\SecureHttpClientFactory();
            if ($argv[2] === 'container') {
                $directory = sys_get_temp_dir() . '/nrllm-doc-provider-' . bin2hex(random_bytes(8));
                mkdir($directory, 0700);
                $file = $directory . '/services.yaml';
                try {
                    file_put_contents($file, $examples['yaml']);
                    $container = new Symfony\Component\DependencyInjection\ContainerBuilder();
                    $services = [Psr\Http\Message\RequestFactoryInterface::class => $factory, Psr\Http\Message\StreamFactoryInterface::class => $factory, Psr\Log\LoggerInterface::class => $logger, Netresearch\NrVault\Service\VaultServiceInterface::class => $vault, Netresearch\NrVault\Http\SecureHttpClientFactory::class => $secure];
                    foreach ($services as $id => $service) {
                        $container->register($id)->setSynthetic(true)->setPublic(true);
                    }
                    $loader = new Symfony\Component\DependencyInjection\Loader\YamlFileLoader($container, new Symfony\Component\Config\FileLocator($directory));
                    $loader->load('services.yaml');
                    $definition = $container->getDefinition($class);
                    $private = !$definition->isPublic();
                    $tags = $definition->getTags();
                    $extension = $probe->double(TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class);
                $extension->method('get')->willReturn([]);
                $managerServices = [
                    Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface::class => $probe->double(Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface::class),
                    Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline::class => new Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline([]),
                    Netresearch\NrLlm\Service\KeyedProviderRegistry::class => new Netresearch\NrLlm\Service\KeyedProviderRegistry($extension, $logger),
                    Netresearch\NrLlm\Service\ConfigurationResolver::class => new Netresearch\NrLlm\Service\ConfigurationResolver(),
                    Netresearch\NrLlm\Service\MessageShaper::class => new Netresearch\NrLlm\Service\MessageShaper(),
                    Netresearch\NrLlm\Service\EmbedCacheKeyBuilder::class => new Netresearch\NrLlm\Service\EmbedCacheKeyBuilder($probe->double(Netresearch\NrLlm\Service\CacheManagerInterface::class)),
                ];
                $services += $managerServices;
                foreach ($managerServices as $id => $service) {
                    $container->register($id)->setSynthetic(true)->setPublic(true);
                }
                $managerClass = Netresearch\NrLlm\Service\LlmServiceManager::class;
                $references = array_map(static fn(string $id): Symfony\Component\DependencyInjection\Reference => new Symfony\Component\DependencyInjection\Reference($id), array_keys($managerServices));
                $container->register($managerClass, $managerClass)->setArguments($references)->setPublic(true);
                $container->addCompilerPass(new Netresearch\NrLlm\DependencyInjection\ProviderCompilerPass());
                $container->compile();
                    foreach ($services as $id => $service) {
                        $container->set($id, $service);
                    }
                    $provider = $container->get($managerClass)->getProvider('my-custom');
                $directLookupFailed = false;
                try { $container->get($class); } catch (Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException) { $directLookupFailed = true; }
                    $reportedTags = [];
                    foreach ($tags['nr_llm.provider'] ?? [] as $tag) {
                        $reportedTags[] = ['name' => 'nr_llm.provider'] + $tag;
                    }
                    echo json_encode(['private' => $private, 'identifier' => $provider->getIdentifier(), 'tags' => $reportedTags, 'directLookupFailed' => $directLookupFailed], JSON_THROW_ON_ERROR);
                } finally {
                    if (is_file($file)) { unlink($file); }
                    rmdir($directory);
                }
                exit(0);
            }
            $provider = new $class($factory, $factory, $logger, $vault, $secure);
            $unconfigured = $provider->isAvailable();
            $provider->configure(['apiKeyIdentifier' => 'fixture-vault-id']);
            $client = new class implements Psr\Http\Client\ClientInterface {
                public array $requests = [];
                public function sendRequest(Psr\Http\Message\RequestInterface $request): Psr\Http\Message\ResponseInterface {
                    $this->requests[] = $request;
                    return new GuzzleHttp\Psr7\Response(200, [], $request->getMethod() === 'GET' ? '{"data":[{"id":"wire-model"}]}' : '{"model":"wire-model","choices":[{"message":{"content":"example answer"},"finish_reason":"stop"}],"usage":{"prompt_tokens":7,"completion_tokens":3}}');
                }
            };
            $provider->setHttpClient($client);
            $answer = $provider->chatCompletion([Netresearch\NrLlm\Domain\ValueObject\ChatMessage::user('hello'), ['role' => 'assistant', 'content' => 'earlier']]);
            $provider->complete('single prompt', ['system_prompt' => 'instructions']);
            $models = $provider->getAvailableModels();
            $connection = $provider->testConnection();
            $embeddingFailure = null;
            try { $provider->embeddings('unsupported'); } catch (Throwable $failure) { $embeddingFailure = $failure::class; }
            echo json_encode(['unconfigured' => $unconfigured, 'configured' => $provider->isAvailable(), 'identifier' => $provider->getIdentifier(), 'features' => [$provider->supportsFeature('chat'), $provider->supportsFeature('completion'), $provider->supportsFeature('embeddings')], 'content' => $answer->content, 'model' => $answer->model, 'provider' => $answer->provider, 'usage' => [$answer->usage->promptTokens, $answer->usage->completionTokens, $answer->usage->totalTokens], 'models' => $models, 'connectionSuccess' => $connection['success'], 'modelRequests' => array_map(static fn(Psr\Http\Message\RequestInterface $request): array => [$request->getMethod(), (string)$request->getUri()], array_slice($client->requests, 2)), 'embeddingFailure' => $embeddingFailure, 'url' => (string)$client->requests[0]->getUri(), 'payload' => json_decode((string)$client->requests[0]->getBody(), true, 512, JSON_THROW_ON_ERROR), 'completionMessages' => json_decode((string)$client->requests[1]->getBody(), true, 512, JSON_THROW_ON_ERROR)['messages']], JSON_THROW_ON_ERROR);
            PROVIDER_EXAMPLE_RUNNER;
        $process = new Process([PHP_BINARY, '-r', $runner, $project, $mode], $project);
        $process->setInput(json_encode($examples, JSON_THROW_ON_ERROR));
        $process->setTimeout(15);
        $process->run();
        self::assertSame(
            0,
            $process->getExitCode(),
            $process->getOutput() . $process->getErrorOutput(),
        );
        $actual = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($actual);
        /** @var array<string, mixed> $actual */
        return $actual;
    }

    #[Test]
    public function documentedModelDiscoveryAndConnectivityMakeActualRequests(): void
    {
        $actual = $this->runExample('calls');
        self::assertSame(
            [
                ['GET', 'https://api.example.com/v1/models'],
                ['GET', 'https://api.example.com/v1/models'],
            ],
            $actual['modelRequests'],
        );
    }
}
