<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Service\TestPromptResolverService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;

#[CoversClass(TestPromptResolverService::class)]
final class TestPromptResolverServiceTest extends AbstractUnitTestCase
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
     * Regression: a logged-in backend user used to crash the model test with
     * AspectPropertyNotFoundException because the service read the non-existent
     * "user" property from the Context UserAspect. The language must now be
     * read from the backend user record.
     */
    #[Test]
    public function resolveSubstitutesLoggedInBackendUserLanguage(): void
    {
        $service = $this->createServiceForBackendUser('Reply in {lang}.', 'de');

        self::assertSame('Reply in German.', $service->resolve());
    }

    #[Test]
    public function resolveFallsBackToEnglishWhenBackendUserHasNoLanguage(): void
    {
        $service = $this->createServiceForBackendUser('Reply in {lang}.', '');

        self::assertSame('Reply in English.', $service->resolve());
    }

    #[Test]
    public function resolveFallsBackToEnglishOutsideBackendContext(): void
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['testing' => ['testPrompt' => 'Reply in {lang}.']]);

        // A Context without a backend.user aspect (CLI, frontend, tests).
        $service = new TestPromptResolverService($extensionConfiguration, new Context());

        self::assertSame('Reply in English.', $service->resolve());
    }

    private function createServiceForBackendUser(string $prompt, string $language): TestPromptResolverService
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['testing' => ['testPrompt' => $prompt]]);

        $backendUser = new BackendUserAuthentication();
        $backendUser->user = ['uid' => 1, 'lang' => $language];
        $GLOBALS['BE_USER'] = $backendUser;

        $context = new Context();
        $context->setAspect('backend.user', new UserAspect($backendUser));

        return new TestPromptResolverService($extensionConfiguration, $context);
    }
}
