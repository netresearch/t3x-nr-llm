<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The overview of the "Setup" container: one card per submodule, and a notice
 * pointing at the setup wizard while no provider exists.
 *
 * TYPO3 14 renders such an overview itself for a container that sets
 * `showSubmoduleOverview`, but its controller is final and offers no event, so
 * nothing can be added above the cards. This action replaces it for the one
 * container that needs the notice. TYPO3 13 has no submodule overview: its
 * BackendModuleValidator opens the last used submodule, or the first one,
 * which is the setup wizard itself, so this action is not reached there.
 *
 * The cards are the container's submodules as the module registry sorted them,
 * filtered by the same access check the core overview applies, so they always
 * match the module menu. "No provider" is the predicate the extension's main
 * overview uses for its own wizard notice ({@see ProviderRepository::countActive()}),
 * so the two pages cannot disagree about it.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final class SetupOverviewController extends ActionController
{
    use ModuleChromeTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ModuleProvider $moduleProvider,
        private readonly ProviderRepository $providerRepository,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->applyModuleChrome($moduleTemplate, $this->request);

        $module = $this->request->getAttribute('module');

        $moduleTemplate->assignMultiple([
            'module'        => $module instanceof ModuleInterface ? $module : null,
            'submodules'    => $module instanceof ModuleInterface ? $this->accessibleSubmodules($module) : [],
            'needsProvider' => $this->providerRepository->countActive() === 0,
        ]);

        return $moduleTemplate->renderResponse('Backend/SetupOverview/Index');
    }

    /**
     * @return list<ModuleInterface>
     */
    private function accessibleSubmodules(ModuleInterface $module): array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return [];
        }

        $submodules = [];
        foreach ($module->getSubModules() as $submodule) {
            if ($this->moduleProvider->accessGranted($submodule->getIdentifier(), $user)) {
                $submodules[] = $submodule;
            }
        }

        return $submodules;
    }
}
