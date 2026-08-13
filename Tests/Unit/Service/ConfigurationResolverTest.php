<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\ServiceAccountScope;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationInactiveException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

#[CoversClass(ConfigurationResolver::class)]
class ConfigurationResolverTest extends AbstractUnitTestCase
{
    #[Test]
    public function resolveDefaultConfigurationReturnsNullWhenProviderPinned(): void
    {
        $repository = self::createMock(LlmConfigurationRepository::class);
        $repository->expects(self::never())->method('findDefault');

        $subject = new ConfigurationResolver($repository);

        self::assertNull($subject->resolveDefaultConfiguration('openai'));
    }

    #[Test]
    public function resolveDefaultConfigurationReturnsNullWhenNoRepositoryWired(): void
    {
        $subject = new ConfigurationResolver();

        self::assertNull($subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function resolveDefaultConfigurationReturnsNullWhenNoDefaultExists(): void
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn(null);

        $subject = new ConfigurationResolver($repository);

        self::assertNull($subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function resolveDefaultConfigurationReturnsNullWhenDefaultHasNoModel(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn(null);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        self::assertNull($subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function resolveDefaultConfigurationReturnsNullWhenDefaultIsAccessRestricted(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn(self::createStub(Model::class));
        $configuration->method('hasAccessRestrictions')->willReturn(true);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        self::assertNull($subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function resolveDefaultConfigurationReturnsUnrestrictedDefaultWithModel(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn(self::createStub(Model::class));
        $configuration->method('hasAccessRestrictions')->willReturn(false);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        self::assertSame($configuration, $subject->resolveDefaultConfiguration(null));
    }

    #[Test]
    public function resolveEffectiveConfigurationReturnsExplicitConfigurationWithoutConsultingRepository(): void
    {
        $explicit = self::createStub(LlmConfiguration::class);

        $repository = self::createMock(LlmConfigurationRepository::class);
        $repository->expects(self::never())->method('findDefault');

        $subject = new ConfigurationResolver($repository);

        self::assertSame($explicit, $subject->resolveEffectiveConfiguration($explicit));
    }

    #[Test]
    public function resolveEffectiveConfigurationFallsBackToDefaultWhenNoneGiven(): void
    {
        $default = self::createStub(LlmConfiguration::class);
        $default->method('getLlmModel')->willReturn(self::createStub(Model::class));
        $default->method('hasAccessRestrictions')->willReturn(false);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($default);

        $subject = new ConfigurationResolver($repository);

        self::assertSame($default, $subject->resolveEffectiveConfiguration());
    }

    #[Test]
    public function getActiveByIdentifierReturnsActiveUnrestrictedConfiguration(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(false);

        $repository = self::createMock(LlmConfigurationRepository::class);
        $repository->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('blog-summarizer')
            ->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        self::assertSame($configuration, $subject->getActiveByIdentifier('blog-summarizer'));
    }

    #[Test]
    public function getActiveByIdentifierDoesNotRequireAnAssignedModel(): void
    {
        // Criteria-mode configurations carry no direct model relation; the
        // model is resolved at call time (ADR-066), so the resolver must not
        // refuse them the way the default path refuses a model-less default.
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn(null);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(false);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        self::assertSame($configuration, $subject->getActiveByIdentifier('criteria-mode'));
    }

    #[Test]
    public function getActiveByIdentifierThrowsWhenConfigurationIsMissing(): void
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn(null);

        $subject = new ConfigurationResolver($repository);

        $this->expectException(ConfigurationNotFoundException::class);
        $this->expectExceptionCode(1784211001);

        $subject->getActiveByIdentifier('missing');
    }

    #[Test]
    public function getActiveByIdentifierThrowsWhenNoRepositoryWired(): void
    {
        $subject = new ConfigurationResolver();

        $this->expectException(ConfigurationNotFoundException::class);
        $this->expectExceptionCode(1784211001);

        $subject->getActiveByIdentifier('anything');
    }

    #[Test]
    public function getActiveByIdentifierThrowsWhenConfigurationIsInactive(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(false);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        $this->expectException(ConfigurationInactiveException::class);
        $this->expectExceptionCode(1784211002);

        $subject->getActiveByIdentifier('deactivated');
    }

    #[Test]
    public function getActiveByIdentifierThrowsWhenConfigurationIsAccessRestricted(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(true);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        $subject = new ConfigurationResolver($repository);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionCode(1784211003);

        $subject->getActiveByIdentifier('restricted');
    }

    // ---- getActiveByIdentifierForActor(): the actorMayUse() decision ----
    //
    // An authorisation decision (ADR-070/083/110): who may drive a call
    // through a group-restricted configuration. Every branch is pinned here
    // because the check runs against the passed actor, not the ambient
    // BE_USER — precisely so a queue worker authorises identically to a
    // synchronous request, which also means no functional-test fixture ever
    // exercises it by accident.

    /**
     * @param list<int|null> $groupUids
     */
    private function restrictedConfiguration(array $groupUids): LlmConfiguration
    {
        $groups = new ObjectStorage();
        foreach ($groupUids as $uid) {
            $group = self::createStub(AbstractEntity::class);
            $group->method('getUid')->willReturn($uid);
            $groups->attach($group);
        }

        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn($groupUids !== []);
        $configuration->method('getBeGroups')->willReturn($groups);

        return $configuration;
    }

    private function resolverFor(LlmConfiguration $configuration): ConfigurationResolver
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        return new ConfigurationResolver($repository);
    }

    #[Test]
    public function anUnrestrictedConfigurationResolvesForAnyActorIncludingAnonymous(): void
    {
        $configuration = $this->restrictedConfiguration([]);

        $resolved = $this->resolverFor($configuration)
            ->getActiveByIdentifierForActor('open', AiActorContext::anonymous());

        self::assertSame($configuration, $resolved);
    }

    #[Test]
    public function anAdminResolvesARestrictedConfigurationWithoutGroupMembership(): void
    {
        $configuration = $this->restrictedConfiguration([7]);

        $resolved = $this->resolverFor($configuration)
            ->getActiveByIdentifierForActor('restricted', AiActorContext::backendUser(3, isAdmin: true));

        self::assertSame($configuration, $resolved);
    }

    #[Test]
    public function aGroupMemberResolvesARestrictedConfiguration(): void
    {
        $configuration = $this->restrictedConfiguration([7, 9]);

        $resolved = $this->resolverFor($configuration)
            ->getActiveByIdentifierForActor('restricted', AiActorContext::backendUser(3, backendGroupIds: [2, 9]));

        self::assertSame($configuration, $resolved);
    }

    #[Test]
    public function aNonMemberIsDeniedARestrictedConfiguration(): void
    {
        $configuration = $this->restrictedConfiguration([7]);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionCode(1784211004);

        $this->resolverFor($configuration)
            ->getActiveByIdentifierForActor('restricted', AiActorContext::backendUser(3, backendGroupIds: [2, 5]));
    }

    #[Test]
    public function anAnonymousActorIsDeniedARestrictedConfiguration(): void
    {
        $configuration = $this->restrictedConfiguration([7]);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionCode(1784211004);

        $this->resolverFor($configuration)
            ->getActiveByIdentifierForActor('restricted', AiActorContext::anonymous());
    }

    #[Test]
    public function aServiceAccountWithTheConfigurationUseScopeResolvesARestrictedConfiguration(): void
    {
        $configuration = $this->restrictedConfiguration([7]);

        $resolved = $this->resolverFor($configuration)->getActiveByIdentifierForActor(
            'restricted',
            AiActorContext::serviceAccount('nightly-report', [ServiceAccountScope::CONFIGURATION_USE]),
        );

        self::assertSame($configuration, $resolved);
    }

    #[Test]
    public function aServiceAccountWithoutTheScopeIsDenied(): void
    {
        $configuration = $this->restrictedConfiguration([7]);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionCode(1784211004);

        $this->resolverFor($configuration)->getActiveByIdentifierForActor(
            'restricted',
            AiActorContext::serviceAccount('nightly-report', [ServiceAccountScope::AGENT_READ]),
        );
    }

    /**
     * A service account is entitled by its scope, never by group membership:
     * the branch decides before groups are consulted. Pinned so nobody
     * "fixes" a denied automation by attaching a backend group to it instead
     * of granting the scope, which is the auditable path (ADR-110).
     */
    #[Test]
    public function aServiceAccountsGroupMembershipDoesNotSubstituteForTheScope(): void
    {
        $configuration = $this->restrictedConfiguration([7]);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionCode(1784211004);

        $this->resolverFor($configuration)->getActiveByIdentifierForActor(
            'restricted',
            // fromArray() is the one public path that can carry both a
            // service-account name and group ids — the persisted-run shape.
            AiActorContext::fromArray([
                'backendGroupIds' => [7],
                'serviceAccount'  => 'nightly-report',
            ]),
        );
    }

    /**
     * An unpersisted group has no uid; it must count as "matches nobody"
     * rather than blow up or, worse, match an actor whose group list happens
     * to contain a null-ish value.
     */
    #[Test]
    public function aGroupWithoutAUidMatchesNoActor(): void
    {
        $configuration = $this->restrictedConfiguration([null]);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionCode(1784211004);

        $this->resolverFor($configuration)
            ->getActiveByIdentifierForActor('restricted', AiActorContext::backendUser(3, backendGroupIds: [2]));
    }

    // ---- actorMayUse(): the same decision, without the throw (ADR-167) ----
    //
    // The eight cases above are the characterization of the throwing entry
    // point and are unchanged by the extraction. These pin that the predicate
    // the Governance tab asks answers identically — one implementation, two
    // callers — over an entity the caller already holds, with no identifier
    // lookup and no exception to sort by type.

    #[Test]
    public function theAccessPredicateRefusesANonMemberWithoutThrowing(): void
    {
        self::assertFalse((new ConfigurationResolver())->actorMayUse(
            $this->restrictedConfiguration([7]),
            AiActorContext::backendUser(3, backendGroupIds: [2, 5]),
        ));
    }

    #[Test]
    public function theAccessPredicateAllowsAMemberAnAdminAndAnUnrestrictedConfiguration(): void
    {
        $subject = new ConfigurationResolver();

        self::assertTrue($subject->actorMayUse(
            $this->restrictedConfiguration([7, 9]),
            AiActorContext::backendUser(3, backendGroupIds: [2, 9]),
        ));
        self::assertTrue($subject->actorMayUse(
            $this->restrictedConfiguration([7]),
            AiActorContext::backendUser(3, isAdmin: true),
        ));
        self::assertTrue($subject->actorMayUse(
            $this->restrictedConfiguration([]),
            AiActorContext::anonymous(),
        ));
    }

    #[Test]
    public function theAccessPredicateRefusesAnAnonymousActorARestrictedConfiguration(): void
    {
        // The fail-closed answer the simulator's unresolved-actor case relies on.
        self::assertFalse((new ConfigurationResolver())->actorMayUse(
            $this->restrictedConfiguration([7]),
            AiActorContext::anonymous(),
        ));
    }
}
