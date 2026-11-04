<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\ExceptionHintController;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ExceptionHintService;
use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use Netresearch\NrLlm\Service\Tool\SourcePathGuard;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * The administrators' error list and its hint action (ADR-258), rendered
 * through the real ModuleTemplate stack.
 */
#[CoversClass(ExceptionHintController::class)]
final class ExceptionHintControllerTest extends AbstractFunctionalTestCase
{
    private string $logDir;

    private int $calls = 0;

    private string $sent = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDir = $this->instancePath . '/hint-log';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0o777, true);
        }

        file_put_contents(
            $this->logDir . '/typo3_x.log',
            'Tue, 03 Nov 2026 10:00:00 +0000 [CRITICAL] request="r" component="TYPO3.CMS.Core": Oops for mysql://u:hunter2@db/x - '
            . json_encode(['exception' => "RuntimeException: Oops in /var/www/packages/a/A.php:5\nStack trace:\n#0 /var/www/packages/a/B.php(9): A->run()\n"]) . "\n",
        );
        $this->calls = 0;
        $this->sent  = '';
    }

    /**
     * The key the list posts for the entry at `$position`, read as the controller reads the log.
     */
    private function keyAt(int $position): string
    {
        return ExceptionHintController::keyOf((new LogExceptionReader($this->logDir))->read(10)[$position]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theListShowsTheEntryAndAsksNoModel(): void
    {
        $body = (string) $this->controllerFor(1, 'GET', 'list')->listAction()->getBody();

        self::assertStringContainsString('RuntimeException', $body);
        self::assertStringContainsString('/var/www/packages/a/A.php:5', $body);
        self::assertStringNotContainsString('hunter2', $body);
        self::assertStringContainsString('name="entry" value="' . $this->keyAt(0) . '"', $body);
        self::assertSame(0, $this->calls);
    }

    #[Test]
    public function aPostedHintIsShownAsAGuessWhenItPointsAtNoProjectFrame(): void
    {
        $body = (string) $this->controllerFor(1, 'POST', 'hint')->hintAction($this->keyAt(0))->getBody();

        self::assertSame(1, $this->calls);
        self::assertStringContainsString('The database is down.', $body);
        self::assertStringContainsString('Check the host.', $body);
        self::assertStringContainsString('a guess', $body);
    }

    #[Test]
    public function aHintByGetAsksNoModel(): void
    {
        $body = (string) $this->controllerFor(1, 'GET', 'hint')->hintAction($this->keyAt(0))->getBody();

        self::assertSame(0, $this->calls);
        self::assertStringNotContainsString('The database is down.', $body);
    }

    /**
     * An error logged between the list and the click does not take the chosen one's place.
     */
    #[Test]
    public function theHintIsForTheChosenEntryNotTheOneNowAtItsPosition(): void
    {
        $chosen = $this->keyAt(0);
        file_put_contents(
            $this->logDir . '/typo3_x.log',
            'Tue, 03 Nov 2026 11:00:00 +0000 [ERROR] request="r2" component="Other": Newer failure - '
            . json_encode(['exception' => "LogicException: Newer in /var/www/packages/b/C.php:7\n"]) . "\n",
            FILE_APPEND,
        );

        $this->controllerFor(1, 'POST', 'hint')->hintAction($chosen);

        self::assertSame(1, $this->calls);
        self::assertStringContainsString('RuntimeException', $this->sent);
        self::assertStringNotContainsString('LogicException', $this->sent);
    }

    #[Test]
    public function anUnknownKeyAsksNoModel(): void
    {
        $this->controllerFor(1, 'POST', 'hint')->hintAction('0');

        self::assertSame(0, $this->calls);
    }

    #[Test]
    public function aNonAdministratorIsRefused(): void
    {
        $response = $this->controllerFor(2, 'POST', 'hint')->hintAction($this->keyAt(0));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->calls);
    }

    private function controllerFor(int $user, string $method, string $action): ExceptionHintController
    {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser($user);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $llm = self::createStub(LlmServiceManagerInterface::class);
        $llm->method('chatWithConfiguration')->willReturnCallback(function (array $messages): CompletionResponse {
            ++$this->calls;
            $this->sent = $messages[1] instanceof ChatMessage ? $messages[1]->content : '';

            return new CompletionResponse('{"cause": "The database is down.", "next_step": "Check the host.", "frame": null}', 'm', new UsageStatistics(1, 1, 2));
        });

        $provider = new Provider();
        $provider->setTrustZone(TrustZone::LOCAL->value);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('local');
        $configuration->setName('Local');
        $configuration->setLlmModel($model);

        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findDefault')->willReturn($configuration);

        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);
        $reader     = new LogExceptionReader($this->logDir);
        $controller = new ExceptionHintController(
            $moduleTemplateFactory,
            $reader,
            new ExceptionHintService($llm, new TrustZoneResolver(), new SourcePathGuard('/var/www')),
            $configurations,
        );

        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerName('Backend\ExceptionHint');
        $parameters->setControllerActionName($action);
        $parameters->setControllerExtensionName('NrLlm');
        $parameters->setPluginName('nrllm_exceptions');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', $method))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/exceptions', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $parameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        (new ReflectionClass($controller))->getProperty('request')->setValue($controller, new ExtbaseRequest($serverRequest));

        return $controller;
    }
}
