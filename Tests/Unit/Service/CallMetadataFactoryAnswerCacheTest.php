<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\CallMetadataFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The key of the answer cache (ADR-249): everything that shapes the answer is
 * in it, and a configuration that did not opt in gets no key at all.
 */
#[CoversClass(CallMetadataFactory::class)]
final class CallMetadataFactoryAnswerCacheTest extends TestCase
{
    #[Test]
    public function aConfigurationWithoutTtlGetsNoKey(): void
    {
        self::assertSame([], $this->build($this->configuration(ttl: 0)));
    }

    #[Test]
    public function anOptedInConfigurationGetsKeyTtlTagsAndTheMarker(): void
    {
        $metadata = $this->build($this->configuration(ttl: 600));

        self::assertIsString($metadata[CacheMiddleware::METADATA_CACHE_KEY]);
        self::assertMatchesRegularExpression('/^nrllm_answer_[0-9a-f]{64}$/', $metadata[CacheMiddleware::METADATA_CACHE_KEY]);
        self::assertSame(600, $metadata[CacheMiddleware::METADATA_CACHE_TTL]);
        self::assertSame(['nrllm_answers', 'nrllm_answers_shop_writer'], $metadata[CacheMiddleware::METADATA_CACHE_TAGS]);
        self::assertTrue($metadata[CacheMiddleware::METADATA_ANSWER_CACHE]);
    }

    #[Test]
    public function theSameCallGetsTheSameKey(): void
    {
        self::assertSame($this->key($this->configuration()), $this->key($this->configuration()));
    }

    #[Test]
    public function eachInputThatShapesTheAnswerChangesTheKey(): void
    {
        $base = $this->key($this->configuration());

        $keys = [
            'message'       => $this->key($this->configuration(), messages: [ChatMessage::user('Hallo!')]),
            'role'          => $this->key($this->configuration(), messages: [ChatMessage::system('Hallo')]),
            'option'        => $this->key($this->configuration(), options: ['temperature' => 0.1]),
            'operation'     => $this->key($this->configuration(), operation: ProviderOperation::Completion),
            'site'          => $this->key($this->configuration(), metadata: [ProviderCallContext::METADATA_SITE => 'blog']),
            'language'      => $this->key($this->configuration(), metadata: [ProviderCallContext::METADATA_SITE => 'shop', ProviderCallContext::METADATA_LANGUAGE => 'de']),
            'configuration' => $this->key($this->configuration(identifier: 'other')),
            'record'        => $this->key($this->configuration(uid: 8)),
            'edited'        => $this->key($this->configuration(tstamp: 2000)),
        ];

        foreach ($keys as $what => $key) {
            self::assertNotSame($base, $key, $what . ' must change the key');
        }

        self::assertCount(count($keys), array_unique($keys));
    }

    #[Test]
    public function metadataThatDoesNotShapeTheAnswerLeavesTheKeyAlone(): void
    {
        self::assertSame(
            $this->key($this->configuration()),
            $this->key($this->configuration(), metadata: [ProviderCallContext::METADATA_SITE => 'shop', 'budget.beUserUid' => 7, 'nrllm.correlationId' => 'abc']),
        );
    }

    #[Test]
    public function aPromptKeysLikeTheSameUserMessage(): void
    {
        $factory = new CallMetadataFactory();

        self::assertSame(
            $factory->answerCache($this->configuration(), ProviderOperation::Completion, 'Hallo', [], [ProviderCallContext::METADATA_SITE => 'shop'])[CacheMiddleware::METADATA_CACHE_KEY],
            $factory->answerCache($this->configuration(), ProviderOperation::Completion, [ChatMessage::user('Hallo')], [], [ProviderCallContext::METADATA_SITE => 'shop'])[CacheMiddleware::METADATA_CACHE_KEY],
        );
    }

    /**
     * @param list<ChatMessage>|null $messages
     * @param array<string, mixed>   $options
     * @param array<string, mixed>   $metadata
     */
    private function key(
        LlmConfiguration $configuration,
        ?array $messages = null,
        array $options = [],
        ProviderOperation $operation = ProviderOperation::Chat,
        array $metadata = [ProviderCallContext::METADATA_SITE => 'shop'],
    ): string {
        $key = $this->build($configuration, $messages, $options, $operation, $metadata)[CacheMiddleware::METADATA_CACHE_KEY] ?? null;
        self::assertIsString($key);

        return $key;
    }

    /**
     * @param list<ChatMessage>|null $messages
     * @param array<string, mixed>   $options
     * @param array<string, mixed>   $metadata
     *
     * @return array<string, mixed>
     */
    private function build(
        LlmConfiguration $configuration,
        ?array $messages = null,
        array $options = [],
        ProviderOperation $operation = ProviderOperation::Chat,
        array $metadata = [ProviderCallContext::METADATA_SITE => 'shop'],
    ): array {
        return (new CallMetadataFactory())->answerCache(
            $configuration,
            $operation,
            $messages ?? [ChatMessage::user('Hallo')],
            $options,
            $metadata,
        );
    }

    private function configuration(int $ttl = 600, string $identifier = 'shop.writer', int $uid = 7, int $tstamp = 1000): LlmConfiguration
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getAnswerCacheTtl')->willReturn($ttl);
        $configuration->method('getIdentifier')->willReturn($identifier);
        $configuration->method('getUid')->willReturn($uid);
        $configuration->method('getTstamp')->willReturn($tstamp);

        return $configuration;
    }
}
