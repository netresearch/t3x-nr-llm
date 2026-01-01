<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Domain;

use Eris\Generator;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Property-based tests for Provider entity.
 *
 * Tests input validation and edge cases with random inputs.
 */
#[CoversNothing]
class ProviderFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function identifierPreservesValidCharacters(): void
    {
        $this
            ->forAll(
                // @phpstan-ignore function.notFound
                Generator\suchThat(
                    static fn(string $s) => preg_match('/^[a-zA-Z0-9_-]+$/', $s) === 1 && strlen($s) <= 100,
                    Generator\string(), // @phpstan-ignore function.notFound
                ),
            )
            ->then(function (string $identifier): void {
                $provider = new Provider();
                $provider->setIdentifier($identifier);

                $this->assertSame($identifier, $provider->getIdentifier());
            });
    }

    #[Test]
    public function namePreservesAnyString(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\string())
            ->then(function (string $name): void {
                $provider = new Provider();
                $provider->setName($name);

                $this->assertSame($name, $provider->getName());
            });
    }

    #[Test]
    public function adapterTypePreservesValue(): void
    {
        $validAdapterTypes = [
            'openai',
            'anthropic',
            'gemini',
            'openrouter',
            'mistral',
            'groq',
            'ollama',
            'azure_openai',
            'custom',
        ];

        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\elements($validAdapterTypes))
            ->then(function (string $adapterType): void {
                $provider = new Provider();
                $provider->setAdapterType($adapterType);

                $this->assertSame($adapterType, $provider->getAdapterType());
            });
    }

    #[Test]
    public function endpointUrlPreservesValidUrls(): void
    {
        $this
            ->forAll(
                // @phpstan-ignore function.notFound
                Generator\suchThat(
                    static fn(string $s) => filter_var($s, FILTER_VALIDATE_URL) !== false,
                    // @phpstan-ignore function.notFound
                    Generator\map(
                        static fn(string $path) => 'https://api.example.com/' . preg_replace('/[^a-z0-9]/i', '', $path),
                        Generator\string(), // @phpstan-ignore function.notFound
                    ),
                ),
            )
            ->then(function (string $url): void {
                $provider = new Provider();
                $provider->setEndpointUrl($url);

                $this->assertSame($url, $provider->getEndpointUrl());
            });
    }

    #[Test]
    public function apiKeyPreservesValue(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\string())
            ->then(function (string $apiKey): void {
                $provider = new Provider();
                $provider->setApiKey($apiKey);

                $this->assertSame($apiKey, $provider->getApiKey());
            });
    }

    #[Test]
    public function organizationIdPreservesValue(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\string())
            ->then(function (string $orgId): void {
                $provider = new Provider();
                $provider->setOrganizationId($orgId);

                $this->assertSame($orgId, $provider->getOrganizationId());
            });
    }

    #[Test]
    public function timeoutIsPositiveInteger(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\int())
            ->then(function (int $timeout): void {
                $provider = new Provider();
                $provider->setTimeout($timeout);

                $result = $provider->getTimeout();

                // Timeout should be at least 1
                $this->assertGreaterThanOrEqual(1, $result);
            });
    }

    #[Test]
    public function maxRetriesIsNonNegative(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\int())
            ->then(function (int $retries): void {
                $provider = new Provider();
                $provider->setMaxRetries($retries);

                $result = $provider->getMaxRetries();

                // Max retries should be at least 0
                $this->assertGreaterThanOrEqual(0, $result);
            });
    }

    #[Test]
    public function isActiveReturnsBoolean(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\bool())
            ->then(function (bool $active): void {
                $provider = new Provider();
                $provider->setIsActive($active);

                $this->assertSame($active, $provider->isActive());
            });
    }

    #[Test]
    public function positiveTimeoutIsPreserved(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\choose(1, 300))
            ->then(function (int $timeout): void {
                $provider = new Provider();
                $provider->setTimeout($timeout);

                $this->assertSame($timeout, $provider->getTimeout());
            });
    }

    #[Test]
    public function positiveMaxRetriesIsPreserved(): void
    {
        $this
            // @phpstan-ignore function.notFound
            ->forAll(Generator\choose(0, 10))
            ->then(function (int $retries): void {
                $provider = new Provider();
                $provider->setMaxRetries($retries);

                $this->assertSame($retries, $provider->getMaxRetries());
            });
    }

    #[Test]
    public function optionsJsonIsValidWhenSet(): void
    {
        $this
            ->forAll(
                // @phpstan-ignore function.notFound
                Generator\associative([
                    // @phpstan-ignore function.notFound
                    'headers' => Generator\associative([
                        'X-Custom' => Generator\string(), // @phpstan-ignore function.notFound
                    ]),
                    'verify_ssl' => Generator\bool(), // @phpstan-ignore function.notFound
                ]),
            )
            ->then(function (array $options): void {
                $provider = new Provider();
                $json = json_encode($options);
                $provider->setOptions($json !== false ? $json : '{}');

                $decoded = $provider->getOptionsArray();

                $this->assertArrayHasKey('headers', $decoded);
            });
    }

    #[Test]
    public function descriptionPreservesMultilineText(): void
    {
        $this
            ->forAll(
                // @phpstan-ignore function.notFound
                Generator\bind(
                    // @phpstan-ignore function.notFound
                    Generator\vector(5, Generator\string()), // @phpstan-ignore function.notFound
                    static fn(array $lines) => Generator\constant(implode("\n", $lines)), // @phpstan-ignore function.notFound
                ),
            )
            ->then(function (string $description): void {
                $provider = new Provider();
                $provider->setDescription($description);

                $this->assertSame($description, $provider->getDescription());
            });
    }
}
