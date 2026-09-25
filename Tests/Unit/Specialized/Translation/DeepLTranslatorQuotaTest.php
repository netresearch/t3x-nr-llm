<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use Netresearch\NrLlm\Controller\Backend\SpecializedTestController;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Image\ImageGeneratorInterface;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Specialized\Translation\CharacterQuota;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Tests\Fixture\AllowingBudgetService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The DeepL character quota (ADR-207): what the translator reads from DeepL's
 * usage endpoint, which endpoint it asks, and what the backend endpoint that
 * shows it to an administrator lets through.
 */
#[CoversClass(DeepLTranslator::class)]
#[CoversClass(CharacterQuota::class)]
#[CoversClass(SpecializedTestController::class)]
#[AllowMockObjectsWithoutExpectations]
final class DeepLTranslatorQuotaTest extends AbstractUnitTestCase
{
    private const KEY_IDENTIFIER = 'deepl-key-identifier';

    /**
     * A secret no output may contain. The `:fx` suffix marks a Free key. Both
     * values are deliberately low-entropy so the secret scanner does not take
     * them for real keys.
     */
    private const FREE_SECRET = 'not-a-real-deepl-key-free:fx';

    private const PRO_SECRET = 'not-a-real-deepl-key-pro';

    private mixed $previousBeUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
        $admin = new BackendUserAuthentication();
        $admin->user = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $admin;
    }

    protected function tearDown(): void
    {
        if ($this->previousBeUser === null) {
            unset($GLOBALS['BE_USER']);
        } else {
            $GLOBALS['BE_USER'] = $this->previousBeUser;
        }

        parent::tearDown();
    }

    #[Test]
    public function aFreeKeyIsAskedAtTheFreeEndpointAndReportedAsFree(): void
    {
        $requests = [];
        $translator = $this->translator(self::FREE_SECRET, $this->usage(12345, 500000), $requests);

        $quota = $translator->getCharacterQuota();

        self::assertSame(['GET https://api-free.deepl.com/v2/usage'], $requests);
        self::assertSame(12345, $quota->used);
        self::assertSame(500000, $quota->limit);
        self::assertSame(CharacterQuota::PLAN_FREE, $quota->plan);
        self::assertSame(2.5, $quota->usedPercent());
    }

    #[Test]
    public function aProKeyIsAskedAtTheProEndpointAndReportedAsPro(): void
    {
        $requests = [];
        $translator = $this->translator(self::PRO_SECRET, $this->usage(7, 1000000), $requests);

        $quota = $translator->getCharacterQuota();

        self::assertSame(['GET https://api.deepl.com/v2/usage'], $requests);
        self::assertSame(CharacterQuota::PLAN_PRO, $quota->plan);
    }

    #[Test]
    public function aConfiguredBaseUrlIsReportedAsCustom(): void
    {
        $requests = [];
        $translator = $this->translator(
            self::FREE_SECRET,
            $this->usage(1, 2),
            $requests,
            'https://deepl-proxy.example',
        );

        $quota = $translator->getCharacterQuota();

        self::assertSame(['GET https://deepl-proxy.example/v2/usage'], $requests);
        self::assertSame(CharacterQuota::PLAN_CUSTOM, $quota->plan);
    }

    #[Test]
    public function aMissingLimitIsNotTurnedIntoAPercentage(): void
    {
        $requests = [];
        $quota = $this->translator(self::PRO_SECRET, $this->usage(99, 0), $requests)->getCharacterQuota();

        self::assertSame(0, $quota->limit);
        self::assertNull($quota->usedPercent());
    }

    #[Test]
    public function theBackendEndpointReportsTheQuota(): void
    {
        $requests = [];
        $response = $this->controllerFor(
            $this->translator(self::FREE_SECRET, $this->usage(12345, 500000), $requests),
        )->deeplQuotaAction();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['success' => true, 'used' => 12345, 'limit' => 500000, 'usedPercent' => 2.5, 'plan' => 'free'],
            json_decode((string)$response->getBody(), true),
        );
    }

    /**
     * @return iterable<string, array{int, string, int}>
     */
    public static function failingUsageResponses(): iterable
    {
        // DeepL refuses the key: the operator must fix the secret.
        yield 'key refused (403)' => [403, 'Wrong key ' . self::FREE_SECRET, 502];
        // DeepL fails and echoes the key into its message; the message travels
        // inside the exception text and must still not reach the page.
        yield 'DeepL error echoing the key (500)' => [500, 'Internal error for ' . self::FREE_SECRET, 503];
        // A rate limit is neither: the generic failure, detail in the log.
        yield 'rate limited (429)' => [429, 'Too many requests for ' . self::FREE_SECRET, 500];
    }

    #[Test]
    #[DataProvider('failingUsageResponses')]
    public function anApiErrorGivesAReadableMessageAndNeverTheKey(int $status, string $message, int $expectedStatus): void
    {
        $requests = [];
        $response = $this->controllerFor(
            $this->translator(self::FREE_SECRET, $this->createJsonResponseMock(['message' => $message], $status), $requests),
        )->deeplQuotaAction();

        $body = (string)$response->getBody();
        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertStringNotContainsString(self::FREE_SECRET, $body);
        self::assertStringNotContainsString('deepl-key-free', $body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertFalse($decoded['success']);
        self::assertIsString($decoded['error']);
        self::assertNotSame('', $decoded['error']);
    }

    #[Test]
    public function aSuccessfulAnswerCarriesNeitherTheKeyNorItsIdentifier(): void
    {
        $requests = [];
        $body = (string)$this->controllerFor(
            $this->translator(self::FREE_SECRET, $this->usage(1, 2), $requests),
        )->deeplQuotaAction()->getBody();

        self::assertStringNotContainsString(self::FREE_SECRET, $body);
        self::assertStringNotContainsString(self::KEY_IDENTIFIER, $body);
    }

    private function controllerFor(DeepLTranslator $translator): SpecializedTestController
    {
        $translationService = self::createStub(TranslationServiceInterface::class);
        $translationService->method('getTranslator')->willReturn($translator);

        return new SpecializedTestController(
            $translationService,
            self::createStub(ImageGeneratorInterface::class),
            self::createStub(ImageGeneratorInterface::class),
            new NullLogger(),
        );
    }

    /**
     * A real DeepLTranslator whose vault holds $secret and whose HTTP client
     * answers every request with $response, recording `METHOD URI` of each.
     *
     * @param list<string> $requests
     */
    private function translator(
        string $secret,
        ResponseInterface $response,
        array &$requests,
        ?string $baseUrl = null,
    ): DeepLTranslator {
        $deepl = ['apiKeyIdentifier' => self::KEY_IDENTIFIER];
        if ($baseUrl !== null) {
            $deepl['baseUrl'] = $baseUrl;
        }

        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use (&$requests, $response): ResponseInterface {
                $requests[] = $request->getMethod() . ' ' . $request->getUri();

                return $response;
            },
        );

        $translator = new DeepLTranslator(
            $this->createVaultServiceMock([self::KEY_IDENTIFIER => $secret]),
            $this->createRequestFactoryMock(),
            $this->createStreamFactoryMock(),
            $this->createExtensionConfigurationMock(['translators' => ['deepl' => $deepl]]),
            self::createStub(UsageTrackerServiceInterface::class),
            $this->createLoggerMock(),
            self::createStub(SpecializedCostCalculatorInterface::class),
            new AllowingBudgetService(),
            new MiddlewarePipeline([]),
            new InputGuardrailScreener([]),
        );
        $translator->setHttpClient($client);

        return $translator;
    }

    private function usage(int $count, int $limit): ResponseInterface
    {
        return $this->createJsonResponseMock(['character_count' => $count, 'character_limit' => $limit]);
    }
}
