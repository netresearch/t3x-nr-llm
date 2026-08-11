<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A provider of use-case packs (ADR-163).
 *
 * Implementations are discovered via the `nr_llm.use_case_pack` DI tag
 * (auto-applied by AutoconfigureTag, mirroring ToolInterface and
 * ConfigurationPresetProviderInterface) and collected by
 * {@see UseCasePackRegistry} through a tagged iterator.
 *
 * A provider returns declarations only. It never touches the database: what
 * gets written, when, and after whose confirmation is
 * {@see UseCasePackInstaller}'s decision.
 *
 * @api Extension point: third parties implement this. No new abstract
 * member within a major version (ADR-127).
 */
#[AutoconfigureTag(name: self::TAG_NAME)]
interface UseCasePackProviderInterface
{
    public const TAG_NAME = 'nr_llm.use_case_pack';

    /**
     * The packs this extension declares.
     *
     * @return list<UseCasePack>
     */
    public function getPacks(): array;
}
