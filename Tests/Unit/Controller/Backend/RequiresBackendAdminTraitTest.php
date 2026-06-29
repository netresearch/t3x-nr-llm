<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\RequiresBackendAdminTrait;
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
}
