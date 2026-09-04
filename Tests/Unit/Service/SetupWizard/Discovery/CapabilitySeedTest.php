<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard\Discovery;

use Netresearch\NrLlm\Domain\DTO\CapabilitySet;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\AbstractModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\GeminiModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\GroqModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\MistralModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\OllamaModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\OpenAiModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\OpenRouterModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A discoverer may only write a capability token the provider's own response
 * substantiates.
 *
 * The payloads below are trimmed recordings of the real endpoints, keeping the
 * capability-bearing fields verbatim. They are the point of the test: the
 * previous seeds were derived from the model NAME or hardcoded per provider, so
 * every one of them passed a test written against a hand-made fixture that
 * agreed with the guess.
 */
#[CoversClass(GeminiModelDiscoverer::class)]
#[CoversClass(GroqModelDiscoverer::class)]
#[CoversClass(MistralModelDiscoverer::class)]
#[CoversClass(OllamaModelDiscoverer::class)]
#[CoversClass(OpenAiModelDiscoverer::class)]
#[CoversClass(OpenRouterModelDiscoverer::class)]
final class CapabilitySeedTest extends AbstractUnitTestCase
{
    #[Test]
    public function mistralReadsTheCapabilitiesObjectRatherThanAssumingToolSupport(): void
    {
        // Recorded from GET /v1/models: Mistral reports six booleans per model.
        $models = $this->discover(MistralModelDiscoverer::class, json_encode([
            'data' => [
                [
                    'id'           => 'mistral-small-latest',
                    'capabilities' => [
                        'completion_chat'  => true,
                        'completion_fim'   => false,
                        'function_calling' => true,
                        'fine_tuning'      => true,
                        'vision'           => false,
                        'classification'   => false,
                    ],
                ],
                [
                    'id'           => 'pixtral-12b',
                    'capabilities' => [
                        'completion_chat'  => true,
                        'function_calling' => false,
                        'vision'           => true,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['chat', 'tools'], $this->capabilitiesOf($models, 'mistral-small-latest'));
        // The old seed was ['chat', 'tools'] for EVERY model, so a vision model
        // lost the one token that distinguishes it and gained one it lacks.
        self::assertSame(['chat', 'vision'], $this->capabilitiesOf($models, 'pixtral-12b'));
    }

    #[Test]
    public function openRouterDerivesToolsAndVisionFromTheCatalogueEntry(): void
    {
        // Recorded from GET /api/v1/models. `supported_parameters` is the field
        // the runtime's own model filter reads for tool support; vision is an
        // INPUT modality. Note `modality` is a display string and never the
        // literal "multimodal" the provider used to compare against.
        $models = $this->discover(OpenRouterModelDiscoverer::class, json_encode([
            'data' => [
                [
                    'id'                   => 'openai/gpt-5.3',
                    'context_length'       => 400000,
                    'architecture'         => ['modality' => 'text+image->text', 'input_modalities' => ['text', 'image']],
                    'supported_parameters' => ['max_tokens', 'tool_choice', 'tools'],
                ],
                [
                    'id'                   => 'inclusionai/ling-3.0-tiny',
                    'context_length'       => 32000,
                    'architecture'         => ['modality' => 'text->text', 'input_modalities' => ['text']],
                    'supported_parameters' => ['max_tokens', 'temperature'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['chat', 'tools', 'vision'], $this->capabilitiesOf($models, 'openai/gpt-5.3'));
        // Both models used to be seeded a flat ['chat'].
        self::assertSame(['chat'], $this->capabilitiesOf($models, 'inclusionai/ling-3.0-tiny'));
    }

    #[Test]
    public function ollamaReadsTheReportedCapabilitiesInsteadOfGuessingFromTheName(): void
    {
        // Two tags chosen because the name guess gets both wrong: gemma3 is
        // tool-capable but matches none of the four hardcoded families, and
        // "mistral-small" matches one of them without the server reporting
        // tools for this quantisation.
        $models = $this->discoverOllama([
            'gemma3:12b'          => ['completion', 'tools', 'vision'],
            'mistral-small:22b-q4' => ['completion'],
        ]);

        self::assertSame(['chat', 'tools', 'vision'], $this->capabilitiesOf($models, 'gemma3:12b'));
        self::assertSame(['chat'], $this->capabilitiesOf($models, 'mistral-small:22b-q4'));
    }

    #[Test]
    public function ollamaFallsBackToChatWhenTheServerReportsNoCapabilities(): void
    {
        // Ollama below 0.6 has no `capabilities` key at all.
        $models = $this->discoverOllama(['legacy-model:latest' => null]);

        self::assertSame(['chat'], $this->capabilitiesOf($models, 'legacy-model:latest'));
    }

    #[Test]
    public function anUnknownGeminiModelIsDescribedByItsSupportedMethodsNotByAGuess(): void
    {
        // A release newer than the curated table. `supportedGenerationMethods`
        // is the listing's only capability-bearing field, and it says nothing
        // about vision -- which the old fallback claimed for every unknown id.
        $models = $this->discover(GeminiModelDiscoverer::class, json_encode([
            'models' => [
                [
                    'name'                       => 'models/gemini-9-nano',
                    'displayName'                => 'Gemini 9 Nano',
                    'supportedGenerationMethods' => ['generateContent', 'streamGenerateContent', 'countTokens'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['chat', 'streaming'], $this->capabilitiesOf($models, 'gemini-9-nano'));
    }

    #[Test]
    public function groqSeedsOnlyChatBecauseItsListingCarriesNoCapabilityField(): void
    {
        $models = $this->discover(GroqModelDiscoverer::class, json_encode([
            'data' => [
                ['id' => 'llama-3.3-70b-versatile', 'context_window' => 131072],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['chat'], $this->capabilitiesOf($models, 'llama-3.3-70b-versatile'));
    }

    /**
     * OpenAI's listing carries no capability fields at all -- id, object,
     * created, owned_by and nothing else -- so unlike every payload above,
     * the id is the only evidence that exists. That is why this discoverer
     * already keys on it for `tts-`, `whisper-` and `gpt-image`, and it is
     * the same instrument here: OpenAI names the modality in the id.
     *
     * `audio` is the chat-completions modality (a model that takes and
     * returns speech in an ordinary chat call), which is a different thing
     * from `text_to_speech` and `transcription` -- those name the dedicated
     * TTS and Whisper endpoints, with their own request shape.
     */
    #[Test]
    public function openAiSeedsAudioForTheModelsWhoseIdCarriesTheModality(): void
    {
        $models = $this->discover(OpenAiModelDiscoverer::class, json_encode([
            'data' => [
                ['id' => 'gpt-4o-audio-preview', 'object' => 'model', 'owned_by' => 'system'],
                ['id' => 'gpt-4o-mini-audio-preview', 'object' => 'model', 'owned_by' => 'system'],
                ['id' => 'gpt-4o', 'object' => 'model', 'owned_by' => 'system'],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['chat', 'audio'], $this->capabilitiesOf($models, 'gpt-4o-audio-preview'));
        self::assertSame(['chat', 'audio'], $this->capabilitiesOf($models, 'gpt-4o-mini-audio-preview'));
        // gpt-4o has a spec entry and a spec entry wins over the id shape.
        // Asserted so that a rule broad enough to touch every gpt-4o fails
        // here rather than passing quietly.
        self::assertNotContains('audio', $this->capabilitiesOf($models, 'gpt-4o'));
    }

    /**
     * The token vocabulary is closed. `reasoning` was written by two
     * discoverers and is not a ModelCapability, so CapabilitySet dropped it
     * silently and the TCA checkbox list could not display it -- an entry in
     * the stored CSV that nothing could read and nobody could edit.
     *
     * The static fallback catalogues are checked here because they are the one
     * capability source that never touches an API and so never appears in the
     * recorded-payload tests above. An unreachable endpoint is the shortest
     * route into that branch.
     */
    #[Test]
    public function everySeededTokenIsPartOfTheCapabilityVocabulary(): void
    {
        $checked = 0;
        foreach ([GeminiModelDiscoverer::class, MistralModelDiscoverer::class, OpenAiModelDiscoverer::class] as $class) {
            $models = $this->discovererFor($class, null)->discover('https://unreachable.invalid', 'k')->models;
            foreach ($models as $model) {
                $tokens = array_values($model->capabilities);
                self::assertCount(
                    count($tokens),
                    CapabilitySet::fromArray($tokens)->capabilities,
                    sprintf('%s seeds a token outside ModelCapability for %s: %s', $class, $model->modelId, implode(', ', $tokens)),
                );
                ++$checked;
            }
        }

        // Guards the guard: a fallback branch that stopped returning models
        // would make every assertion above vacuous.
        self::assertGreaterThan(0, $checked);
    }

    /**
     * @param list<DiscoveredModel> $models
     *
     * @return list<string>
     */
    private function capabilitiesOf(array $models, string $modelId): array
    {
        foreach ($models as $model) {
            if ($model->modelId === $modelId) {
                return array_values($model->capabilities);
            }
        }

        self::fail(sprintf('No discovered model with id "%s".', $modelId));
    }

    /**
     * @return list<DiscoveredModel>
     */
    private function discover(string $class, string $body): array
    {
        return array_values($this->discovererFor($class, $body)->discover('https://api.example.invalid', 'test-key')->models);
    }

    /**
     * Ollama needs two different responses: `/api/tags` lists the pulled tags,
     * and one `/api/show` per tag carries the capabilities.
     *
     * @param array<string, list<string>|null> $capabilitiesByTag
     *
     * @return list<DiscoveredModel>
     */
    private function discoverOllama(array $capabilitiesByTag): array
    {
        $tags = ['models' => []];
        foreach (array_keys($capabilitiesByTag) as $tag) {
            $tags['models'][] = ['name' => $tag, 'size' => 1024];
        }

        $bodies = [json_encode($tags, JSON_THROW_ON_ERROR)];
        foreach ($capabilitiesByTag as $capabilities) {
            $show = ['model_info' => ['general.context_length' => 8192]];
            if ($capabilities !== null) {
                $show['capabilities'] = $capabilities;
            }

            $bodies[] = json_encode($show, JSON_THROW_ON_ERROR);
        }

        return array_values(
            $this->discovererFor(OllamaModelDiscoverer::class, $bodies)
                ->discover('http://ollama.example.invalid:11434', '')
                ->models,
        );
    }

    /**
     * @param string|list<string>|null $body a single response body, one body per
     *                                       request in order, or null to make
     *                                       every dispatch fail
     */
    private function discovererFor(string $class, string|array|null $body): AbstractModelDiscoverer
    {
        $client = self::createStub(ClientInterface::class);
        if ($body === null) {
            $client->method('sendRequest')->willThrowException(new RuntimeException('unreachable'));
        } else {
            $queue = is_array($body) ? $body : [$body];
            $client->method('sendRequest')->willReturnCallback(
                function () use (&$queue): ResponseInterface {
                    $next = array_shift($queue) ?? '{}';

                    return $this->jsonResponse($next);
                },
            );
        }

        /** @var AbstractModelDiscoverer $discoverer */
        $discoverer = new $class(
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $discoverer->setHttpClient($client);

        return $discoverer;
    }

    private function jsonResponse(string $body): ResponseInterface&Stub
    {
        $stream = self::createStub(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
