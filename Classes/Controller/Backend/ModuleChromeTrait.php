<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The doc-header furniture every backend view of this extension owes the user:
 * a module title, the module menu, and a shortcut context.
 *
 * It exists because the extension was inconsistent with itself. Of the eighteen
 * backend controllers that render a view, ten set no title at all, nine added no
 * doc-header component, and three did not even build the module menu — while
 * {@see LlmModuleController} did all three on every one of its actions. A run
 * detail reached from the approvals list therefore opened with no title, no
 * menu and no way back, which reads as a broken module rather than as a page.
 *
 * Nothing here is invented: the sequence is the one the core applies in
 * `SubmoduleOverviewController::handleRequest()` — localize the module's own
 * title, set it, build the menu, hand the same identifier and title to the
 * shortcut context.
 *
 * WHY IT READS THE REQUEST INSTEAD OF TAKING ARGUMENTS. The title and the route
 * identifier already exist, once, in `Configuration/Backend/Modules.php`, and
 * the routing puts the resolved module on the request. Passing them in per
 * controller would copy eighteen strings out of that file and let them drift
 * from it silently — a renamed module would keep its old title in the header
 * and its shortcut would point at an identifier that no longer routes. Reading
 * the request cannot drift, and a view rendered outside a module context (a
 * test harness, an AJAX entry point) simply gets no chrome rather than a wrong
 * one.
 */
trait ModuleChromeTrait
{
    /**
     * Give one view its title, module menu and shortcut context.
     *
     * A caller that needs a richer shortcut than this — one carrying action
     * arguments, so the bookmark returns to a specific tab rather than the
     * module's default action — sets its own afterwards and wins by order.
     * {@see LlmModuleController} does that for three of its four tabs; the
     * generic one here is what the remaining views get instead of nothing.
     *
     * @param string $context optional second half of the title, shown after a
     *                        middle dot — the record or subject a detail view is
     *                        about, never a repetition of the module name
     */
    private function applyModuleChrome(
        ModuleTemplate $view,
        ServerRequestInterface $request,
        string $context = '',
    ): void {
        $view->makeDocHeaderModuleMenu();

        $chrome = $this->moduleChromeFor($request, $context);
        if ($chrome === null) {
            return;
        }

        $view->setTitle($chrome['title'], $context);

        // Guarded for the same reason LlmModuleController guards it: the method
        // is not present across the whole supported TYPO3 range, and a shortcut
        // is furniture — worth having where it exists, never worth a fatal.
        //
        // The context travels into the shortcut name as well as the title. Four
        // tabs of one module would otherwise produce four bookmarks with the
        // same name, which is the state this trait replaces rather than one it
        // should introduce.
        if (method_exists($view->getDocHeaderComponent(), 'setShortcutContext')) {
            $view->getDocHeaderComponent()->setShortcutContext(
                $chrome['identifier'],
                $chrome['shortcutName'],
            );
        }
    }

    /**
     * What the chrome should say, decided without touching the view.
     *
     * Separated from the wiring above because `ModuleTemplate` is final in
     * TYPO3 v14 and cannot be doubled, so a test of the whole method would need
     * a real one and everything it depends on. The decisions worth asserting —
     * which title, what the bookmark is called, and whether there is anything to
     * set at all — live here and are a pure function of the request.
     *
     * Null when the request carries no module: an AJAX route or a test harness.
     * The menu is harmless there; a title and a shortcut pointing at nothing are
     * not, so they are left off rather than guessed.
     *
     * @return array{identifier: string, title: string, shortcutName: string}|null
     */
    private function moduleChromeFor(ServerRequestInterface $request, string $context): ?array
    {
        $module = $request->getAttribute('module');
        if (!$module instanceof ModuleInterface) {
            return null;
        }

        $title = $this->localizedModuleTitle($module);

        return [
            'identifier'   => $module->getIdentifier(),
            'title'        => $title,
            'shortcutName' => $context !== '' ? $title . ' · ' . $context : $title,
        ];
    }

    private function localizedModuleTitle(ModuleInterface $module): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $module->getTitle();
        }

        $title = $languageService->sL($module->getTitle());

        // sL() answers an empty string for a key it cannot resolve. An empty
        // title is worse than an unresolved one: the header would silently lose
        // the module name instead of showing something a reader can report.
        return $title !== '' ? $title : $module->getTitle();
    }
}
