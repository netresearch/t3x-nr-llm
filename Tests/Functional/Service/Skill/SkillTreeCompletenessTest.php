<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Service\Skill\GitHubClient;
use Netresearch\NrLlm\Service\Skill\MarketplaceParser;
use Netresearch\NrLlm\Service\Skill\SkillDiscovery;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use Netresearch\NrLlm\Service\Skill\SkillSyncLeaseRepository;
use Netresearch\NrLlm\Service\Skill\SkillSyncService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

#[CoversClass(GitHubClient::class)]
#[CoversClass(SkillSyncService::class)]
final class SkillTreeCompletenessTest extends AbstractFunctionalTestCase
{
    #[Test]
    #[DataProvider('sourceTypes')]
    public function truncatedTreeCannotOverwriteOrOrphanPreviouslyImportedSkills(
        bool $marketplace,
        SyncStatus $expectedStatus,
    ): void {
        $source = new SkillSource();
        $source->setType(
            $marketplace ? SkillSourceType::MARKETPLACE->value : SkillSourceType::REPO->value,
        );
        $source->setUrl(
            $marketplace ? 'https://raw.githubusercontent.com/acme/market/main/marketplace.json' : 'https://github.com/acme/skills',
        );
        $source->setRef('main');
        $this->getService(SkillSourceRepository::class)->add($source);
        $persistence = $this->getService(PersistenceManagerInterface::class);
        $persistence->persistAll();

        $initialRequests = [];
        $initial = $this->service($this->client(false, $initialRequests))->sync($source);
        self::assertSame(SyncStatus::OK, $initial->status);
        self::assertSame(2, $initial->created);
        $skills = $this
            ->getService(SkillRepository::class)
            ->findBySource((int)$source->getUid());
        self::assertCount(2, $skills);
        foreach ($skills as $skill) {
            $skill->setEnabled(true);
            $this->getService(SkillRepository::class)->update($skill);
        }

        $persistence->persistAll();
        $before = $this->skillRows();
        $previousSha = $source->getPinnedSha();

        $requests = [];
        $result = $this->service($this->client(true, $requests))->sync($source);

        self::assertSame(
            $before,
            $this->skillRows(),
            'A partial tree is not proof that omitted skills were removed; even its included skills must remain unchanged.',
        );
        self::assertSame($expectedStatus, $result->status);
        self::assertSame(
            [0, 0, 0, 0, 0],
            [
                $result->created,
                $result->updated,
                $result->disabledOnChange,
                $result->orphaned,
                $result->injectionBlocked,
            ],
        );
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('truncated', $result->errors[0]);
        self::assertSame($previousSha, $source->getPinnedSha());
        $expectedRequests = [
            'https://api.github.com/repos/acme/skills/commits/main',
            'https://api.github.com/repos/acme/skills/git/trees/newsha?recursive=1',
        ];
        if ($marketplace) {
            $expectedRequests[0] = 'https://api.github.com/repos/acme/skills/commits/HEAD';
            array_unshift(
                $expectedRequests,
                'https://raw.githubusercontent.com/acme/market/main/marketplace.json',
            );
        }

        self::assertSame(
            $expectedRequests,
            $requests,
            'No raw file may be fetched from an incomplete tree.',
        );
    }

    /**
     * @return iterable<string, array{bool, SyncStatus}>
     */
    public static function sourceTypes(): iterable
    {
        yield 'repository root' => [false, SyncStatus::ERROR];
        yield 'marketplace child' => [true, SyncStatus::PARTIAL];
    }

    private function service(GitHubClient $client): SkillSyncService
    {
        return new SkillSyncService(
            $client,
            new SkillMarkdownParser(),
            new MarketplaceParser(),
            new SkillDiscovery(),
            $this->getService(SkillRepository::class),
            $this->getService(PersistenceManagerInterface::class),
            new NullLogger(),
            $this->getService(SkillSyncLeaseRepository::class),
        );
    }

    /**
     * @param list<string> $requests
     */
    private function client(bool $truncated, array &$requests): GitHubClient
    {
        $transport = self::createStub(ClientInterface::class);
        $transport
            ->method('sendRequest')
            ->willReturnCallback(
                static function (
                    RequestInterface $request,
                ) use ($truncated, &$requests): Response {
                    $url = (string)$request->getUri();
                    $requests[] = $url;
                    if (str_ends_with($url, '/marketplace.json')) {
                        return new Response(
                            200,
                            [],
                            '{"plugins":[{"source":"acme/skills"}]}',
                        );
                    }

                    if (str_contains($url, '/commits/')) {
                        return new Response(
                            200,
                            [],
                            $truncated ? '{"sha":"newsha"}' : '{"sha":"oldsha"}',
                        );
                    }

                    if (str_contains($url, '/git/trees/')) {
                        return new Response(
                            200,
                            [],
                            $truncated ? '{"truncated":true,"tree":[{"type":"blob","path":"skills/a/SKILL.md"}]}' : '{"truncated":false,"tree":[{"type":"blob","path":"skills/a/SKILL.md"},{"type":"blob","path":"skills/b/SKILL.md"}]}',
                        );
                    }

                    return new Response(
                        200,
                        [],
                        "---\nname: guide\ndescription: Review\n---\n" . ($truncated ? 'Changed incomplete-tree body.' : 'Existing reviewed body.'),
                    );
                },
            );
        $client = new GitHubClient(
            self::createStub(VaultServiceInterface::class),
            new RequestFactory(new GuzzleClientFactory()),
            new NullLogger(),
        );
        $client->setHttpClient($transport);

        return $client;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skillRows(): array
    {
        return $this
            ->getService(ConnectionPool::class)
            ->getConnectionForTable('tx_nrllm_skill')
            ->select(['*'], 'tx_nrllm_skill', [], [], ['uid' => 'ASC'])
            ->fetchAllAssociative();
    }
}
