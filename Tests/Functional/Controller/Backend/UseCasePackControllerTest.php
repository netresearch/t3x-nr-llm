<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\UseCasePackController;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Dispatch the real container-built controller, installer and persistence.
 *
 * The existing render tests remain responsible for detailed template branches.
 * This suite tests the documented confirmation and installation outcomes. It
 * does not exercise the backend middleware's authorization or CSRF handling.
 */
#[CoversClass(UseCasePackController::class)]
final class UseCasePackControllerTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
        $this->messages();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function theActualEntryOffersAllSixUseCasesWithoutWriting(): void
    {
        $before = $this->extensionRows();

        $response = $this->dispatch('index');
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        foreach ([
            'Editorial assistance',
            'Translation',
            'Metadata',
            'Media accessibility',
            'Agent workflows',
            'Developer integration',
        ] as $label) {
            self::assertStringContainsString($label, $body);
        }

        self::assertStringContainsString('Editorial Starter', $body);
        self::assertSame(5, substr_count($body, 'No pack yet'));
        self::assertStringContainsString('/module/nrllm/wizard', $body);
        self::assertSame($before, $this->extensionRows());
    }

    #[Test]
    public function theActualPlanDescribesPendingRecordsWithoutInstallingThem(): void
    {
        $this->satisfiableModel();
        $before = $this->extensionRows();
        $governance = $this->governanceConfiguration();

        $response = $this->dispatch('show', 'editorial-starter');
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Would be created', $body);
        self::assertStringContainsString('nr_llm.editorial_starter', $body);
        self::assertStringContainsString('Create the missing records', $body);
        self::assertStringContainsString('profile=controlled-cloud', $body);
        self::assertStringContainsString('tone_of_voice', $body);
        self::assertStringContainsString('audience', $body);
        self::assertSame($before, $this->extensionRows());
        self::assertSame($governance, $this->governanceConfiguration());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unknownPackActions(): array
    {
        return [
            'unknown plan' => ['show', 'GET'],
            'unknown install' => ['install', 'POST'],
        ];
    }

    #[Test]
    #[DataProvider('unknownPackActions')]
    public function anUnknownPackRedirectsWithoutWritingOrReportingSuccess(
        string $action,
        string $method,
    ): void {
        $this->satisfiableModel();
        $before = $this->extensionRows();

        $response = $this->dispatch($action, 'not-a-pack', $method);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($before, $this->extensionRows());
        self::assertSame([], $this->messages());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unconfirmedMethods(): array
    {
        return ['bookmark GET' => ['GET'], 'prefetch HEAD' => ['HEAD']];
    }

    #[Test]
    #[DataProvider('unconfirmedMethods')]
    public function anUnconfirmedInstallCannotCreateRecordsEvenWithASatisfiableModel(
        string $method,
    ): void {
        // A missing model would let a removed POST guard pass this test: the
        // installer would refuse independently. This setup must be installable.
        $this->satisfiableModel();
        $before = $this->extensionRows();

        $response = $this->dispatch('install', 'editorial-starter', $method);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($before, $this->extensionRows());
        self::assertSame([], $this->messages());
    }

    #[Test]
    public function aConfirmedInstallCreatesTheDeclaredRecordsAndASecondConfirmationIsANoOp(): void
    {
        $this->satisfiableModel();
        $before = $this->extensionRows();
        $governance = $this->governanceConfiguration();

        $response = $this->dispatch('install', 'editorial-starter', 'POST');

        self::assertInstanceOf(RedirectResponse::class, $response);
        $after = $this->extensionRows();
        self::assertCount(1, $after['tx_nrllm_configuration']);
        self::assertCount(10, $after['tx_nrllm_task']);
        self::assertCount(2, $after['tx_nrllm_promptsnippet']);
        $configuration = $after['tx_nrllm_configuration'][0];
        self::assertSame(
            'nr_llm.editorial_starter',
            $configuration['identifier'],
        );
        self::assertSame(
            'tone_of_voice,audience',
            $configuration['snippet_tags'],
        );
        foreach ($after['tx_nrllm_task'] as $task) {
            self::assertSame(
                (int)$configuration['uid'],
                (int)$task['configuration_uid'],
            );
        }

        foreach ($before as $table => $rows) {
            if (!in_array(
                $table,
                [
                    'tx_nrllm_configuration',
                    'tx_nrllm_task',
                    'tx_nrllm_promptsnippet',
                ],
                true,
            )) {
                self::assertSame(
                    $rows,
                    $after[$table],
                    $table . ' must remain unchanged',
                );
            }
        }

        self::assertSame($governance, $this->governanceConfiguration());
        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertSame(
            ContextualFeedbackSeverity::OK,
            $messages[0]->getSeverity(),
        );
        self::assertStringContainsString(
            '13 record(s) created, 0 already present',
            $messages[0]->getMessage(),
        );
        self::assertStringContainsString(
            'Snippet tags added to the configuration: tone_of_voice, audience.',
            $messages[0]->getMessage(),
        );

        $second = $this->dispatch('install', 'editorial-starter', 'POST');

        self::assertInstanceOf(RedirectResponse::class, $second);
        self::assertSame($after, $this->extensionRows());
        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertSame(
            ContextualFeedbackSeverity::OK,
            $messages[0]->getSeverity(),
        );
        self::assertStringContainsString(
            '0 record(s) created, 13 already present',
            $messages[0]->getMessage(),
        );
        self::assertStringNotContainsString(
            'Snippet tags added',
            $messages[0]->getMessage(),
        );
    }

    #[Test]
    public function anUnsatisfiableConfirmedInstallShowsAWarningAndWritesNothing(): void
    {
        $before = $this->extensionRows();

        $response = $this->dispatch('install', 'editorial-starter', 'POST');

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($before, $this->extensionRows());
        $messages = $this->messages();
        self::assertCount(1, $messages);
        self::assertSame(
            ContextualFeedbackSeverity::WARNING,
            $messages[0]->getSeverity(),
        );
        self::assertStringContainsString(
            'no active model',
            $messages[0]->getMessage(),
        );
    }

    private function satisfiableModel(): void
    {
        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function extensionRows(): array
    {
        $connection = $this->getConnection();
        $snapshot = [];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            if (!str_starts_with($table, 'tx_nrllm_')) {
                continue;
            }

            $rows = $connection->select(['*'], $table)->fetchAllAssociative();
            sort($rows);
            $snapshot[$table] = $rows;
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @return list<FlashMessage>
     */
    private function messages(): array
    {
        return array_values(
            $this
                ->getService(FlashMessageService::class)
                ->getMessageQueueByIdentifier()
                ->getAllMessagesAndFlush(),
        );
    }

    private function dispatch(
        string $action,
        string $pack = '',
        string $method = 'GET',
    ): ResponseInterface {
        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerName('Backend\UseCasePack');
        $parameters->setControllerActionName($action);
        $parameters->setControllerExtensionName('NrLlm');
        if ($action !== 'index') {
            $parameters->setArgument('pack', $pack);
        }

        $request = (new ServerRequest('https://typo3-testing.local/typo3/', $method))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute(
                'route',
                new Route(
                    '/module/nrllm/usecase',
                    ['packageName' => 'netresearch/nr-llm'],
                ),
            )
            ->withAttribute('extbase', $parameters);
        $request = $request->withAttribute(
            'normalizedParams',
            NormalizedParams::createFromRequest($request),
        );
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $GLOBALS['LANG'] = $this
            ->getService(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);

        // Extbase's ConfigurationManager must see this ambient request before
        // the container constructs the controller, just as in the real module.
        return $this
            ->getService(UseCasePackController::class)
            ->processRequest(new ExtbaseRequest($request));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function governanceConfiguration(): array
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'];
        self::assertIsArray($configuration);
        $extensions = $configuration['EXTENSIONS'] ?? [];
        self::assertIsArray($extensions);
        $governance = $extensions['nr_llm'] ?? [];
        self::assertIsArray($governance);

        return $governance;
    }
}
