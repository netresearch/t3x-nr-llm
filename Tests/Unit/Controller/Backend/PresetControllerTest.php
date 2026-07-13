<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\PresetController;
use Netresearch\NrLlm\Domain\DTO\ModelSelectionCriteria;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Preset\ConfigurationPreset;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetImportService;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Preset\Fixtures\FixturePresetProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Unit tests for the PresetController AJAX actions (ADR-056).
 *
 * Tests the PSR-7 AJAX handlers directly without Extbase initialization,
 * mirroring ConfigurationControllerTest: an admin BE_USER is provided so the
 * RequiresBackendAdminTrait guard (ADR-037) lets the tests reach the action
 * body; the denial paths drop the admin flag.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(PresetController::class)]
final class PresetControllerTest extends TestCase
{
    private mixed $previousBeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $backendUser;
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

    private static function preset(): ConfigurationPreset
    {
        return new ConfigurationPreset(
            identifier: 'ext.chat',
            name: 'Chat',
            description: 'A chat preset.',
            criteria: new ModelSelectionCriteria(capabilities: ['chat']),
        );
    }

    /**
     * Build the controller around a registry holding the given presets, a
     * repository answering findOneByIdentifier() with $existing, and a model
     * selection answering findMatchingModel() with $matchedModel.
     *
     * @param list<ConfigurationPreset> $presets
     */
    private function createController(
        array $presets,
        ?LlmConfiguration $existing = null,
        ?Model $matchedModel = null,
    ): PresetController {
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($existing);

        $modelSelection = $this->createMock(ModelSelectionServiceInterface::class);
        $modelSelection->method('findMatchingModel')->willReturn($matchedModel);
        $modelSelection->method('findCandidates')->willReturn([]);

        $registry = new ConfigurationPresetRegistry([new FixturePresetProvider($presets)], $repository);
        $importService = new ConfigurationPresetImportService(
            $modelSelection,
            $repository,
            $this->createMock(PersistenceManagerInterface::class),
        );

        return new PresetController($registry, $importService);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    #[Test]
    public function listPresetsDeniesNonAdmin(): void
    {
        unset($GLOBALS['BE_USER']);
        $controller = $this->createController([]);

        $response = $controller->listPresetsAction(new ServerRequest());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function importDeniesNonAdmin(): void
    {
        unset($GLOBALS['BE_USER']);
        $controller = $this->createController([]);

        $response = $controller->importAction(new ServerRequest());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function listPresetsReturnsPendingPresetsWithPreflight(): void
    {
        $model = new Model();
        $model->setName('Claude Sonnet');
        $controller = $this->createController([self::preset()], existing: null, matchedModel: $model);

        $response = $controller->listPresetsAction(new ServerRequest());

        self::assertSame(200, $response->getStatusCode());
        $body = self::decode($response);
        self::assertTrue($body['success']);
        assert(isset($body['presets']) && is_array($body['presets']));
        self::assertCount(1, $body['presets']);
        $entry = $body['presets'][0];
        assert(is_array($entry));
        self::assertSame('ext.chat', $entry['identifier']);
        self::assertSame('Chat', $entry['name']);
        self::assertTrue($entry['satisfiable']);
        self::assertSame('Claude Sonnet', $entry['matchedModelLabel']);
        self::assertNull($entry['missingRequirement']);
    }

    #[Test]
    public function listPresetsOmitsAlreadyImportedPresets(): void
    {
        $controller = $this->createController([self::preset()], existing: new LlmConfiguration());

        $body = self::decode($controller->listPresetsAction(new ServerRequest()));

        self::assertSame([], $body['presets']);
    }

    #[Test]
    public function importCreatesConfigurationForKnownPreset(): void
    {
        // The registry must see the identifier as pending while the import
        // service checks for duplicates against the same repository — a null
        // answer serves both.
        $controller = $this->createController([self::preset()], existing: null, matchedModel: new Model());
        $request = (new ServerRequest())->withParsedBody(['identifier' => 'ext.chat']);

        $response = $controller->importAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = self::decode($response);
        self::assertTrue($body['success']);
        self::assertSame('ext.chat', $body['identifier']);
    }

    #[Test]
    public function importReturns404ForUnknownIdentifier(): void
    {
        $controller = $this->createController([self::preset()]);
        $request = (new ServerRequest())->withParsedBody(['identifier' => 'ext.unknown']);

        $response = $controller->importAction($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse(self::decode($response)['success']);
    }

    #[Test]
    public function importReturns422WhenPresetIsUnsatisfiable(): void
    {
        // No matched model and no candidates: preflight is unsatisfiable.
        $controller = $this->createController([self::preset()], existing: null, matchedModel: null);
        $request = (new ServerRequest())->withParsedBody(['identifier' => 'ext.chat']);

        $response = $controller->importAction($request);

        self::assertSame(422, $response->getStatusCode());
        $body = self::decode($response);
        self::assertFalse($body['success']);
        assert(isset($body['error']) && is_string($body['error']));
        self::assertStringContainsString('capabilities: chat', $body['error']);
    }
}
