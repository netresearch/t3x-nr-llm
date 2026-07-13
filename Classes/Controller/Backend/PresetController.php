<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetImportService;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Admin-only AJAX endpoints for configuration presets (ADR-056).
 *
 * `listPresetsAction` returns the pending presets (declared by consuming
 * extensions via the `nr_llm.configuration_preset` DI tag but not yet
 * imported), each with its preflight result so the admin sees upfront
 * whether an import can succeed. `importAction` imports one preset by
 * identifier as a criteria-mode configuration record.
 *
 * Both actions are admin-gated via {@see RequiresBackendAdminTrait} FIRST
 * (ADR-037) — AJAX routes bypass the module's `access => admin` check.
 */
#[AsController]
final class PresetController extends ActionController
{
    use RequiresBackendAdminTrait;
    use DefensiveLocalizationTrait;

    public function __construct(
        private readonly ConfigurationPresetRegistry $presetRegistry,
        private readonly ConfigurationPresetImportService $importService,
    ) {}

    /**
     * List pending presets including a preflight result per preset (AJAX, admin-gated).
     */
    public function listPresetsAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $presets = [];
        foreach ($this->presetRegistry->pending() as $preset) {
            $preflight = $this->importService->preflight($preset);
            $presets[] = [
                'identifier' => $preset->identifier,
                'name' => $preset->name,
                'description' => $preset->description,
                'criteria' => $preset->criteria->toArray(),
                'satisfiable' => $preflight->satisfiable,
                'missingRequirement' => $preflight->missingRequirement,
                'matchedModelLabel' => $preflight->matchedModelLabel,
            ];
        }
        return new JsonResponse(['success' => true, 'presets' => $presets]);
    }

    /**
     * Import one pending preset by identifier (AJAX, admin-gated).
     *
     * Returns 404 for an identifier no registered provider declares, 422 when
     * the import is refused (already imported, or no active model satisfies
     * the criteria — the admin should configure a matching provider/model
     * first and re-check via the list endpoint).
     */
    public function importAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $identifier = $this->stringFromBody($request->getParsedBody(), 'identifier');
        $preset = $this->presetRegistry->findByIdentifier($identifier);
        if ($preset === null) {
            return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.preset.unknown', 'Unknown preset')], 404);
        }
        try {
            $configuration = $this->importService->import($preset);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        }
        return new JsonResponse([
            'success' => true,
            'identifier' => $configuration->getIdentifier(),
            'uid' => $configuration->getUid(),
        ]);
    }

    private function stringFromBody(mixed $body, string $key): string
    {
        if (!is_array($body)) {
            return '';
        }
        $value = $body[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }
}
