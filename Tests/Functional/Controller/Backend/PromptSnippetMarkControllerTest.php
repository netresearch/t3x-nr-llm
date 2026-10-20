<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\PromptSnippetMarkController;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Service\Prompt\SnippetCallerMark;
use Netresearch\NrLlm\Service\Prompt\SnippetCallerMarkService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * Marking a snippet for a caller's system message (ADR-214 item 8) changes
 * state only on a POST of the review page's form through the action's own
 * route, stores the digest of the block as it is stored now, and refuses a
 * digest the form did not render from the current block.
 */
#[CoversClass(PromptSnippetMarkController::class)]
#[CoversClass(SnippetCallerMarkService::class)]
final class PromptSnippetMarkControllerTest extends AbstractFunctionalTestCase
{
    private const SNIPPET = 7;

    private const NAME = 'Tone';

    private const TEXT = 'Use the formal register.';

    private string $digest = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $this->pool()->getConnectionForTable('tx_nrllm_promptsnippet')->insert('tx_nrllm_promptsnippet', [
            'uid'        => self::SNIPPET,
            'identifier' => 'tone',
            'name'       => self::NAME,
            'snippet'    => self::TEXT,
            'tags'       => 'style',
            'is_active'  => 1,
        ]);

        $snippet = new PromptSnippet();
        $snippet->setName(self::NAME);
        $snippet->setSnippet(self::TEXT);
        $snippet->setTags('style');

        $this->digest = (new SnippetCallerMark())->digestOf($snippet);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function aPostedMarkStoresTheDigestOfTheCurrentBlock(): void
    {
        $this->dispatch('mark', 'POST', $this->digest);

        self::assertSame($this->digest, $this->storedDigest());
        self::assertStringStartsWith('2:', $this->digest);
    }

    #[Test]
    public function aDigestTheFormDidNotRenderFromTheCurrentBlockIsRefused(): void
    {
        $this->dispatch('mark', 'POST', '2:' . str_repeat('0', 64));

        self::assertSame('', $this->storedDigest());
    }

    /**
     * The digest covers the tags: a form rendered before the snippet was
     * re-tagged posts a digest that no longer matches, and the mark is
     * refused.
     */
    #[Test]
    public function aDigestRenderedBeforeARetaggingIsRefused(): void
    {
        $this->pool()->getConnectionForTable('tx_nrllm_promptsnippet')->update('tx_nrllm_promptsnippet', ['tags' => 'style,legal'], ['uid' => self::SNIPPET]);

        $this->dispatch('mark', 'POST', $this->digest);

        self::assertSame('', $this->storedDigest());
    }

    #[Test]
    public function aBlockWithCharactersThePageCannotShowIsRefused(): void
    {
        $text = "Use the formal register.\u{200B}";
        $this->pool()->getConnectionForTable('tx_nrllm_promptsnippet')->update('tx_nrllm_promptsnippet', ['snippet' => $text], ['uid' => self::SNIPPET]);
        $snippet = new PromptSnippet();
        $snippet->setName(self::NAME);
        $snippet->setSnippet($text);
        $snippet->setTags('style');

        $this->dispatch('mark', 'POST', (new SnippetCallerMark())->digestOf($snippet));

        self::assertSame('', $this->storedDigest());
    }

    #[Test]
    public function aGetOfTheMarkUrlMarksNothing(): void
    {
        $this->dispatch('mark', 'GET', $this->digest);

        self::assertSame('', $this->storedDigest());
    }

    #[Test]
    public function aPostThroughTheReviewRouteDoesNotMark(): void
    {
        $this->dispatch('mark', 'POST', $this->digest, routeAction: 'review');

        self::assertSame('', $this->storedDigest());
    }

    #[Test]
    public function aPostThroughAnotherControllersRouteDoesNotMark(): void
    {
        $this->dispatch('mark', 'POST', $this->digest, routeController: 'Backend\\PromptSnippet');

        self::assertSame('', $this->storedDigest());
    }

    #[Test]
    public function aPostedUnmarkRemovesTheMarkAndAGetDoesNot(): void
    {
        $this->dispatch('mark', 'POST', $this->digest);
        $this->dispatch('unmark', 'GET');
        self::assertSame($this->digest, $this->storedDigest());

        $this->dispatch('unmark', 'POST', routeAction: 'review');
        self::assertSame($this->digest, $this->storedDigest());

        $this->dispatch('unmark', 'POST');
        self::assertSame('', $this->storedDigest());
    }

    #[Test]
    public function aNonAdministratorMarksNothing(): void
    {
        $this->setUpBackendUser(2);

        $this->dispatch('mark', 'POST', $this->digest);

        self::assertSame('', $this->storedDigest());
    }

    private function dispatch(string $action, string $method, string $digest = '', ?string $routeAction = null, ?string $routeController = null): void
    {
        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerName('Backend\\PromptSnippetMark');
        $parameters->setControllerActionName($action);
        $parameters->setControllerExtensionName('NrLlm');
        $parameters->setArgument('snippet', (string) self::SNIPPET);
        if ($action === 'mark') {
            $parameters->setArgument('digest', $digest);
        }

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', $method))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/snippets', ['packageName' => 'netresearch/nr-llm', 'controller' => $routeController ?? 'Backend\\PromptSnippetMark', 'action' => $routeAction ?? $action]))
            ->withAttribute('extbase', $parameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;
        $GLOBALS['LANG']          = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);

        $controller = $this->getService(PromptSnippetMarkController::class);
        $controller->processRequest(new ExtbaseRequest($serverRequest));
    }

    private function storedDigest(): string
    {
        $digest = $this->pool()->getConnectionForTable('tx_nrllm_promptsnippet')
            ->select(['caller_system_digest'], 'tx_nrllm_promptsnippet', ['uid' => self::SNIPPET])
            ->fetchOne();

        return is_string($digest) ? $digest : '';
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }
}
