<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\SkillSourceController;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

#[CoversClass(SkillSourceController::class)]
final class SkillSourceControllerTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function toggleSkillFlipsEnabledAndReturnsJson(): void
    {
        $this->importFixture('Skills.csv'); // uid 1 is enabled=1
        // Resolving an Extbase ActionController from the container triggers
        // injectConfigurationManager(), which reads settings from the current
        // request. Provide a backend request so the ConfigurationManager is
        // initialised properly.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
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
}
