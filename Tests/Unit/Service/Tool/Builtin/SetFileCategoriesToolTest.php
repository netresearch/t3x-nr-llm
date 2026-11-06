<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileCategoriesTool;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\Vision\CategoryVocabulary;
use Netresearch\NrLlm\Service\Tool\Vision\VisionSettings;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * What the categories writer refuses from its arguments alone, before it
 * reads a category or a file (ADR-268). The database half is functional:
 * `Tests/Functional/Service/Tool/SetFileCategoriesToolTest`.
 */
#[CoversClass(SetFileCategoriesTool::class)]
final class SetFileCategoriesToolTest extends AbstractUnitTestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedCalls(): array
    {
        return [
            'no list'          => [['uid' => 3], 'must be a list of sys_category uids'],
            'a string'         => [['uid' => 3, 'categories' => '4,5'], 'must be a list of sys_category uids'],
            'a zero'           => [['uid' => 3, 'categories' => [0]], 'positive sys_category uid'],
            'a negative'       => [['uid' => 3, 'categories' => [-4]], 'positive sys_category uid'],
            'a fraction'       => [['uid' => 3, 'categories' => ['1.9']], 'positive sys_category uid'],
            'a title'          => [['uid' => 3, 'categories' => ['Dogs']], 'positive sys_category uid'],
            'an unknown field' => [['uid' => 3, 'categories' => [4], 'title' => 'x'], '"title" is not an argument of this tool'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('malformedCalls')]
    public function aMalformedCallIsRefusedBeforeTheCard(array $arguments, string $reason): void
    {
        $refusal = $this->tool()->refusalBeforeApproval($arguments, ToolExecutionContext::fromBackendUser($this->user()));

        self::assertNotNull($refusal);
        self::assertTrue($refusal->isError);
        self::assertStringContainsString($reason, $refusal->content);
    }

    /**
     * Without an acting user the answer is not known here; the write refuses
     * it with the neutral string.
     */
    #[Test]
    public function withoutAnActingUserTheRefusalIsLeftToTheWrite(): void
    {
        self::assertNull($this->tool()->refusalBeforeApproval(['uid' => 3, 'categories' => []], ToolExecutionContext::none()));
    }

    #[Test]
    public function withoutARootAWellFormedCallIsRefused(): void
    {
        $refusal = $this->tool()->refusalBeforeApproval(['uid' => 3, 'categories' => [4]], ToolExecutionContext::fromBackendUser($this->user()));

        self::assertNotNull($refusal);
        self::assertStringContainsString('vision.categories.rootUid', $refusal->content);
    }

    #[Test]
    public function itIsAnOffByDefaultEditingWriteForNonAdminsOnFiles(): void
    {
        $tool   = $this->tool();
        $action = $tool->getEditorAction();

        self::assertSame('set_file_categories', $tool->getSpec()->name);
        self::assertSame(ToolEffect::IDEMPOTENT_WRITE, $tool->getEffect());
        self::assertFalse($tool->isEnabledByDefault());
        self::assertFalse($tool->requiresAdmin());
        self::assertSame('editing', $tool->getGroup());
        self::assertSame(['sys_file'], $action->recordTypes);
        self::assertSame([VisionSettings::CATEGORIES], $action->visionFields);
        self::assertSame(['table' => 'sys_file', 'uid' => 3, 'fields' => ['categories']], $tool->pendingTarget(['uid' => 3, 'categories' => [4]])?->toArray());
    }

    private function tool(): SetFileCategoriesTool
    {
        $connectionPool = self::createStub(ConnectionPool::class);

        return new SetFileCategoriesTool(
            $connectionPool,
            new FalStorageGate(),
            new ApprovalPreviewTranslator(self::createStub(LanguageServiceFactory::class)),
            new CategoryVocabulary($connectionPool, new VisionSettings()),
        );
    }

    private function user(): BackendUserAuthentication
    {
        $user = self::createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $user->user = ['uid' => 2];

        return $user;
    }
}
