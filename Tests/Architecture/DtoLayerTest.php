<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architectural tests for DTO layer isolation.
 *
 * These tests enforce that DTOs remain pure data containers without
 * business logic or infrastructure dependencies.
 */
final class DtoLayerTest
{
    /**
     * Form input DTOs should not depend on Extbase persistence.
     *
     * DTOs should be independent of ORM infrastructure.
     */
    public function testFormInputDtosDoNotDependOnPersistence(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('/.*FormInput$/', true))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('TYPO3\CMS\Extbase\Persistence'),
                Selector::inNamespace('TYPO3\CMS\Extbase\DomainObject'),
            )
            ->because('Form input DTOs must be persistence-agnostic. Only factories should handle ORM conversion.');
    }

    /**
     * Form input factories should depend on repositories (they need to resolve UIDs).
     *
     * This is the correct place for repository dependencies - in factories, not in models.
     */
    public function testFormInputFactoriesMayDependOnRepositories(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname('/.*FormInputFactory$/', true))
            ->canOnlyDependOn()
            ->classes(
                // DTOs they work with
                Selector::inNamespace('Netresearch\NrLlm\Controller\Backend\DTO'),
                // Domain models they create
                Selector::inNamespace('Netresearch\NrLlm\Domain\Model'),
                // Repositories for resolving relations
                Selector::inNamespace('Netresearch\NrLlm\Domain\Repository'),
            )
            ->because('Form input factories are the designated location for repository usage during entity creation.');
    }
}
