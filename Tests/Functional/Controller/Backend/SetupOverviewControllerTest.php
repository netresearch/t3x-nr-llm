<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\SetupOverviewController;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Routing\Route as SymfonyRoute;
use TYPO3\CMS\Backend\Middleware\BackendModuleValidator;
use TYPO3\CMS\Backend\Module\ExtbaseModule;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Extbase\Core\Bootstrap;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

use function array_map;
use function array_values;
use function is_array;
use function sort;
use function strpos;
use function var_export;

/**
 * The "Setup" section: the wizard comes first, and an installation without a
 * provider is sent there.
 *
 * The order is asserted on the module registry the backend builds, not on the
 * source of Modules.php: the menu, the v13 fallback route and the cards on the
 * overview all read that registry, and the registry is where TYPO3's own
 * sorting (`position`, then registration order) has already been applied.
 */
#[CoversClass(SetupOverviewController::class)]
final class SetupOverviewControllerTest extends AbstractFunctionalTestCase
{
    private const WIZARD_PATH = '/module/nrllm/wizard';

    private const NOTICE_TITLE = 'No LLM provider is set up yet';

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function theSetupWizardIsTheFirstEntryAndUseCasePacksTheLast(): void
    {
        self::assertSame(
            [
                'nrllm_wizard',
                'nrllm_providers',
                'nrllm_models',
                'nrllm_configurations',
                'nrllm_usecase',
            ],
            $this->submoduleIdentifiers(),
        );
    }

    /**
     * The overview only matters if the module route reaches it. Before the
     * controller runs, the core's BackendModuleValidator decides whether a
     * container is shown or replaced by one of its submodules, so the route is
     * sent through that middleware here rather than straight to the controller.
     *
     * v14 skips the redirect for a container with `showSubmoduleOverview`; v13
     * has no such switch and always opens a submodule — on a first visit the
     * first one, which is the setup wizard.
     */
    #[Test]
    public function openingSetupReachesTheOverviewOnV14AndTheWizardOnV13(): void
    {
        $this->assertSetupRoutesTo('nrllm_wizard');
    }

    /**
     * The validator remembers the last opened submodule of a container and
     * reopens it. On v14 that must not replace the overview; on v13 it does.
     */
    #[Test]
    public function aPreviouslyOpenedSubmoduleDoesNotReplaceTheOverviewOnV14(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        $user->pushModuleData('nrllm_setup', ['action' => 'nrllm_providers'], true);

        $this->assertSetupRoutesTo('nrllm_providers');
    }

    /**
     * Sends the nrllm_setup route through BackendModuleValidator and asserts
     * where it ends up: on v14 at this extension's overview action, on v13 at
     * the given submodule.
     */
    private function assertSetupRoutesTo(string $submoduleOnV13): void
    {
        if (!isset($GLOBALS['BE_USER'])) {
            $this->importFixture('BeUsers.csv');
            $this->setUpBackendUser(1);
        }
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);

        // The router hands out a Symfony route, which is what the validator
        // reads its options from.
        $route = $this->getService(Router::class)->getRoute('nrllm_setup');
        self::assertInstanceOf(SymfonyRoute::class, $route);

        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/nrllm/setup', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            // The validator rewrites the options of the route it is given.
            ->withAttribute('route', clone $route);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response();
            }
        };

        $this->getService(BackendModuleValidator::class)->process($request, $handler);

        $module = $handler->request?->getAttribute('module');
        self::assertInstanceOf(ModuleInterface::class, $module);

        if ((new Typo3Version())->getMajorVersion() < 14) {
            self::assertSame($submoduleOnV13, $module->getIdentifier());
        } else {
            self::assertSame('nrllm_setup', $module->getIdentifier());
            // And the route dispatches this extension's action, not the core
            // overview, which has no notice.
            $resolvedRoute = $handler->request?->getAttribute('route');
            self::assertInstanceOf(SymfonyRoute::class, $resolvedRoute);
            self::assertSame(Bootstrap::class . '::handleBackendRequest', $resolvedRoute->getOption('target'));
            self::assertSame('index', $resolvedRoute->getOption('action'));
            self::assertSame(SetupOverviewController::class, $this->controllerClassOf($module, $resolvedRoute->getOption('controller')));
        }
    }

    /**
     * The route names the controller by its Extbase alias; the module knows
     * which class that alias stands for.
     */
    private function controllerClassOf(ModuleInterface $module, mixed $alias): string
    {
        self::assertInstanceOf(ExtbaseModule::class, $module);
        foreach ($module->getControllerActions() as $className => $configuration) {
            if (is_array($configuration) && ($configuration['alias'] ?? null) === $alias) {
                return (string) $className;
            }
        }

        self::fail('No controller registered under the alias ' . var_export($alias, true));
    }

    #[Test]
    public function withoutAProviderTheOverviewOpensWithANoticeLinkingTheWizard(): void
    {
        $body = $this->renderOverview();

        $notice = strpos($body, self::NOTICE_TITLE);
        self::assertNotFalse($notice, 'The no-provider notice is missing.');

        // The notice is the first thing on the page, above every card, and its
        // button is a real link to the wizard: reachable with the keyboard and
        // announced as a link without any script.
        $firstCard = strpos($body, 'class="card card-size-small"');
        self::assertNotFalse($firstCard, 'No submodule card rendered.');
        self::assertLessThan($firstCard, $notice);

        $button = strpos($body, 'class="btn btn-primary"', $notice);
        self::assertNotFalse($button, 'The notice carries no button.');
        self::assertLessThan($firstCard, $button);
        $href = strpos($body, self::WIZARD_PATH, $notice);
        self::assertNotFalse($href);
        self::assertLessThan($firstCard, $href, 'The notice button does not link the wizard.');
    }

    #[Test]
    public function withAProviderTheNoticeIsAbsentAndTheCardsRemain(): void
    {
        $this->importFixture('Providers.csv');

        $body = $this->renderOverview();

        self::assertStringNotContainsString(self::NOTICE_TITLE, $body);
        self::assertStringContainsString('class="card card-size-small"', $body);
    }

    #[Test]
    public function theCardsFollowTheMenuOrder(): void
    {
        $this->importFixture('Providers.csv');

        $body = $this->renderOverview();

        $firstCard = strpos($body, 'class="card card-size-small"');
        self::assertNotFalse($firstCard, 'No submodule card rendered.');

        $positions = array_map(
            static fn (string $path): int|false => strpos($body, $path . '?', $firstCard),
            [
                '/module/nrllm/wizard',
                '/module/nrllm/providers',
                '/module/nrllm/models',
                '/module/nrllm/configurations',
                '/module/nrllm/use-case',
            ],
        );

        foreach ($positions as $index => $position) {
            self::assertNotFalse($position, 'Card ' . $index . ' has no link.');
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'The cards are not in the menu order.');
    }

    /**
     * @return list<string>
     */
    private function submoduleIdentifiers(): array
    {
        $setup = $this->setupModule();

        return array_values(array_map(
            static fn (ModuleInterface $module): string => $module->getIdentifier(),
            $setup->getSubModules(),
        ));
    }

    private function setupModule(): ModuleInterface
    {
        $setup = $this->getService(ModuleProvider::class)->getModule('nrllm_setup');
        self::assertInstanceOf(ModuleInterface::class, $setup);

        return $setup;
    }

    /**
     * Dispatches the controller action with the request the module route hands
     * it: the resolved module travels on the request, which is where the
     * controller reads its submodules from. That the route reaches this action
     * at all is the middleware test above.
     */
    private function renderOverview(): string
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerName('Backend\\SetupOverview');
        $parameters->setControllerActionName('index');
        $parameters->setControllerExtensionName('NrLlm');

        // BackendViewFactory resolves its template paths from the route's
        // packageName, so a module action cannot be dispatched without one.
        $route = new Route('/module/nrllm/setup', ['packageName' => 'netresearch/nr-llm']);

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withAttribute('module', $this->setupModule())
            ->withAttribute('extbase', $parameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;
        $GLOBALS['LANG']          = $this->getService(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);

        $controller = $this->getService(SetupOverviewController::class);

        return (string) $controller->processRequest(new ExtbaseRequest($serverRequest))->getBody();
    }
}
