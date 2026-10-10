<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Error;
use Netresearch\NrLlm\Controller\Backend\FormEngineUrlBuilder;
use Netresearch\NrLlm\Controller\Backend\SkillSourceController;
use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Service\Skill\MarketplaceParser;
use Netresearch\NrLlm\Service\Skill\SkillDiscovery;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use Netresearch\NrLlm\Service\Skill\SkillSyncService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Skill\Fixtures\FakeGitHubClient;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

#[CoversClass(SkillSourceController::class)]
final class SkillSourceControllerTest extends AbstractFunctionalTestCase
{
    private function setUpBackendRequest(): void
    {
        // Resolving an Extbase ActionController from the container triggers
        // injectConfigurationManager(), which reads settings from the current
        // request. Provide a backend request so the ConfigurationManager is
        // initialised properly.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    /**
     * Build the controller with an injected vault and optional diagnostic logger.
     * The sync service is unused by token actions.
     */
    private function controllerWithVault(
        VaultServiceInterface $vault,
        ?LoggerInterface $logger = null,
    ): SkillSourceController {
        $syncService = new SkillSyncService(
            new FakeGitHubClient(),
            new SkillMarkdownParser(),
            new MarketplaceParser(),
            new SkillDiscovery(),
            $this->get(SkillRepository::class),
            $this->get(SkillSourceRepository::class),
            $this->get(PersistenceManagerInterface::class),
            new NullLogger(),
        );
        return new SkillSourceController(
            $this->get(ModuleTemplateFactory::class),
            $this->get(SkillSourceRepository::class),
            $this->get(SkillRepository::class),
            $syncService,
            $vault,
            $this->get(PersistenceManagerInterface::class),
            $this->get(PageRenderer::class),
            $this->get(IconFactory::class),
            $this->get(FormEngineUrlBuilder::class),
            logger: $logger,
        );
    }

    private function persistedSource(): SkillSource
    {
        $source = new SkillSource();
        $source->setTitle('Acme');
        $source->setType(SkillSourceType::SINGLE_FILE->value);
        $source->setUrl('https://github.com/acme/skills/blob/main/SKILL.md');

        $repository = $this->get(SkillSourceRepository::class);
        $repository->add($source);
        $this->get(PersistenceManagerInterface::class)->persistAll();
        return $source;
    }

    #[Test]
    public function toggleSkillFlipsEnabledAndReturnsJson(): void
    {
        $this->importFixture('Skills.csv'); // uid 1 is enabled=1
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
        $this->setUpBackendRequest();
        $controller = $this->get(SkillSourceController::class);
        self::assertInstanceOf(SkillSourceController::class, $controller);

        $request = (new ServerRequest())->withParsedBody(['skill' => 1, 'enabled' => 0]);
        $response = $controller->toggleSkillAction($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);

        $this->get(PersistenceManagerInterface::class)->persistAll();
        $skill = $this->get(SkillRepository::class)->findBySourceAndIdentifier(1, '1:SKILL.md');
        self::assertNotNull($skill);
        self::assertFalse($skill->isEnabled());
    }

    #[Test]
    public function toggleSkillDeniedForNonAdmin(): void
    {
        $this->importFixture('Skills.csv');
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2); // uid 2 is a non-admin editor (admin=0)
        $this->setUpBackendRequest();
        $controller = $this->get(SkillSourceController::class);
        self::assertInstanceOf(SkillSourceController::class, $controller);

        $request = (new ServerRequest())->withParsedBody(['skill' => 1, 'enabled' => 0]);
        $response = $controller->toggleSkillAction($request);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);

        // The non-admin call must not have mutated the skill.
        $this->get(PersistenceManagerInterface::class)->persistAll();
        $skill = $this->get(SkillRepository::class)->findBySourceAndIdentifier(1, '1:SKILL.md');
        self::assertNotNull($skill);
        self::assertTrue($skill->isEnabled(), 'a denied call must leave the skill unchanged');
    }

    #[Test]
    public function syncActionDeniedForNonAdmin(): void
    {
        $source = $this->persistedSource();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2); // non-admin
        $this->setUpBackendRequest();
        $controller = $this->get(SkillSourceController::class);
        self::assertInstanceOf(SkillSourceController::class, $controller);

        $request = (new ServerRequest())->withParsedBody(['source' => $source->getUid()]);
        $response = $controller->syncAction($request);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);

        // The denied call must not have started a sync (status untouched).
        $this->get(PersistenceManagerInterface::class)->persistAll();
        $uid = $source->getUid();
        self::assertNotNull($uid);
        $reloaded = $this->get(SkillSourceRepository::class)->findByUid($uid);
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->getLastSynced(), 'a denied sync must not touch the source');
    }

    #[Test]
    public function setTokenActionDeniedForNonAdmin(): void
    {
        $source = $this->persistedSource();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(2); // non-admin
        $this->setUpBackendRequest();

        $vault = $this->createMock(VaultServiceInterface::class);
        $vault->expects(self::never())->method('store');
        $controller = $this->controllerWithVault($vault);

        $request = (new ServerRequest())->withParsedBody(['source' => $source->getUid(), 'token' => 'ghp_secret']);
        $response = $controller->setTokenAction($request);

        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);

        // The denied call must not have written a token to the source.
        $this->get(PersistenceManagerInterface::class)->persistAll();
        $uid = $source->getUid();
        self::assertNotNull($uid);
        $reloaded = $this->get(SkillSourceRepository::class)->findByUid($uid);
        self::assertNotNull($reloaded);
        self::assertSame('', $reloaded->getGithubToken());
    }

    #[Test]
    public function setTokenActionStoresVaultUuidForAdmin(): void
    {
        $source = $this->persistedSource();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
        // admin
        $this->setUpBackendRequest();

        $capturedId = '';
        $capturedSecret = '';
        $vault = $this->createMock(VaultServiceInterface::class);
        $vault
            ->expects(self::once())
            ->method('store')
            ->willReturnCallback(
                function (
                    string $id,
                    string $secret,
                ) use (&$capturedId, &$capturedSecret): void {
                    $capturedId = $id;
                    $capturedSecret = $secret;
                },
            );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $controller = $this->controllerWithVault($vault, $logger);

        $uid = $source->getUid();
        self::assertNotNull($uid);
        $request = (new ServerRequest())->withParsedBody(
            ['source' => $uid, 'token' => 'ghp_plaintext_secret'],
        );
        $response = $controller->setTokenAction($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['success']);

        // The plaintext token is handed to the vault; the source stores only the vault reference.
        self::assertSame('ghp_plaintext_secret', $capturedSecret);
        $this->get(PersistenceManagerInterface::class)->persistAll();
        $reloaded = $this->get(SkillSourceRepository::class)->findByUid($uid);
        self::assertNotNull($reloaded);
        self::assertNotSame('', $reloaded->getGithubToken());
        self::assertNotSame(
            'ghp_plaintext_secret',
            $reloaded->getGithubToken(),
            'the column holds a vault reference, not the plaintext token',
        );
        self::assertSame($capturedId, $reloaded->getGithubToken());
        self::assertStringStartsWith('ghtoken_', $reloaded->getGithubToken());
    }

    #[Test]
    public function setTokenActionReturnsJsonErrorWhenVaultStoreFails(): void
    {
        $source = $this->persistedSource();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1); // admin
        $this->setUpBackendRequest();

        // A vault/encryption failure must surface as a JSON 500 the module can
        // render, not a raw HTML 500 from an uncaught exception.
        $vault = $this->createMock(VaultServiceInterface::class);
        $vault->method('store')->willThrowException(new RuntimeException('vault unavailable'));
        $controller = $this->controllerWithVault($vault);

        $uid = $source->getUid();
        self::assertNotNull($uid);
        $request = (new ServerRequest())->withParsedBody(['source' => $uid, 'token' => 'ghp_plaintext_secret']);
        $response = $controller->setTokenAction($request);

        self::assertSame(500, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
        self::assertArrayHasKey('error', $payload);
    }

    #[Test]
    #[DataProvider('tokenDiagnosticCases')]
    public function tokenFailureRemainsGenericJsonWhenDiagnosticsFail(
        bool $nativeError,
        string $diagnosticMode,
    ): void {
        $source = $this->persistedSource();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
        $this->setUpBackendRequest();
        $cause = $nativeError ? new Error('private vault failure ghp_secret', 1770611095) : new RuntimeException('private vault failure ghp_secret', 1770611095);
        $diagnosticFailure = $diagnosticMode === 'error' ? new Error('private diagnostic failure ghp_secret', 1770611096) : new RuntimeException(
            'private diagnostic failure ghp_secret',
            1770611096,
        );
        $capturedId = '';
        $capturedSecret = '';
        $vault = $this->createMock(VaultServiceInterface::class);
        $vault
            ->method('store')
            ->willReturnCallback(
                static function (
                    string $id,
                    string $secret,
                ) use ($cause, &$capturedId, &$capturedSecret): never {
                    $capturedId = $id;
                    $capturedSecret = $secret;
                    throw $cause;
                },
            );
        $messages = [];
        $contexts = [];
        $logger = null;
        if ($diagnosticMode !== 'absent') {
            $logger = $this->createMock(LoggerInterface::class);
            $logger
                ->method('error')
                ->willReturnCallback(
                    static function (
                        string $message,
                        array $context,
                    ) use ($diagnosticMode, $diagnosticFailure, &$messages, &$contexts): void {
                        $messages[] = $message;
                        $contexts[] = $context;
                        if ($diagnosticMode !== 'healthy') {
                            throw $diagnosticFailure;
                        }
                    },
                );
        }

        $controller = $this->controllerWithVault($vault, $logger);
        $uid = $source->getUid();
        self::assertNotNull($uid);
        $request = (new ServerRequest())->withParsedBody(
            ['source' => $uid, 'token' => 'ghp_secret'],
        );
        $response = null;
        $escaped = null;
        try {
            $response = $controller->setTokenAction($request);
        } catch (Throwable $failure) {
            $escaped = $failure;
        }

        self::assertNull(
            $escaped,
            'A diagnostic failure must not replace the generic token-store JSON response.',
        );
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(500, $response->getStatusCode());
        $body = (string)$response->getBody();
        $payload = json_decode($body, true);
        self::assertSame(
            [
                'success' => false,
                'error' => 'Failed to store the token securely. See the system log for details.',
            ],
            $payload,
        );
        self::assertStringNotContainsString('ghp_secret', $body);
        self::assertStringNotContainsString($cause->getMessage(), $body);
        self::assertStringNotContainsString(
            $diagnosticFailure->getMessage(),
            $body,
        );
        self::assertSame('ghp_secret', $capturedSecret);
        self::assertStringStartsWith('ghtoken_', $capturedId);
        self::assertSame('', $source->getGithubToken());
        $stored = $this->get(SkillSourceRepository::class)->findByUid($uid);
        self::assertNotNull($stored);
        self::assertSame('', $stored->getGithubToken());
        if ($diagnosticMode === 'absent') {
            self::assertSame([], $messages);
            self::assertSame([], $contexts);
        } else {
            self::assertSame(
                ['Skill source: failed to store GitHub token'],
                $messages,
            );
            self::assertCount(1, $contexts);
            self::assertArrayHasKey('exception', $contexts[0]);
            self::assertSame($cause, $contexts[0]['exception']);
        }
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function tokenDiagnosticCases(): iterable
    {
        foreach ([false, true] as $nativeError) {
            foreach (['runtime', 'error', 'healthy', 'absent'] as $mode) {
                yield ($nativeError ? 'vault Error' : 'vault RuntimeException') . ' / logger ' . $mode => [$nativeError, $mode];
            }
        }
    }
}
