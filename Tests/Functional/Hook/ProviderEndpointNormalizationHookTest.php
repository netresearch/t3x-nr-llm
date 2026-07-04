<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Hook;

use Netresearch\NrLlm\Hook\ProviderEndpointNormalizationHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(ProviderEndpointNormalizationHook::class)]
final class ProviderEndpointNormalizationHookTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_provider';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
    }

    #[Test]
    public function createNormalizesBareOpenAiEndpointToV1(): void
    {
        // #300: a provider created through the TCA record editor (not the wizard)
        // must still get the canonical /v1 base URL OpenAiProvider requires.
        $uid = $this->createProvider('openai', 'https://api.openai.com');

        self::assertSame('https://api.openai.com/v1', $this->endpointOf($uid));
    }

    #[Test]
    public function createKeepsOllamaEndpointBare(): void
    {
        // Ollama's adapter adds "api/" per request, so its base URL must stay bare.
        $uid = $this->createProvider('ollama', 'http://localhost:11434');

        self::assertSame('http://localhost:11434', $this->endpointOf($uid));
    }

    #[Test]
    public function createIsIdempotentForAnAlreadyVersionedEndpoint(): void
    {
        $uid = $this->createProvider('openai', 'https://api.openai.com/v1');

        self::assertSame('https://api.openai.com/v1', $this->endpointOf($uid));
    }

    #[Test]
    public function editingOnlyTheEndpointNormalizesUsingTheStoredAdapterType(): void
    {
        // Start from a correct record, then edit ONLY the endpoint to a bare host
        // without sending adapter_type — the hook must read it from the stored row.
        $uid = $this->createProvider('openai', 'https://api.openai.com/v1');

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start(
            [self::TABLE => [$uid => ['endpoint_url' => 'https://api.openai.com']]],
            [],
        );
        $dataHandler->process_datamap();

        self::assertSame('https://api.openai.com/v1', $this->endpointOf($uid));
    }

    private function createProvider(string $adapterType, string $endpoint): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->BE_USER = $GLOBALS['BE_USER'];
        $dataHandler->start(
            [
                self::TABLE => [
                    'NEW1' => [
                        'pid'          => 0,
                        'identifier'   => 'provider-' . $adapterType,
                        'name'         => 'Provider ' . $adapterType,
                        'adapter_type' => $adapterType,
                        'endpoint_url' => $endpoint,
                    ],
                ],
            ],
            [],
        );
        $dataHandler->process_datamap();

        $uid = $dataHandler->substNEWwithIDs['NEW1'] ?? null;
        self::assertNotNull($uid, 'DataHandler must have created the provider record');

        return (int)$uid;
    }

    private function endpointOf(int $uid): string
    {
        $record = BackendUtility::getRecord(self::TABLE, $uid, 'endpoint_url');
        self::assertIsArray($record);

        return is_string($record['endpoint_url'] ?? null) ? $record['endpoint_url'] : '';
    }
}
