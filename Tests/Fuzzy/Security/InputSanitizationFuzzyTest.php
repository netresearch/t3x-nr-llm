<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Security;

use Eris\Generator;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Security-focused fuzzy tests for input sanitization.
 *
 * Tests that potentially malicious inputs are handled safely.
 */
#[CoversNothing]
class InputSanitizationFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function configurationHandlesNullBytes(): void
    {
        $this
            ->forAll(
                Generator\map(
                    static fn(string $s) => $s . "\0" . $s,
                    Generator\string(),
                ),
            )
            ->then(function (string $input): void {
                $config = new LlmConfiguration();

                // Setting values with null bytes should not cause exceptions
                $config->setIdentifier(str_replace("\0", '', $input));
                $config->setName($input);
                $config->setSystemPrompt($input);

                // Values should be retrievable
                $this->assertIsString($config->getName());
                $this->assertIsString($config->getSystemPrompt());
            });
    }

    #[Test]
    public function configurationHandlesControlCharacters(): void
    {
        $controlChars = array_map('chr', range(0, 31));

        $this
            ->forAll(
                Generator\map(
                    static fn(string $s) => $s . implode('', $controlChars) . $s,
                    Generator\string(),
                ),
            )
            ->then(function (string $input): void {
                $config = new LlmConfiguration();
                $config->setName($input);
                $config->setSystemPrompt($input);

                // Should handle without exceptions
                $this->assertIsString($config->getName());
                $this->assertIsString($config->getSystemPrompt());
            });
    }

    #[Test]
    public function providerHandlesSpecialUrlCharacters(): void
    {
        $this
            ->forAll(
                Generator\elements([
                    'https://api.example.com/v1?key=value&other=test',
                    'https://user:pass@api.example.com/path',
                    'https://api.example.com/path#fragment',
                    'https://api.example.com/path%20with%20spaces',
                    'https://api.example.com/../../../etc/passwd',
                    'https://api.example.com/\r\nX-Injected: header',
                ]),
            )
            ->then(function (string $url): void {
                $provider = new Provider();
                $provider->setEndpointUrl($url);

                // Should store the URL as-is (validation happens elsewhere)
                $this->assertIsString($provider->getEndpointUrl());
            });
    }

    #[Test]
    public function providerHandlesPotentialInjectionInApiKey(): void
    {
        $this
            ->forAll(
                Generator\elements([
                    "sk-test-key'; DROP TABLE users; --",
                    'sk-test-key" OR "1"="1',
                    'sk-test-key<script>alert("xss")</script>',
                    'sk-test-key${process.env.SECRET}',
                    'sk-test-key`whoami`',
                    "sk-test-key\r\nX-Injected: malicious",
                ]),
            )
            ->then(function (string $apiKey): void {
                $provider = new Provider();
                $provider->setApiKey($apiKey);

                // API key should be stored as-is (it's a credential)
                $this->assertSame($apiKey, $provider->getApiKey());
            });
    }

    #[Test]
    public function modelHandlesUnicodeEdgeCases(): void
    {
        $this
            ->forAll(
                Generator\elements([
                    "model\u{200B}name", // Zero-width space
                    "model\u{FEFF}name", // BOM
                    "model\u{200D}name", // Zero-width joiner
                    "model\u{202E}name", // Right-to-left override
                    "model\u{2028}name", // Line separator
                    "model\u{2029}name", // Paragraph separator
                ]),
            )
            ->then(function (string $name): void {
                $model = new Model();
                $model->setName($name);

                // Should preserve unicode characters
                $this->assertSame($name, $model->getName());
            });
    }

    #[Test]
    public function configurationHandlesExtremelyLongStrings(): void
    {
        $this
            ->forAll(
                Generator\map(
                    static fn(int $len) => str_repeat('a', min($len, 100000)),
                    Generator\choose(1000, 10000),
                ),
            )
            ->then(function (string $longString): void {
                $config = new LlmConfiguration();
                $config->setSystemPrompt($longString);

                // Should handle long strings without memory issues
                $this->assertIsString($config->getSystemPrompt());
                $this->assertGreaterThan(0, strlen($config->getSystemPrompt()));
            });
    }

    #[Test]
    public function configurationHandlesJsonInjection(): void
    {
        $this
            ->forAll(
                Generator\elements([
                    '{"key": "value", "injected": true}',
                    '{"__proto__": {"admin": true}}',
                    '{"constructor": {"prototype": {"admin": true}}}',
                    '[1,2,3]',
                    'null',
                    'true',
                    '{"nested": {"deeply": {"value": "test"}}}',
                ]),
            )
            ->then(function (string $json): void {
                $config = new LlmConfiguration();
                $config->setOptions($json);

                $result = $config->getOptionsArray();

                // Should always return an array (may be empty for invalid JSON)
                $this->assertIsArray($result);
            });
    }

    #[Test]
    public function providerHandlesJsonOptionsInjection(): void
    {
        $this
            ->forAll(
                Generator\elements([
                    '{"headers": {"X-Evil": "value"}}',
                    '{"proxy": "http://evil.com"}',
                    '{"verify_ssl": false}',
                    '{"timeout": 999999}',
                    'not valid json',
                    '',
                ]),
            )
            ->then(function (string $json): void {
                $provider = new Provider();
                $provider->setOptions($json);

                $result = $provider->getOptionsArray();

                // Should always return an array
                $this->assertIsArray($result);
            });
    }

    #[Test]
    public function modelCapabilitiesHandleInvalidInput(): void
    {
        $this
            ->forAll(
                Generator\elements([
                    'chat,completion,invalid_capability',
                    ',,,chat,,,',
                    'CHAT,COMPLETION',
                    'chat;completion',
                    'chat|completion',
                    '',
                ]),
            )
            ->then(function (string $capabilities): void {
                $model = new Model();
                $model->setCapabilities($capabilities);

                $result = $model->getCapabilitiesArray();

                // Should always return an array (may be empty)
                $this->assertIsArray($result);
            });
    }

    #[Test]
    public function numericFieldsHandleEdgeCases(): void
    {
        $this
            ->forAll(
                Generator\elements([
                    PHP_INT_MAX,
                    PHP_INT_MIN,
                    0,
                    -1,
                    1,
                ]),
            )
            ->then(function (int $value): void {
                $config = new LlmConfiguration();

                // These should all be handled without exceptions
                $config->setMaxTokens($value);

                $result = $config->getMaxTokens();
                $this->assertIsInt($result);
                $this->assertGreaterThanOrEqual(1, $result);
            });
    }

    #[Test]
    public function floatFieldsHandleSpecialValues(): void
    {
        $this
            ->forAll(
                Generator\elements([
                    INF,
                    -INF,
                    NAN,
                    PHP_FLOAT_MAX,
                    PHP_FLOAT_MIN,
                    PHP_FLOAT_EPSILON,
                    0.0,
                    -0.0,
                ]),
            )
            ->then(function (float $value): void {
                $config = new LlmConfiguration();

                // Temperature should be clamped to valid range
                $config->setTemperature($value);
                $result = $config->getTemperature();

                if (!is_nan($value)) {
                    $this->assertGreaterThanOrEqual(0.0, $result);
                    $this->assertLessThanOrEqual(2.0, $result);
                }
            });
    }
}
