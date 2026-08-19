<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\TaskExecutionController;
use Netresearch\NrLlm\Domain\Enum\CallOutcome;
use Netresearch\NrLlm\Service\Outcome\CallOutcomeRepositoryInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * The endpoint that records an explicit rating (ADR-176).
 */
#[CoversClass(TaskExecutionController::class)]
final class TaskOutcomeEndpointTest extends AbstractFunctionalTestCase
{
    private const CALL = '9f8e7d6c-5b4a-4392-8180-0f1e2d3c4b5a';

    private TaskExecutionController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
        // Extbase's ConfigurationManager captures the ambient request when it
        // is constructed, so it has to exist before the controller is resolved
        // — resolving first and setting it after fails with
        // NoServerRequestGivenException.
        $this->ambientBackendRequest();
        $controller = $this->get(TaskExecutionController::class);
        self::assertInstanceOf(TaskExecutionController::class, $controller);
        $this->controller = $controller;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): ResponseInterface
    {
        return $this->controller->recordOutcomeAction((new ServerRequest())->withParsedBody($body));
    }

    /**
     * @return list<CallOutcome>
     */
    private function stored(): array
    {
        return $this->get(CallOutcomeRepositoryInterface::class)->findByCorrelation(self::CALL);
    }

    #[Test]
    public function anExplicitRatingIsStoredAgainstTheCall(): void
    {
        $response = $this->post(['correlationId' => self::CALL, 'outcome' => 'helpful']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([CallOutcome::HELPFUL], $this->stored());
    }

    #[Test]
    public function anObservedValueIsRefusedOverThisRoute(): void
    {
        // The observed cases are derived from what happened to a record. A
        // client claiming one would be writing a measurement nobody measured,
        // and the canary would read it as if something had been observed.
        $response = $this->post(['correlationId' => self::CALL, 'outcome' => 'accepted_unchanged']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->stored());
    }

    #[Test]
    public function anUnknownRatingIsRefused(): void
    {
        $response = $this->post(['correlationId' => self::CALL, 'outcome' => 'excellent']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->stored());
    }

    #[Test]
    public function aMissingCallReferenceIsRefusedRatherThanStoredEmpty(): void
    {
        // Every such row would key on the empty string and read as the same
        // call, which is worse than losing the rating.
        $response = $this->post(['outcome' => 'helpful']);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function aNonAdminWithoutTheGrantIsRefused(): void
    {
        // uid 2 is a non-admin editor without TASKS_USE.
        $this->setUpBackendUser(2);
        $this->ambientBackendRequest();
        $controller = $this->get(TaskExecutionController::class);
        self::assertInstanceOf(TaskExecutionController::class, $controller);

        $response = $controller->recordOutcomeAction(
            (new ServerRequest())->withParsedBody(['correlationId' => self::CALL, 'outcome' => 'helpful']),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->stored());
    }

    private function ambientBackendRequest(): void
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/', 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute(
            'normalizedParams',
            NormalizedParams::createFromRequest($request),
        );
    }

}
