<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\ModuleChromeTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * What the doc-header chrome says, asserted where it is decided.
 *
 * `ModuleTemplate` is final in TYPO3 v14 and cannot be doubled, so the wiring
 * that hands these values to the view is not reachable from a unit test. The
 * decisions are, and each of the four below was a real state of this extension
 * before the trait existed: a view with no title at all, a title that came back
 * empty because its label did not resolve, a view reached outside a module
 * context, and four tabs of one module that would otherwise produce four
 * bookmarks with the same name.
 */
#[CoversTrait(ModuleChromeTrait::class)]
final class ModuleChromeTraitTest extends TestCase
{
    use ModuleChromeTrait;

    private ?LanguageService $previousLanguageService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $existing = $GLOBALS['LANG'] ?? null;
        $this->previousLanguageService = $existing instanceof LanguageService ? $existing : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousLanguageService instanceof LanguageService) {
            $GLOBALS['LANG'] = $this->previousLanguageService;
        } else {
            unset($GLOBALS['LANG']);
        }

        parent::tearDown();
    }

    #[Test]
    public function theIdentifierAndTheLocalizedTitleComeFromTheRequest(): void
    {
        $this->givenLanguageServiceTranslating(['LLL:mod.title' => 'AI Tasks']);

        self::assertSame(
            ['identifier' => 'nrllm_aitasks', 'title' => 'AI Tasks', 'shortcutName' => 'AI Tasks'],
            $this->chromeFor('nrllm_aitasks', 'LLL:mod.title'),
        );
    }

    /**
     * A context keeps four tabs of one module apart. It has to reach the
     * bookmark as well as the title, or the bookmarks are four entries with one
     * name — which is the state this replaces, not one it may introduce.
     */
    #[Test]
    public function aContextTravelsIntoTheShortcutName(): void
    {
        $this->givenLanguageServiceTranslating(['LLL:mod.title' => 'LLM']);

        self::assertSame(
            'LLM · Governance',
            $this->chromeFor('nrllm_overview', 'LLL:mod.title', 'Governance')['shortcutName'],
        );
    }

    /**
     * `sL()` answers an empty string for a key it cannot resolve. Passing that
     * through would drop the module name from the header silently; the raw key
     * is ugly and reportable, which is the better of the two.
     */
    #[Test]
    public function anUnresolvableLabelKeepsTheRawKeyRatherThanGoingEmpty(): void
    {
        $this->givenLanguageServiceTranslating([]);

        self::assertSame(
            'LLL:mod.unresolvable',
            $this->chromeFor('nrllm_setup', 'LLL:mod.unresolvable')['title'],
        );
    }

    /**
     * Reached outside a module context — an AJAX route, a test harness. A title
     * and a shortcut pointing at nothing are worse than none, so there is
     * nothing to apply.
     */
    #[Test]
    public function withoutAModuleOnTheRequestThereIsNoChromeToApply(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);

        self::assertNull($this->invokeChrome($request, ''));
    }

    /**
     * @return array{identifier: string, title: string, shortcutName: string}
     */
    private function chromeFor(string $identifier, string $title, string $context = ''): array
    {
        $chrome = $this->invokeChrome($this->requestForModule($identifier, $title), $context);
        self::assertIsArray($chrome);

        return $chrome;
    }

    /**
     * @return array{identifier: string, title: string, shortcutName: string}|null
     */
    private function invokeChrome(ServerRequestInterface $request, string $context): ?array
    {
        // The trait's methods are private, which is what keeps them out of the
        // controllers' public surface; the test uses the trait itself, so the
        // method is reached through reflection rather than by widening it.
        $method = new ReflectionMethod($this, 'moduleChromeFor');

        /** @var array{identifier: string, title: string, shortcutName: string}|null $chrome */
        $chrome = $method->invoke($this, $request, $context);

        return $chrome;
    }

    /**
     * @param array<string, string> $translations
     */
    private function givenLanguageServiceTranslating(array $translations): void
    {
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn(string $key): string => $translations[$key] ?? '',
        );

        $GLOBALS['LANG'] = $languageService;
    }

    private function requestForModule(string $identifier, string $title): ServerRequestInterface
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('getIdentifier')->willReturn($identifier);
        $module->method('getTitle')->willReturn($title);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): ?ModuleInterface => $name === 'module' ? $module : null,
        );

        return $request;
    }
}
