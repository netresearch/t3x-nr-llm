<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * The admin execute form (Templates/Backend/Task/Execute.html), rendered.
 *
 * `executeAction` and `refreshInputAction` both refuse an inactive task with
 * "Task is not active". The form must say so and must not offer the two
 * controls that call those endpoints; for an active task it offers both.
 * Rendered with an auto-input task, the only kind that has a refresh control.
 */
#[CoversNothing]
final class TaskExecuteFormRenderTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function anInactiveTaskSaysItCannotRunAndOffersNeitherRunNorRefresh(): void
    {
        $body = $this->render($this->syslogTask(false));

        self::assertStringContainsString('This task is inactive.', $body);
        self::assertStringContainsString('it cannot run or refresh its input here either. Activate the task to test it.', $body);
        self::assertStringContainsString('Activate this task to run it here.', $body);
        self::assertStringContainsString('shown below as it was when this page loaded', $body);
        self::assertStringNotContainsString('You can still test it here', $body);
        self::assertStringNotContainsString('id="executeBtn"', $body);
        self::assertStringNotContainsString('id="refreshInputBtn"', $body);
        self::assertStringNotContainsString('refresh if it looks stale', $body);
        self::assertStringNotContainsString('Click &quot;Execute Task&quot; to run this task', $body);
    }

    #[Test]
    public function anActiveTaskOffersRunAndRefresh(): void
    {
        $body = $this->render($this->syslogTask(true));

        self::assertStringContainsString('id="executeBtn"', $body);
        self::assertStringContainsString('id="refreshInputBtn"', $body);
        self::assertStringContainsString('refresh if it looks stale', $body);
        self::assertStringContainsString('Click &quot;Execute Task&quot; to run this task', $body);
        self::assertStringNotContainsString('This task is inactive.', $body);
        self::assertStringNotContainsString('Activate this task to run it here.', $body);
    }

    private function syslogTask(bool $active): Task
    {
        $task = new Task();
        $task->setName('Log review');
        $task->setPromptTemplate('Review: {{input}}');
        $task->setInputType('syslog');
        $task->setOutputFormat('markdown');
        $task->setIsActive($active);

        return $task;
    }

    private function extbaseRequest(): ExtbaseRequest
    {
        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerName('Backend\\TaskExecution');
        $parameters->setControllerActionName('executeForm');
        $parameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('extbase', $parameters);
        $serverRequest = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));

        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }

    private function render(Task $task): string
    {
        $view = $this->getService(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: ['EXT:nr_llm/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:nr_llm/Resources/Private/Partials/'],
            layoutRootPaths: [
                'EXT:nr_llm/Resources/Private/Layouts/',
                'EXT:backend/Resources/Private/Layouts/',
            ],
            templatePathAndFilename: GeneralUtility::getFileAbsFileName(
                'EXT:nr_llm/Resources/Private/Templates/Backend/Task/Execute.html',
            ),
            request: $this->extbaseRequest(),
        ));

        $view->assignMultiple([
            'task'                 => $task,
            'inputData'            => '[2026-11-09 10:00:00] [ERROR] Login failed',
            'requiresManualInput'  => false,
            'effectiveConfig'      => null,
            'isUsingDefaultConfig' => false,
            'taskEditUrl'          => '/typo3/record/edit',
            'configEditUrl'        => '',
            'modelEditUrl'         => '',
            'providerEditUrl'      => '',
        ]);

        return $view->render();
    }
}
