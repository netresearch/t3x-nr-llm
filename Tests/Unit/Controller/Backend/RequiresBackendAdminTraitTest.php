<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\RequiresBackendAdminTrait;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Unit tests for the shared backend-admin guard trait.
 *
 * The trait method is private; an anonymous class `using` the trait exposes it
 * so the guard can be exercised directly against the three relevant
 * `$GLOBALS['BE_USER']` states: admin, non-admin, and absent.
 */
#[CoversNothing]
final class RequiresBackendAdminTraitTest extends TestCase
{
    private mixed $previousBeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousBeUser = $GLOBALS['BE_USER'] ?? null;
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

    /**
     * Run the guard via an anonymous class that `uses` the trait and exposes
     * the otherwise-private method.
     */
    private function guardResult(): ?ResponseInterface
    {
        $subject = new class {
            use RequiresBackendAdminTrait;

            public function expose(): ?ResponseInterface
            {
                return $this->denyNonAdmin();
            }
        };

        return $subject->expose();
    }

    #[Test]
    public function denyNonAdminReturnsNullForAdminUser(): void
    {
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 1, 'admin' => 1];
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertNull($this->guardResult());
    }

    #[Test]
    public function denyNonAdminReturnsForbiddenForNonAdminUser(): void
    {
        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 2, 'admin' => 0];
        $GLOBALS['BE_USER'] = $backendUser;

        $response = $this->guardResult();

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
        // Actionable, admin-oriented message (translated when a language service
        // is available, else the English fallback) rather than a bare "Forbidden".
        self::assertIsString($payload['error']);
        self::assertStringContainsStringIgnoringCase('administrator', $payload['error']);
    }

    #[Test]
    public function denyNonAdminReturnsForbiddenWhenNoBackendUserIsPresent(): void
    {
        unset($GLOBALS['BE_USER']);

        $response = $this->guardResult();

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
    }

    /**
     * Run the grant guard via an anonymous class exposing the private method.
     */
    private function grantGuardResult(): ?ResponseInterface
    {
        $subject = new class {
            use RequiresBackendAdminTrait;

            public function expose(): ?ResponseInterface
            {
                return $this->denyWithoutGrant(BackendUserGrant::TASKS_USE);
            }
        };

        return $subject->expose();
    }

    #[Test]
    public function denyWithoutGrantPassesAdminsWithoutAnyGrant(): void
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertNull($this->grantGuardResult());
    }

    #[Test]
    public function denyWithoutGrantPassesANonAdminHoldingTheGrant(): void
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => $type === 'custom_options'
                && $value === BackendUserGrant::TASKS_USE->permissionValue(),
        );
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertNull($this->grantGuardResult());
    }

    #[Test]
    public function denyWithoutGrantReturnsForbiddenForAGrantlessNonAdmin(): void
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        $response = $this->grantGuardResult();

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(403, $response->getStatusCode());
        $payload = json_decode((string)$response->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['success']);
        self::assertIsString($payload['error']);
    }

    #[Test]
    public function denyWithoutGrantReturnsForbiddenWhenNoBackendUserIsPresent(): void
    {
        unset($GLOBALS['BE_USER']);

        $response = $this->grantGuardResult();

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Run the HTML grant guard via an anonymous class exposing the private method.
     */
    private function grantGuardHtmlResult(): ?ResponseInterface
    {
        $subject = new class {
            use RequiresBackendAdminTrait;

            public function expose(): ?ResponseInterface
            {
                return $this->denyWithoutGrantHtml(BackendUserGrant::TASKS_USE);
            }
        };

        return $subject->expose();
    }

    #[Test]
    public function denyWithoutGrantHtmlPassesAdminsWithoutAnyGrant(): void
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertNull($this->grantGuardHtmlResult());
    }

    #[Test]
    public function denyWithoutGrantHtmlPassesANonAdminHoldingTheGrant(): void
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => $type === 'custom_options'
                && $value === BackendUserGrant::TASKS_USE->permissionValue(),
        );
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertNull($this->grantGuardHtmlResult());
    }

    #[Test]
    public function denyWithoutGrantHtmlReturnsAnHtmlForbiddenForAGrantlessNonAdmin(): void
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        $response = $this->grantGuardHtmlResult();

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(403, $response->getStatusCode());
        // Module-route actions render into a page, so the guard must answer
        // with an HTML fragment — not the JSON shape of the AJAX guards.
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $body = (string)$response->getBody();
        self::assertStringStartsWith('<div', $body);
        self::assertNull(json_decode($body), 'the body is a page fragment, not a JSON payload');
        self::assertStringContainsStringIgnoringCase('permission', $body);
    }

    #[Test]
    public function denyWithoutGrantHtmlReturnsForbiddenWhenNoBackendUserIsPresent(): void
    {
        unset($GLOBALS['BE_USER']);

        $response = $this->grantGuardHtmlResult();

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }
}
