<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\AccessDeniedException;
use Netresearch\NrLlm\Exception\ConfigurationNotFoundException;
use Netresearch\NrLlm\Service\LlmConfigurationService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

#[CoversClass(LlmConfigurationService::class)]
class LlmConfigurationServiceTest extends AbstractUnitTestCase
{
    private LlmConfigurationRepository&Stub $repositoryStub;
    private PersistenceManagerInterface&Stub $persistenceManagerStub;
    private Context&Stub $contextStub;
    private bool $isAdmin = false;
    private bool $isLoggedIn = true;
    /** @var array<int> */
    private array $groupIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryStub = self::createStub(LlmConfigurationRepository::class);
        $this->persistenceManagerStub = self::createStub(PersistenceManagerInterface::class);
        $this->contextStub = self::createStub(Context::class);

        // Reset user state
        $this->isAdmin = false;
        $this->isLoggedIn = true;
        $this->groupIds = [];
    }

    private function createSubject(): LlmConfigurationService
    {
        return new LlmConfigurationService(
            $this->repositoryStub,
            $this->persistenceManagerStub,
            $this->contextStub,
        );
    }

    /**
     * @param list<int>|null $groupIds
     */
    private function createConfigurationStub(
        string $identifier = 'test-config',
        bool $isActive = true,
        bool $hasRestrictions = false,
        ?array $groupIds = null,
    ): LlmConfiguration&Stub {
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getIdentifier')->willReturn($identifier);
        $config->method('isActive')->willReturn($isActive);
        $config->method('hasAccessRestrictions')->willReturn($hasRestrictions);

        if ($groupIds !== null) {
            $groups = new ObjectStorage();
            foreach ($groupIds as $id) {
                $group = self::createStub(AbstractEntity::class);
                $group->method('getUid')->willReturn($id);
                $groups->attach($group);
            }
            $config->method('getBeGroups')->willReturn($groups);
        } else {
            $config->method('getBeGroups')->willReturn(null);
        }

        return $config;
    }

    private function setupAdminUser(): void
    {
        $this->isLoggedIn = true;
        $this->isAdmin = true;
        $this->groupIds = [];
        $this->setupContextStub();
    }

    /**
     * @param array<int> $groupIds
     */
    private function setupNonAdminUser(array $groupIds = []): void
    {
        $this->isLoggedIn = true;
        $this->isAdmin = false;
        $this->groupIds = $groupIds;
        $this->setupContextStub();
    }

    private function setupNoBackendUser(): void
    {
        $this->contextStub
            ->method('getAspect')
            ->with('backend.user')
            ->willThrowException(new AspectNotFoundException());
    }

    private function setupContextStub(): void
    {
        // Create an anonymous class that implements AspectInterface
        $aspectStub = new class ($this->isLoggedIn, $this->isAdmin, $this->groupIds) implements AspectInterface {
            /**
             * @param array<int> $groupIds
             */
            public function __construct(
                private readonly bool $isLoggedIn,
                private readonly bool $isAdmin,
                private readonly array $groupIds,
            ) {}

            public function get(string $name): mixed
            {
                return match ($name) {
                    'isLoggedIn' => $this->isLoggedIn,
                    'isAdmin' => $this->isAdmin,
                    'groupIds' => $this->groupIds,
                    default => null,
                };
            }
        };

        $this->contextStub
            ->method('getAspect')
            ->with('backend.user')
            ->willReturn($aspectStub);
    }

    // ==================== getConfiguration tests ====================

    #[Test]
    public function getConfigurationReturnsActiveConfiguration(): void
    {
        $this->setupAdminUser();
        $config = $this->createConfigurationStub();

        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->with('test-config')
            ->willReturn($config);

        $subject = $this->createSubject();
        $result = $subject->getConfiguration('test-config');

        self::assertSame($config, $result);
    }

    #[Test]
    public function getConfigurationThrowsWhenNotFound(): void
    {
        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn(null);

        $subject = $this->createSubject();

        $this->expectException(ConfigurationNotFoundException::class);

        $subject->getConfiguration('nonexistent');
    }

    #[Test]
    public function getConfigurationThrowsWhenNotActive(): void
    {
        $config = $this->createConfigurationStub(isActive: false);

        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($config);

        $subject = $this->createSubject();

        $this->expectException(ConfigurationNotFoundException::class);

        $subject->getConfiguration('test-config');
    }

    #[Test]
    public function getConfigurationThrowsWhenAccessDenied(): void
    {
        $this->setupNonAdminUser();
        $config = $this->createConfigurationStub(hasRestrictions: true, groupIds: [5, 6]);

        $this->repositoryStub
            ->method('findOneByIdentifier')
            ->willReturn($config);

        $subject = $this->createSubject();

        $this->expectException(AccessDeniedException::class);

        $subject->getConfiguration('test-config');
    }

    // ==================== getDefaultConfiguration tests ====================

    #[Test]
    public function getDefaultConfigurationReturnsDefault(): void
    {
        $this->setupAdminUser();
        $config = $this->createConfigurationStub();

        $this->repositoryStub
            ->method('findDefault')
            ->willReturn($config);

        $subject = $this->createSubject();
        $result = $subject->getDefaultConfiguration();

        self::assertSame($config, $result);
    }

    #[Test]
    public function getDefaultConfigurationThrowsWhenNotFound(): void
    {
        $this->repositoryStub
            ->method('findDefault')
            ->willReturn(null);

        $subject = $this->createSubject();

        $this->expectException(ConfigurationNotFoundException::class);

        $subject->getDefaultConfiguration();
    }

    // ==================== getAccessibleConfigurations tests ====================

    #[Test]
    public function getAccessibleConfigurationsReturnsAllForAdmin(): void
    {
        $this->setupAdminUser();

        $config1 = $this->createConfigurationStub('config1');
        $config2 = $this->createConfigurationStub('config2');

        $queryResult = self::createStub(QueryResultInterface::class);
        $queryResult->method('toArray')->willReturn([$config1, $config2]);

        $this->repositoryStub
            ->method('findActive')
            ->willReturn($queryResult);

        $subject = $this->createSubject();
        $result = $subject->getAccessibleConfigurations();

        self::assertCount(2, $result);
    }

    #[Test]
    public function getAccessibleConfigurationsFiltersForNonAdmin(): void
    {
        $this->setupNonAdminUser([1, 2, 3]);

        $config1 = $this->createConfigurationStub('config1');

        $queryResult = self::createStub(QueryResultInterface::class);
        $queryResult->method('toArray')->willReturn([$config1]);

        $this->repositoryStub
            ->method('findAccessibleForGroups')
            ->with([1, 2, 3])
            ->willReturn($queryResult);

        $subject = $this->createSubject();
        $result = $subject->getAccessibleConfigurations();

        self::assertCount(1, $result);
    }

    // ==================== hasAccess tests ====================

    #[Test]
    public function hasAccessReturnsFalseWhenNoBackendUser(): void
    {
        $this->setupNoBackendUser();
        $config = $this->createConfigurationStub();

        $subject = $this->createSubject();
        $result = $subject->hasAccess($config);

        self::assertFalse($result);
    }

    #[Test]
    public function hasAccessReturnsTrueForAdmin(): void
    {
        $this->setupAdminUser();
        $config = $this->createConfigurationStub(hasRestrictions: true, groupIds: [5]);

        $subject = $this->createSubject();
        $result = $subject->hasAccess($config);

        self::assertTrue($result);
    }

    #[Test]
    public function hasAccessReturnsTrueWhenNoRestrictions(): void
    {
        $this->setupNonAdminUser([1, 2]);
        $config = $this->createConfigurationStub(hasRestrictions: false);

        $subject = $this->createSubject();
        $result = $subject->hasAccess($config);

        self::assertTrue($result);
    }

    #[Test]
    public function hasAccessReturnsTrueWhenUserInAllowedGroup(): void
    {
        $this->setupNonAdminUser([1, 2, 3]);
        $config = $this->createConfigurationStub(hasRestrictions: true, groupIds: [2, 5]);

        $subject = $this->createSubject();
        $result = $subject->hasAccess($config);

        self::assertTrue($result);
    }

    #[Test]
    public function hasAccessReturnsFalseWhenUserNotInAllowedGroup(): void
    {
        $this->setupNonAdminUser([1, 2, 3]);
        $config = $this->createConfigurationStub(hasRestrictions: true, groupIds: [5, 6]);

        $subject = $this->createSubject();
        $result = $subject->hasAccess($config);

        self::assertFalse($result);
    }

    // ==================== checkAccess tests ====================

    #[Test]
    public function checkAccessDoesNotThrowWhenHasAccess(): void
    {
        $this->setupAdminUser();
        $config = $this->createConfigurationStub();

        $subject = $this->createSubject();

        // Should not throw
        $subject->checkAccess($config);

        // Test passes if no exception was thrown
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function checkAccessThrowsWhenNoAccess(): void
    {
        $this->setupNonAdminUser();
        $config = $this->createConfigurationStub(hasRestrictions: true, groupIds: [5]);

        $subject = $this->createSubject();

        $this->expectException(AccessDeniedException::class);

        $subject->checkAccess($config);
    }

    // ==================== setAsDefault tests ====================

    #[Test]
    public function setAsDefaultUnsetsOthersAndSetsNew(): void
    {
        $config = $this->createMock(LlmConfiguration::class);
        $config->expects(self::once())->method('setIsDefault')->with(true);

        $repositoryMock = $this->createMock(LlmConfigurationRepository::class);
        $repositoryMock->expects(self::once())->method('unsetAllDefaults');
        $repositoryMock->expects(self::once())->method('update')->with($config);

        $persistenceManagerMock = $this->createMock(PersistenceManagerInterface::class);
        $persistenceManagerMock->expects(self::once())->method('persistAll');

        $subject = new LlmConfigurationService(
            $repositoryMock,
            $persistenceManagerMock,
            $this->contextStub,
        );
        $subject->setAsDefault($config);
    }

    // ==================== toggleActive tests ====================

    #[Test]
    public function toggleActiveTogglesStatus(): void
    {
        $config = $this->createMock(LlmConfiguration::class);
        $config->method('isActive')->willReturn(true);
        $config->expects(self::once())->method('setIsActive')->with(false);

        $repositoryMock = $this->createMock(LlmConfigurationRepository::class);
        $repositoryMock->expects(self::once())->method('update')->with($config);

        $persistenceManagerMock = $this->createMock(PersistenceManagerInterface::class);
        $persistenceManagerMock->expects(self::once())->method('persistAll');

        $subject = new LlmConfigurationService(
            $repositoryMock,
            $persistenceManagerMock,
            $this->contextStub,
        );
        $subject->toggleActive($config);
    }

    // ==================== CRUD tests ====================

    #[Test]
    public function createAddsAndPersists(): void
    {
        $config = self::createStub(LlmConfiguration::class);

        $repositoryMock = $this->createMock(LlmConfigurationRepository::class);
        $repositoryMock->expects(self::once())->method('add')->with($config);

        $persistenceManagerMock = $this->createMock(PersistenceManagerInterface::class);
        $persistenceManagerMock->expects(self::once())->method('persistAll');

        $subject = new LlmConfigurationService(
            $repositoryMock,
            $persistenceManagerMock,
            $this->contextStub,
        );
        $subject->create($config);
    }

    #[Test]
    public function updateUpdatesAndPersists(): void
    {
        $config = self::createStub(LlmConfiguration::class);

        $repositoryMock = $this->createMock(LlmConfigurationRepository::class);
        $repositoryMock->expects(self::once())->method('update')->with($config);

        $persistenceManagerMock = $this->createMock(PersistenceManagerInterface::class);
        $persistenceManagerMock->expects(self::once())->method('persistAll');

        $subject = new LlmConfigurationService(
            $repositoryMock,
            $persistenceManagerMock,
            $this->contextStub,
        );
        $subject->update($config);
    }

    #[Test]
    public function deleteRemovesAndPersists(): void
    {
        $config = self::createStub(LlmConfiguration::class);

        $repositoryMock = $this->createMock(LlmConfigurationRepository::class);
        $repositoryMock->expects(self::once())->method('remove')->with($config);

        $persistenceManagerMock = $this->createMock(PersistenceManagerInterface::class);
        $persistenceManagerMock->expects(self::once())->method('persistAll');

        $subject = new LlmConfigurationService(
            $repositoryMock,
            $persistenceManagerMock,
            $this->contextStub,
        );
        $subject->delete($config);
    }

    // ==================== isIdentifierAvailable tests ====================

    #[Test]
    public function isIdentifierAvailableChecksRepository(): void
    {
        $this->repositoryStub
            ->method('isIdentifierUnique')
            ->with('new-identifier', 5)
            ->willReturn(true);

        $subject = $this->createSubject();
        $result = $subject->isIdentifierAvailable('new-identifier', 5);

        self::assertTrue($result);
    }

    #[Test]
    public function isIdentifierAvailableReturnsFalseWhenTaken(): void
    {
        $this->repositoryStub
            ->method('isIdentifierUnique')
            ->with('existing-identifier', null)
            ->willReturn(false);

        $subject = $this->createSubject();
        $result = $subject->isIdentifierAvailable('existing-identifier');

        self::assertFalse($result);
    }

    // ==================== Edge cases ====================

    #[Test]
    public function hasAccessHandlesNullBeGroups(): void
    {
        $this->setupNonAdminUser([1, 2]);

        $config = self::createStub(LlmConfiguration::class);
        $config->method('hasAccessRestrictions')->willReturn(true);
        $config->method('getBeGroups')->willReturn(null);

        $subject = $this->createSubject();
        $result = $subject->hasAccess($config);

        // No allowed groups means no access
        self::assertFalse($result);
    }

    #[Test]
    public function getAccessibleConfigurationsHandlesEmptyGroupIds(): void
    {
        $this->setupNonAdminUser([]);

        $queryResult = self::createStub(QueryResultInterface::class);
        $queryResult->method('toArray')->willReturn([]);

        $this->repositoryStub
            ->method('findAccessibleForGroups')
            ->with([])
            ->willReturn($queryResult);

        $subject = $this->createSubject();
        $result = $subject->getAccessibleConfigurations();

        self::assertEmpty($result);
    }
}
