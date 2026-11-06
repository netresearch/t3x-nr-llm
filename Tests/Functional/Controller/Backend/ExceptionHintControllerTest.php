<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\ExceptionHintController;
use Netresearch\NrLlm\Domain\DTO\FallbackChain;
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
use ReflectionMethod;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

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

    private string $system = '';

    private string $asked = '';

    /** @var array<string, LlmConfiguration> by identifier */
    private array $configurations = [];

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
        $this->calls          = 0;
        $this->sent           = '';
        $this->system         = '';
        $this->asked          = '';
        $this->configurations = [];
        $this->configuration('local', 'Local', TrustZone::LOCAL, default: true);
    }

    /**
     * @param list<string> $fallbacks
     */
    private function configuration(string $identifier, string $name, TrustZone $zone, bool $active = true, bool $default = false, array $fallbacks = []): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZone($zone->value);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($identifier);
        $configuration->setName($name);
        $configuration->setLlmModel($model);
        $configuration->setIsActive($active);
        $configuration->setIsDefault($default);
        $configuration->setFallbackChainDTO(new FallbackChain($fallbacks));

        return $this->configurations[$identifier] = $configuration;
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
    public function anUnknownKeyAsksNoModelAndSaysSo(): void
    {
        $body = (string) $this->controllerFor(1, 'POST', 'hint')->hintAction('0')->getBody();

        self::assertSame(0, $this->calls);
        self::assertStringContainsString('no longer among the ten newest', $body);
        self::assertStringNotContainsString('no longer among the ten newest', (string) $this->controllerFor(1, 'GET', 'list', false)->listAction()->getBody());
    }

    #[Test]
    public function aDeactivatedDefaultConfigurationIsNotAsked(): void
    {
        $this->configuration('local', 'Local', TrustZone::LOCAL, active: false, default: true);
        $body = (string) $this->controllerFor(1, 'POST', 'hint')->hintAction($this->keyAt(0))->getBody();

        self::assertSame(0, $this->calls);
        self::assertStringContainsString('deactivated', $body);
        self::assertStringContainsString('choose an active configuration above', $body);
        self::assertMatchesRegularExpression('#<a href="[^"]*/module/nrllm/configurations[^"]*">Configurations</a>#', $body);
    }

    /**
     * Every action the controller offers is routed: an action missing from
     * the module registration renders its form with an empty target.
     */
    #[Test]
    public function everyActionIsRegisteredForTheModule(): void
    {
        $modules = require __DIR__ . '/../../../../Configuration/Backend/Modules.php';
        self::assertIsArray($modules);
        $module = $modules[ExceptionHintController::MODULE] ?? null;
        self::assertIsArray($module);
        self::assertIsArray($module['controllerActions'] ?? null);
        $registered = $module['controllerActions'][ExceptionHintController::class] ?? null;
        self::assertIsArray($registered);

        $actions = [];
        foreach ((new ReflectionClass(ExceptionHintController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_ends_with($method->getName(), 'Action') && $method->getDeclaringClass()->getName() === ExceptionHintController::class) {
                $actions[] = substr($method->getName(), 0, -6);
            }
        }

        sort($actions);
        sort($registered);
        self::assertSame($actions, $registered);
    }

    #[Test]
    public function aNonAdministratorIsRefused(): void
    {
        $response = $this->controllerFor(2, 'POST', 'hint')->hintAction($this->keyAt(0));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->calls);
    }

    /**
     * A German administrator reads the refusal in German, with what to do and
     * where: the screenshot's "Kein Hinweis: The configuration …" case.
     */
    #[Test]
    public function aRefusalIsWordedInTheBackendLanguageAndSaysWhatToDo(): void
    {
        $this->configuration('local', 'Content assistant', TrustZone::EXTERNAL_GLOBAL, default: true);

        $body = (string) $this->controllerFor(1, 'POST', 'hint', language: 'de')->hintAction($this->keyAt(0))->getBody();

        self::assertSame(0, $this->calls);
        self::assertStringContainsString('Kein Hinweis:', $body);
        self::assertStringContainsString('darf keine Systemdiagnosen erhalten', $body);
        self::assertStringContainsString('lokal oder privat gehostet', $body);
        self::assertStringNotContainsString('trust zone admits no system diagnostics', $body);
        self::assertMatchesRegularExpression('#<a href="[^"]*/module/nrllm/configurations[^"]*">Konfigurationen</a>#', $body);
        self::assertMatchesRegularExpression('#<a href="[^"]*/module/nrllm/providers[^"]*">Provider</a>#', $body);
    }

    #[Test]
    public function theModelWritesTheHintInTheBackendLanguage(): void
    {
        $this->controllerFor(1, 'POST', 'hint', language: 'de')->hintAction($this->keyAt(0));

        self::assertSame(1, $this->calls);
        self::assertStringContainsString('in German', $this->system);
    }

    #[Test]
    public function theChosenConfigurationIsAsked(): void
    {
        $this->configuration('second', 'Second', TrustZone::PRIVATE_HOSTED);

        $this->controllerFor(1, 'POST', 'hint', stored: 'second')->hintAction($this->keyAt(0));

        self::assertSame(1, $this->calls);
        self::assertSame('second', $this->asked);
    }

    /**
     * The list offers every active configuration, marked by what a hint may
     * send it; a local configuration with an external fallback is external.
     */
    #[Test]
    public function theListMarksEachConfigurationByWhatItMayReceive(): void
    {
        $this->configuration('private', 'Private', TrustZone::PRIVATE_HOSTED);
        $this->configuration('cloud', 'Cloud', TrustZone::EXTERNAL_EU);
        $this->configuration('mixed', 'Local with cloud fallback', TrustZone::LOCAL, fallbacks: ['cloud']);
        $this->configuration('chained', 'Local with private fallback', TrustZone::LOCAL, fallbacks: ['private']);
        $this->configuration('off', 'Switched off', TrustZone::LOCAL, active: false);

        $body = (string) $this->controllerFor(1, 'GET', 'list')->listAction()->getBody();

        self::assertMatchesRegularExpression('#<option value="local"[^>]*>Local – \(allowed: class, stack and message\)</option>#', $body);
        self::assertMatchesRegularExpression('#<option value="private"[^>]*>Private – \(allowed without the message\)</option>#', $body);
        self::assertMatchesRegularExpression('#<option value="cloud"[^>]*>Cloud – \(not allowed\)</option>#', $body);
        self::assertMatchesRegularExpression('#<option value="mixed"[^>]*>Local with cloud fallback – \(not allowed\)</option>#', $body);
        self::assertMatchesRegularExpression('#<option value="chained"[^>]*>Local with private fallback – \(allowed without the message\)</option>#', $body);
        self::assertStringNotContainsString('value="off"', $body);
        self::assertMatchesRegularExpression('#<option value="" selected>The default configuration \(Local\)</option>#', $body);
        self::assertSame(0, $this->calls);
    }

    #[Test]
    public function aLocalConfigurationWithAnExternalFallbackIsNotAsked(): void
    {
        $this->configuration('cloud', 'Cloud', TrustZone::EXTERNAL_GLOBAL);
        $this->configuration('mixed', 'Mixed', TrustZone::LOCAL, fallbacks: ['cloud']);

        $body = (string) $this->controllerFor(1, 'POST', 'hint', stored: 'mixed')->hintAction($this->keyAt(0))->getBody();

        self::assertSame(0, $this->calls);
        self::assertStringContainsString('may not receive system diagnostics', $body);
    }

    /**
     * A stored choice that names a deleted or deactivated configuration falls
     * back to the default one, and the page says so.
     */
    #[Test]
    public function aStaleChoiceFallsBackToTheDefaultAndSaysSo(): void
    {
        $this->configuration('gone-off', 'Gone off', TrustZone::LOCAL, active: false);

        $deleted = (string) $this->controllerFor(1, 'GET', 'list', stored: 'deleted')->listAction()->getBody();
        self::assertStringContainsString('The configuration you chose is gone or deactivated', $deleted);

        $this->controllerFor(1, 'POST', 'hint', false, stored: 'gone-off')->hintAction($this->keyAt(0));
        self::assertSame('local', $this->asked);
    }

    #[Test]
    public function aPostedChoiceIsKeptForTheUser(): void
    {
        $this->configuration('second', 'Second', TrustZone::LOCAL);

        $body = (string) $this->controllerFor(1, 'POST', 'choose')->chooseAction('second')->getBody();

        self::assertSame(['configuration' => 'second'], $GLOBALS['BE_USER']->getModuleData(ExceptionHintController::MODULE));
        self::assertMatchesRegularExpression('#<option value="second" selected>#', $body);
        self::assertSame(0, $this->calls);
    }

    /**
     * A link cannot change the choice, an inactive or unknown configuration is
     * not kept, and an editor cannot choose at all.
     */
    #[Test]
    public function onlyAnAdministratorsPostOfAnActiveConfigurationIsKept(): void
    {
        $this->configuration('off', 'Off', TrustZone::LOCAL, active: false);

        $this->controllerFor(1, 'GET', 'choose')->chooseAction('local');
        self::assertNull($GLOBALS['BE_USER']->getModuleData(ExceptionHintController::MODULE));

        $body = (string) $this->controllerFor(1, 'POST', 'choose', false)->chooseAction('off')->getBody();
        self::assertNull($GLOBALS['BE_USER']->getModuleData(ExceptionHintController::MODULE));
        self::assertStringContainsString('That configuration is not active', $body);

        $this->controllerFor(1, 'POST', 'choose', false)->chooseAction('unknown');
        self::assertNull($GLOBALS['BE_USER']->getModuleData(ExceptionHintController::MODULE));

        $response = $this->controllerFor(2, 'POST', 'choose', false)->chooseAction('local');
        self::assertSame(403, $response->getStatusCode());
        self::assertNull($GLOBALS['BE_USER']->getModuleData(ExceptionHintController::MODULE));
    }

    private function controllerFor(int $user, string $method, string $action, bool $import = true, string $language = '', ?string $stored = null): ExceptionHintController
    {
        if ($import) {
            $this->importFixture('BeUsers.csv');
        }

        $backendUser = $this->setUpBackendUser($user);
        if ($language !== '') {
            $backendUser->user['lang'] = $language;
            $backendUser->uc['lang']   = $language;
        }

        if ($stored !== null) {
            $backendUser->pushModuleData(ExceptionHintController::MODULE, [ExceptionHintController::CHOICE => $stored]);
        }

        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $llm = self::createStub(LlmServiceManagerInterface::class);
        $llm->method('chatWithConfiguration')->willReturnCallback(function (array $messages, LlmConfiguration $configuration): CompletionResponse {
            ++$this->calls;
            $this->system = $messages[0] instanceof ChatMessage ? $messages[0]->content : '';
            $this->sent   = $messages[1] instanceof ChatMessage ? $messages[1]->content : '';
            $this->asked  = $configuration->getIdentifier();

            return new CompletionResponse('{"cause": "The database is down.", "next_step": "Check the host.", "frame": null}', 'm', new UsageStatistics(1, 1, 2));
        });

        $active = array_values(array_filter($this->configurations, static fn (LlmConfiguration $c): bool => $c->isActive()));
        $result = self::createStub(QueryResultInterface::class);
        $result->method('toArray')->willReturn($active);

        $default        = array_values(array_filter($this->configurations, static fn (LlmConfiguration $c): bool => $c->isDefault()))[0] ?? null;
        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findDefault')->willReturn($default);
        $configurations->method('findActive')->willReturn($result);
        $configurations->method('findOneByIdentifier')->willReturnCallback(fn (string $identifier): ?LlmConfiguration => $this->configurations[$identifier] ?? null);

        $moduleTemplateFactory = $this->get(ModuleTemplateFactory::class);
        self::assertInstanceOf(ModuleTemplateFactory::class, $moduleTemplateFactory);
        $backendUriBuilder = $this->get(BackendUriBuilder::class);
        self::assertInstanceOf(BackendUriBuilder::class, $backendUriBuilder);
        $reader     = new LogExceptionReader($this->logDir);
        $controller = new ExceptionHintController(
            $moduleTemplateFactory,
            $reader,
            new ExceptionHintService($llm, new TrustZoneResolver($configurations), new SourcePathGuard('/var/www')),
            $configurations,
            $backendUriBuilder,
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
