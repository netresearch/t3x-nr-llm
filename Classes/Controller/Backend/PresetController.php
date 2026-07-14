<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetDiffService;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetImportService;
use Netresearch\NrLlm\Service\Preset\ConfigurationPresetRegistry;
use Netresearch\NrLlm\Service\Preset\PresetDiff;
use Netresearch\NrLlm\Service\Preset\PresetFieldDiff;
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
 * whether an import can succeed, plus the drifted imported presets whose
 * declaration changed since import. `importAction` imports one preset by
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
        private readonly ConfigurationPresetDiffService $diffService,
        private readonly LlmConfigurationRepository $configurationRepository,
    ) {}

    /**
     * List pending presets (with preflight) and drifted imported presets (AJAX, admin-gated).
     *
     * JSON shape:
     * `presets` — one entry per pending preset: `identifier`, `name`,
     * `description`, `criteria` (array), `satisfiable` (bool),
     * `missingRequirement` (string|null), `matchedModelLabel` (string|null).
     * `drifted` — one entry per imported preset whose declaration changed
     * since import (checksum mismatch, ADR-056): `identifier`, `name`,
     * `configurationUid` (int|null), and `changedFields` (list<string>, the
     * machine names of the fields an update would overwrite; may be empty when
     * only an optional seed was removed from the declaration). Consuming
     * clients drive the diff/update flow from these.
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
        $drifted = [];
        foreach ($this->presetRegistry->drifted() as $drift) {
            $drifted[] = [
                'identifier' => $drift['preset']->identifier,
                'name' => $drift['preset']->name,
                'configurationUid' => $drift['configuration']->getUid(),
                'changedFields' => $this->diffService->diff($drift['preset'], $drift['configuration'])->changedFields(),
            ];
        }
        return new JsonResponse(['success' => true, 'presets' => $presets, 'drifted' => $drifted]);
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

    /**
     * Diff a drifted imported preset against its record (AJAX GET, admin-gated).
     *
     * Returns the field-level changes an update would apply so the admin can
     * review before confirming: 404 for an unknown identifier, 422 when no
     * drifted record can be updated (not imported, up to date, mode switched,
     * or the updated criteria unsatisfiable), 200 with the diff otherwise.
     */
    public function diffAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $identifier = $this->stringFromBody($request->getQueryParams(), 'identifier');
        $preset = $this->presetRegistry->findByIdentifier($identifier);
        if ($preset === null) {
            return $this->unknownPresetResponse();
        }
        $record = $this->configurationRepository->findOneByIdentifier($identifier);
        if ($record === null) {
            return $this->notDriftedResponse();
        }
        try {
            $diff = $this->importService->previewUpdate($preset, $record);
        } catch (InvalidArgumentException $e) {
            return $this->refusalResponse($e);
        }
        return new JsonResponse([
            'success' => true,
            'identifier' => $diff->identifier,
            'name' => $diff->name,
            'changes' => $this->serializeChanges($diff),
        ]);
    }

    /**
     * Apply a reviewed preset update to its record (AJAX POST, admin-gated).
     *
     * Same 404/422 refusals as {@see diffAction}; on success re-stamps the
     * record's checksum (clearing the drift hint) and returns the machine names
     * of the fields that were applied.
     */
    public function updateAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $identifier = $this->stringFromBody($request->getParsedBody(), 'identifier');
        $preset = $this->presetRegistry->findByIdentifier($identifier);
        if ($preset === null) {
            return $this->unknownPresetResponse();
        }
        $record = $this->configurationRepository->findOneByIdentifier($identifier);
        if ($record === null) {
            return $this->notDriftedResponse();
        }
        try {
            $diff = $this->importService->update($preset, $record);
        } catch (InvalidArgumentException $e) {
            return $this->refusalResponse($e);
        }
        return new JsonResponse([
            'success' => true,
            'identifier' => $diff->identifier,
            'changedFields' => $diff->changedFields(),
        ]);
    }

    /**
     * @return list<array{field: string, current: string, declared: string}>
     */
    private function serializeChanges(PresetDiff $diff): array
    {
        return array_map(
            static fn(PresetFieldDiff $change): array => [
                'field' => $change->field,
                'current' => $change->current,
                'declared' => $change->declared,
            ],
            $diff->changes,
        );
    }

    private function unknownPresetResponse(): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.preset.unknown', 'Unknown preset')], 404);
    }

    private function notDriftedResponse(): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.preset.notDrifted', 'This configuration is up to date; there is nothing to update.')], 422);
    }

    /**
     * Map an update refusal to a 422 response, localising the static reasons and
     * surfacing the dynamic (missing-requirement) message for an unsatisfiable
     * update, mirroring how the import flow surfaces its reason.
     */
    private function refusalResponse(InvalidArgumentException $e): ResponseInterface
    {
        $message = match ($e->getCode()) {
            ConfigurationPresetImportService::CODE_UPDATE_NOT_DRIFTED => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.preset.notDrifted', 'This configuration is up to date; there is nothing to update.'),
            ConfigurationPresetImportService::CODE_UPDATE_MODE_SWITCHED => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.preset.modeSwitched', 'This configuration was switched to fixed model selection; the preset update is refused.'),
            default => $e->getMessage(),
        };
        return new JsonResponse(['success' => false, 'error' => $message], 422);
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
