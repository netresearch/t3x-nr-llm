<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\UseCase\UseCase;
use Netresearch\NrLlm\Service\UseCase\UseCasePack;
use Netresearch\NrLlm\Service\UseCase\UseCasePackInstaller;
use Netresearch\NrLlm\Service\UseCase\UseCasePackRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The onboarding step that starts from the use case (ADR-163).
 *
 * Setup used to start at "Provider", which is the last question an operator can
 * answer and the first one the technical wizard asks. This module asks "what do
 * you want to do?" instead, and answers it with a pack: a named bundle of a
 * configuration preset, tasks and snippets, plus the governance posture and
 * tool groups it was written for.
 *
 * Three properties this controller must keep:
 *
 * - **Nothing is written before an operator confirms.** `show` computes a plan
 *   read-only; only the POSTed `install` writes, and it refuses a GET.
 * - **It recommends, it does not apply.** The governance profile and the tool
 *   groups are rendered with a link to the surface that owns them. Neither is
 *   set from here (ADR-145 for the profile, the Tools module's admin enable for
 *   the groups).
 * - **The technical wizard stays.** Every screen links to it, and a use case
 *   with no pack says so and links there instead of hiding itself.
 *
 * The module is registered `access => admin`; the records an install creates
 * are ordinary records an administrator could create by hand.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final class UseCasePackController extends ActionController
{
    use DefensiveLocalizationTrait;

    private const LANG = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UseCasePackRegistry $registry,
        private readonly UseCasePackInstaller $installer,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly FlashMessageService $flashMessageService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * "What do you want to do?" — every use case, with the packs answering it.
     */
    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $groups = [];
        foreach (UseCase::cases() as $useCase) {
            $packs = $this->registry->forUseCase($useCase);
            $groups[] = [
                'useCase' => $useCase,
                'packs' => $packs,
                // An explicit boolean: `<f:if condition="{group.packs}">` over an
                // empty array works, but the negative branch is the one carrying
                // the "not built yet" message and deserves to be readable.
                'hasPacks' => $packs !== [],
            ];
        }

        $moduleTemplate->assignMultiple([
            'groups' => $groups,
            'wizardUrl' => $this->wizardUrl(),
        ]);

        return $moduleTemplate->renderResponse('Backend/UseCase/Index');
    }

    /**
     * One pack, and what installing it would do to this installation.
     */
    public function showAction(string $pack = ''): ResponseInterface
    {
        $useCasePack = $this->registry->findByIdentifier($pack);
        if (!$useCasePack instanceof UseCasePack) {
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('index'));
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $moduleTemplate->assignMultiple([
            'pack' => $useCasePack,
            'plan' => $this->installer->plan($useCasePack),
            'wizardUrl' => $this->wizardUrl(),
            'governanceUrl' => $this->routeUrl('nrllm_overview', 'Backend\\LlmModule', 'governance'),
            'toolsUrl' => $this->routeUrl('nrllm_tools', 'Backend\\Tool', 'list'),
            'tasksUrl' => $this->routeUrl('nrllm_tasks', 'Backend\\TaskList', 'list'),
            'snippetsUrl' => $this->routeUrl('nrllm_snippets', 'Backend\\PromptSnippet', 'list'),
            'configurationsUrl' => $this->routeUrl('nrllm_configurations', 'Backend\\Configuration', 'list'),
        ]);

        return $moduleTemplate->renderResponse('Backend/UseCase/Show');
    }

    /**
     * Create the pack's missing records, after the operator confirmed the plan.
     */
    public function installAction(string $pack = ''): ResponseInterface
    {
        $useCasePack = $this->registry->findByIdentifier($pack);
        if (!$useCasePack instanceof UseCasePack) {
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('index'));
        }

        // The confirmation is a POST of the plan screen. A GET of this URL —
        // a bookmark, a prefetch, a link someone pasted — must not provision.
        if ($this->request->getMethod() !== 'POST') {
            return new RedirectResponse($this->uriBuilder->reset()->uriFor('show', ['pack' => $pack]));
        }

        try {
            $result = $this->installer->install($useCasePack);
        } catch (InvalidArgumentException $e) {
            // The refusals are the operator's to act on and name what is
            // missing (no satisfying model, in practice), so the message is
            // shown rather than swallowed.
            $this->enqueueFlashMessage(
                $e->getMessage(),
                $this->localize(self::LANG . 'flash.usecase.installFailed.title', 'Pack not installed'),
                ContextualFeedbackSeverity::WARNING,
            );

            return new RedirectResponse($this->uriBuilder->reset()->uriFor('show', ['pack' => $pack]));
        } catch (Throwable $e) {
            $this->logger->error('Use-case pack: install failed', ['pack' => $pack, 'exception' => $e]);
            $this->enqueueFlashMessage(
                $this->localize(self::LANG . 'flash.usecase.installError.body', 'Failed to install the pack. See the system log for details.'),
                $this->localize(self::LANG . 'flash.usecase.installFailed.title', 'Pack not installed'),
                ContextualFeedbackSeverity::ERROR,
            );

            return new RedirectResponse($this->uriBuilder->reset()->uriFor('show', ['pack' => $pack]));
        }

        $body = sprintf(
            $this->localize(self::LANG . 'flash.usecase.installed.body', '%1$s: %2$d record(s) created, %3$d already present and left unchanged.'),
            $useCasePack->name,
            $result->getCreatedCount(),
            $result->getSkippedCount(),
        );

        // The tag link is the one thing an install writes on a record it did
        // not create. Saying only "created / left unchanged" would describe the
        // preset-first case — configuration already there, tags just written —
        // as the case where nothing was touched.
        if ($result->addedSnippetTags !== []) {
            $body .= ' ' . sprintf(
                $this->localize(self::LANG . 'flash.usecase.installed.tags', 'Snippet tags added to the configuration: %s.'),
                implode(', ', $result->addedSnippetTags),
            );
        }

        $this->enqueueFlashMessage(
            $body,
            $this->localize(self::LANG . 'flash.usecase.installed.title', 'Pack installed'),
            ContextualFeedbackSeverity::OK,
        );

        return new RedirectResponse($this->uriBuilder->reset()->uriFor('show', ['pack' => $pack]));
    }

    /**
     * The technical wizard's own backend route. `f:uri.action` cannot reach
     * another module's route, and the controller/action are stated explicitly
     * so the link does not depend on the module's default-action order.
     */
    private function wizardUrl(): string
    {
        return $this->routeUrl('nrllm_wizard', 'Backend\\SetupWizard', 'index');
    }

    private function routeUrl(string $route, string $controller, string $action): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute($route, [
            'controller' => $controller,
            'action' => $action,
        ]);
    }

    private function enqueueFlashMessage(string $message, string $title, ContextualFeedbackSeverity $severity): void
    {
        // FlashMessage is a plain value object, not a service — instantiate directly.
        $this->flashMessageService
            ->getMessageQueueByIdentifier()
            ->addMessage(new FlashMessage($message, $title, $severity, true));
    }
}
