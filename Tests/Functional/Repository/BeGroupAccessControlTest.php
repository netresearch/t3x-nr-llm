<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ground-truth coverage for the backend-group access-control path.
 *
 * A configuration can be restricted to specific backend user groups via the
 * `allowed_groups` MM relation (table `tx_nrllm_configuration_begroups_mm`).
 * Two production consumers rely on it hydrating:
 *   - LlmConfigurationRepository::findAccessibleForGroups() filters at the DB
 *     level via `beGroups.uid`;
 *   - LlmConfigurationService::getConfigurationGroupIds() iterates
 *     LlmConfiguration::getBeGroups() in PHP.
 *
 * Before these tests the whole non-empty-group branch was uncovered — only the
 * "no groups" and the int-counter (`allowedGroups`) paths were exercised. These
 * tests assert the security-relevant behaviour end-to-end against a real MM row.
 */
#[CoversClass(LlmConfigurationRepository::class)]
final class BeGroupAccessControlTest extends AbstractFunctionalTestCase
{
    private LlmConfigurationRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeGroupRelations.csv');

        /** @var LlmConfigurationRepository $subject */
        $subject       = $this->get(LlmConfigurationRepository::class);
        $this->subject = $subject;
    }

    #[Test]
    public function beGroupsRelationHydratesFromMmTable(): void
    {
        $config = $this->subject->findByUid(130);
        self::assertInstanceOf(LlmConfiguration::class, $config);

        // The restricting group must be reachable through the ObjectStorage
        // relation — this is what getConfigurationGroupIds() iterates.
        self::assertCount(1, $config->getBeGroups());

        $uids = [];
        foreach ($config->getBeGroups() as $group) {
            $uids[] = $group->getUid();
        }
        self::assertSame([5], $uids);
    }

    #[Test]
    public function memberOfRestrictingGroupSeesRestrictedConfiguration(): void
    {
        $uids = $this->configUids($this->subject->findAccessibleForGroups([5]));

        self::assertContains(130, $uids, 'a member of the restricting group must see the restricted config');
        self::assertContains(131, $uids, 'the unrestricted config is always visible');
    }

    #[Test]
    public function nonMemberDoesNotSeeRestrictedConfiguration(): void
    {
        $uids = $this->configUids($this->subject->findAccessibleForGroups([6]));

        self::assertNotContains(130, $uids, 'a config restricted to group 5 must NOT leak to group 6 (fail-closed)');
        self::assertContains(131, $uids, 'the unrestricted config stays visible');
    }

    /**
     * @param iterable<LlmConfiguration> $configs
     *
     * @return list<int>
     */
    private function configUids(iterable $configs): array
    {
        $uids = [];
        foreach ($configs as $config) {
            $uid = $config->getUid();
            if ($uid !== null) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }
}
