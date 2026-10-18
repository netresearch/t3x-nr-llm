<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\InvocableProcessSkill;
use Netresearch\NrLlm\Service\Agent\Process\ProcessSkillCatalogue;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * The process skills the current backend user may start on a configuration,
 * as JSON for the slash-command menu (ADR-214 item 10, ADR-228).
 *
 * Serves {@see ProcessSkillCatalogue}'s list for the authenticated user: a
 * GET with the configuration uid. An unknown, deleted or deactivated
 * configuration, and one the user may not use, answer 404 alike, so the
 * route does not tell configurations the user may not see apart from ones
 * that do not exist.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final readonly class ProcessSkillCatalogueController
{
    use BackendUserUidTrait;

    public function __construct(
        private LlmConfigurationRepository $configurationRepository,
        private ProcessSkillCatalogue $catalogue,
        private ConfigurationResolver $configurations,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $raw = $request->getQueryParams()['configuration'] ?? null;
        $uid = is_string($raw) && ctype_digit($raw) ? (int)$raw : 0;

        $configuration = $uid > 0 ? $this->configurationRepository->findByUid($uid) : null;
        $actor         = $this->currentActor();
        if (!$configuration instanceof LlmConfiguration || !$configuration->isActive() || !$this->configurations->actorMayUse($configuration, $actor)) {
            return new JsonResponse(['success' => false, 'error' => 'Unknown configuration.'], 404);
        }

        return new JsonResponse([
            'success' => true,
            'skills'  => array_map(
                static fn(InvocableProcessSkill $skill): array => $skill->toArray(),
                $this->catalogue->forActor($configuration, $actor),
            ),
        ]);
    }
}
