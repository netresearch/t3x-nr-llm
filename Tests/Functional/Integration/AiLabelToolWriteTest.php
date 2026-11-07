<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Integration;

use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SkillToolAllowList;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * The actual tool, approval resume, dispatcher and upstream API; no provider calls.
 */
#[CoversClass(ToolLoopService::class)]
final class AiLabelToolWriteTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-vault', 'b13/ai-label', 'netresearch/nr-llm'];

    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    /**
     * @return iterable<string, array{bool, AiOrigin}>
     */
    public static function bodies(): iterable
    {
        yield 'explicit AI body' => [true, AiOrigin::Generated];
        yield 'human default body' => [false, AiOrigin::Manipulated];
    }

    #[Test]
    #[DataProvider('bodies')]
    public function theRealCreationToolDistinguishesItsBodyFromADefault(
        bool $supplyBody,
        AiOrigin $expected,
    ): void {
        $pool = $this->getService(ConnectionPool::class);
        $pool
            ->getConnectionForTable('be_users')
            ->insert('be_users', ['uid' => 1, 'username' => 'writer', 'admin' => 1]);
        $pool
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'             => 1,
                    'title'           => 'Parent',
                    'doktype'         => 1,
                    'perms_everybody' => 31,
                ],
            );
        $user            = $this->setUpBackendUser(1);
        $user->workspace = 0;
        // @phpstan-ignore-next-line TYPO3 TCA configuration is mixed.
        $GLOBALS['TCA']['tt_content']['columns']['bodytext']['config']['default'] = 'Human-authored default body.';
        $this->getService(TcaSchemaFactory::class)->rebuild($GLOBALS['TCA']);
        $languages       = $this->getService(LanguageServiceFactory::class);
        $GLOBALS['LANG'] = $languages->create('default');
        $tool            = new CreateContentElementDraftTool(
            $pool,
            new ApprovalPreviewTranslator($languages),
        );
        $arguments = ['page' => 1, 'type' => 'text', 'header' => 'AI headline'];
        if ($supplyBody) {
            $arguments['bodytext'] = 'AI-generated body.';
        }

        $queue = [
            new CompletionResponse(
                '',
                'test-model',
                UsageStatistics::fromTokens(1, 1),
                toolCalls: [
                    new ToolCall(
                        'create',
                        'create_content_element_draft',
                        $arguments,
                    ),
                ],
            ),
            new CompletionResponse(
                'Done.',
                'test-model',
                UsageStatistics::fromTokens(1, 1),
            ),
        ];
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager
            ->method('chatWithToolsForConfiguration')
            ->willReturnCallback(
                static function () use (&$queue): CompletionResponse {
                    $response = array_shift($queue);
                    self::assertInstanceOf(CompletionResponse::class, $response);

                    return $response;
                },
            );
        $decision = new ToolPolicyDecision(
            toolName: 'create_content_element_draft',
            allowed: true,
            dataClass: ToolDataClass::PUBLIC_CONTENT,
            zone: TrustZone::LOCAL,
            ceiling: ToolDataClass::SECRET_ADJACENT,
            reason: ToolDenialReason::NONE,
        );
        $policy = self::createStub(ToolCallPolicyInterface::class);
        $policy
            ->method('skillAllowListForRun')
            ->willReturn(new SkillToolAllowList(null));
        $policy->method('decide')->willReturn($decision);
        $policy
            ->method('filterOfferable')
            ->willReturn(['create_content_element_draft']);
        $policy->method('explain')->willReturn([$decision]);
        $loop = new ToolLoopService(
            $manager,
            new ToolRegistry([$tool]),
            $policy,
            eventDispatcher: $this->getService(EventDispatcherInterface::class),
        );
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        $context = new ToolExecutionContext(
            AiActorContext::backendUser(1, true, workspaceId: 0),
            $user,
            new AgentRunReference(91, 'label-tool-run'),
        );
        $trace = new RunTrace();
        try {
            $loop->runLoop(
                [['role' => 'user', 'content' => 'Create text.']],
                $configuration,
                $context,
                null,
            );
            self::fail('A content creation must ask for approval.');
        } catch (ToolApprovalRequiredException $exception) {
            $loop->resume(
                $exception->state,
                true,
                $configuration,
                $context,
                null,
                $trace,
            );
        }

        $row = $pool
            ->getConnectionForTable('tt_content')
            ->executeQuery(
                'SELECT bodytext, tx_ailabel_metadata, tx_nrllm_ai_written FROM tt_content WHERE pid = ?',
                [1],
            )
            ->fetchAssociative();
        self::assertIsArray(
            $row,
            implode(
                '\n',
                array_map(
                    static fn (
                        RunStep $step,
                    ): string => (string) $step->toolResult,
                    $trace->getSteps(),
                ),
            ),
        );
        self::assertSame(
            $supplyBody ? 'AI-generated body.' : 'Human-authored default body.',
            $row['bodytext'],
        );
        self::assertSame(1, (int) $row['tx_nrllm_ai_written']);
        self::assertIsString($row['tx_ailabel_metadata']);
        self::assertSame(
            $expected,
            AiMetadata::fromJsonString($row['tx_ailabel_metadata'])->getOrigin(),
        );
    }
}
