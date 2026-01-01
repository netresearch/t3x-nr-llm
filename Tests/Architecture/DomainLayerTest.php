<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Architecture;

use Netresearch\NrLlm\Domain\Model\Provider;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architectural tests for Domain layer isolation.
 *
 * These tests enforce that Domain models remain pure entities without
 * infrastructure dependencies. This prevents anti-patterns like:
 * - Domain models calling repositories
 * - Domain models depending on HTTP layer classes
 * - Mixing persistence logic with business logic
 */
final class DomainLayerTest
{
    /**
     * Domain models should not depend on repositories.
     *
     * This prevents N+1 query anti-patterns and hidden dependencies
     * where models lazily fetch related entities through repositories.
     */
    public function testDomainModelsDoNotDependOnRepositories(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Domain\Model'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Domain\Repository'))
            ->because('Domain models must not depend on repositories. Use DTOs and factories for entity hydration.');
    }

    /**
     * Domain models should not depend on controllers.
     *
     * This enforces proper layering where controllers depend on domain,
     * not the other way around.
     */
    public function testDomainModelsDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Domain\Model'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Controller'))
            ->because('Domain models must not depend on controllers. This violates dependency inversion.');
    }

    /**
     * Domain models should not depend on HTTP-specific classes.
     *
     * Domain models should be framework-agnostic for the HTTP layer.
     */
    public function testDomainModelsDoNotDependOnHttpClasses(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Domain\Model'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Psr\Http'),
                Selector::inNamespace('TYPO3\CMS\Core\Http'),
            )
            ->because('Domain models must not depend on HTTP layer. Use DTOs for data transfer.');
    }

    /**
     * Domain models (except Provider) should only depend on domain/Extbase classes.
     *
     * Provider is excluded because it legitimately needs encryption services
     * for API key handling.
     */
    public function testDomainModelsOnlyDependOnDomainAndCore(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Domain\Model'))
            ->excluding(
                // Provider needs encryption service for API key handling
                Selector::classname(Provider::class),
            )
            ->canOnlyDependOn()
            ->classes(
                // Other domain models (for relations)
                Selector::inNamespace('Netresearch\NrLlm\Domain\Model'),
                // Value objects and service options
                Selector::inNamespace('Netresearch\NrLlm\Service\Option'),
                // Extbase base classes (required for entity functionality)
                Selector::inNamespace('TYPO3\CMS\Extbase\DomainObject'),
                Selector::inNamespace('TYPO3\CMS\Extbase\Persistence'),
                // PHP built-ins are always allowed implicitly
            )
            ->because('Domain models should only depend on domain classes and Extbase infrastructure.');
    }

    /**
     * Provider model has special requirements for encryption.
     *
     * We limit what it can depend on to prevent other dependencies creeping in.
     */
    public function testProviderModelLimitedDependencies(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(Provider::class))
            ->canOnlyDependOn()
            ->classes(
                // Other domain models
                Selector::inNamespace('Netresearch\NrLlm\Domain\Model'),
                // Encryption service (required for API key handling)
                Selector::inNamespace('Netresearch\NrLlm\Service\Crypto'),
                // Extbase infrastructure
                Selector::inNamespace('TYPO3\CMS\Extbase\DomainObject'),
                Selector::inNamespace('TYPO3\CMS\Extbase\Persistence'),
                // TYPO3 utility for service instantiation (necessary evil)
                Selector::classname(GeneralUtility::class),
            )
            ->because('Provider has limited additional dependencies for API key encryption.');
    }
}
