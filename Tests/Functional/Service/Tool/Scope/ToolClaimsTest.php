<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ClearCacheTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CopyRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreatePageDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateRecordDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateRedirectTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateTranslationDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\DeleteRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\DisableRedirectTool;
use Netresearch\NrLlm\Service\Tool\Builtin\MoveContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\MovePageTool;
use Netresearch\NrLlm\Service\Tool\Builtin\PublishRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReplaceFileReferenceTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileCategoriesTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileReferenceAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetPageSocialImageTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateFalAssetMetaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateRedirectTool;
use Netresearch\NrLlm\Service\Tool\RemoteApprovalInterface;
use Netresearch\NrLlm\Service\Tool\RemoteToolInterface;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedDestination;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWrite;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWriteInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the built-in write tools say they will touch (ADR-295), read from the
 * real tools of the container. The first layer is only as good as these
 * statements: a tool that names the wrong record lets a call through.
 */
#[CoversNothing]
final class ToolClaimsTest extends AbstractFunctionalTestCase
{
    /**
     * A write tool that can be held to a scope states its targets. One that
     * changes things outside TYPO3 cannot, and is refused while a scope applies.
     */
    #[Test]
    public function everyWriteToolOfTheRegistryStatesItsTargetsOrIsRemote(): void
    {
        $registry = $this->getService(ToolRegistry::class);
        $effects  = new ToolEffectResolver($registry);

        $missing = [];
        $writers = 0;
        foreach ($registry->names() as $name) {
            $tool = $registry->get($name);
            if (!$tool instanceof ToolInterface || !$effects->effectFor($name)->isWrite()) {
                continue;
            }

            ++$writers;

            if ($tool instanceof RemoteToolInterface || $tool instanceof RemoteApprovalInterface || $tool instanceof ScopedWriteInterface) {
                continue;
            }

            $missing[] = $name . ' (' . $tool::class . ')';
        }

        self::assertGreaterThan(15, $writers, 'the registry holds the built-in write tools, so the check below looked at something');
        self::assertSame([], $missing, 'write tools that state no targets and so are refused under every scope');
    }

    /**
     * @return iterable<string, array{class-string<ScopedWriteInterface>, array<string, mixed>, string|null}>
     */
    public static function claims(): iterable
    {
        // operation | records | destinations (table@pid) | reaches subpages | language
        yield 'copy a page' => [CopyRecordTool::class, ['table' => 'pages', 'uid' => 5, 'target_page' => 2], 'copy|pages:5|pages@2||'];
        yield 'copy content behind another element' => [CopyRecordTool::class, ['table' => 'tt_content', 'uid' => 5, 'target_page' => 2, 'after_uid' => '9'], 'copy|tt_content:5,tt_content:9|tt_content@2||'];
        yield 'copy with a target that is no page' => [CopyRecordTool::class, ['table' => 'pages', 'uid' => 5, 'target_page' => 'two'], null];
        yield 'create content' => [CreateContentElementDraftTool::class, ['page' => 4, 'type' => 'text', 'language' => 1], 'create||tt_content@4||1'];
        yield 'create content behind an element' => [CreateContentElementDraftTool::class, ['page' => 4, 'after_content_uid' => 8], 'create|tt_content:8|tt_content@4||0'];
        yield 'create content with an anchor that is no uid' => [CreateContentElementDraftTool::class, ['page' => 4, 'after_content_uid' => 'x'], null];
        yield 'create a page' => [CreatePageDraftTool::class, ['parent' => 4, 'title' => 'T'], 'create||pages@4||0'];
        yield 'create a page behind another' => [CreatePageDraftTool::class, ['parent' => 4, 'title' => 'T', 'after_page_uid' => 6], 'create|pages:6|pages@4||0'];
        yield 'create a page at the root' => [CreatePageDraftTool::class, ['parent' => 0, 'title' => 'T'], 'create||pages@0||0'];
        yield 'create a record' => [CreateRecordDraftTool::class, ['table' => 'tx_news_domain_model_news', 'pid' => 12, 'fields' => []], 'create||tx_news_domain_model_news@12||'];
        yield 'create a record in a table that is no identifier' => [CreateRecordDraftTool::class, ['table' => 'pages; DROP', 'pid' => 12], null];
        yield 'translate' => [CreateTranslationDraftTool::class, ['table' => 'tt_content', 'uid' => 7, 'language' => 2], 'translate|tt_content:7|||2'];
        yield 'translate without a language' => [CreateTranslationDraftTool::class, ['table' => 'tt_content', 'uid' => 7], null];
        yield 'delete a page' => [DeleteRecordTool::class, ['table' => 'pages', 'uid' => 7], 'delete|pages:7||subpages|'];
        yield 'delete content' => [DeleteRecordTool::class, ['table' => 'tt_content', 'uid' => '7'], 'delete|tt_content:7|||'];
        yield 'move a page under a parent' => [MovePageTool::class, ['uid' => 7, 'parent' => 2], 'move|pages:7|pages@2|subpages|'];
        yield 'move a page behind another' => [MovePageTool::class, ['uid' => 7, 'after_page_uid' => 9], 'move|pages:7,pages:9||subpages|'];
        yield 'move a page to nowhere readable' => [MovePageTool::class, ['uid' => 7, 'parent' => 'x'], null];
        yield 'move content' => [MoveContentElementTool::class, ['uid' => 7, 'target_page' => 2, 'after_content_uid' => 3], 'move|tt_content:7,tt_content:3|tt_content@2||'];
        yield 'update content' => [UpdateContentElementTool::class, ['uid' => 7, 'fields' => []], 'update|tt_content:7|||'];
        yield 'update a page' => [UpdatePageMetadataTool::class, ['uid' => 7], 'update|pages:7|||'];
        yield 'update without a uid' => [UpdatePageMetadataTool::class, ['title' => 'x'], null];
        yield 'publish' => [PublishRecordTool::class, ['table' => 'pages', 'uid' => 3], 'publish|pages:3|||'];
        yield 'attach a file to a record' => [AttachFileToRecordTool::class, ['table' => 'tx_news_domain_model_news', 'record' => 4, 'file' => 9], 'attach|tx_news_domain_model_news:4|||'];
        yield 'attach a file to content' => [AttachFileToContentElementTool::class, ['content_element' => 4, 'file' => 9], 'attach|tt_content:4|||'];
        yield 'set the social image of a page' => [SetPageSocialImageTool::class, ['page' => 4, 'file' => 9], 'update|pages:4|||'];
        yield 'replace a file reference' => [ReplaceFileReferenceTool::class, ['reference' => 15, 'action' => 'remove'], 'update|sys_file_reference:15|||'];
        yield 'alternative text of a reference' => [SetFileReferenceAlternativeTextTool::class, ['reference' => 15], 'update|sys_file_reference:15|||'];
        yield 'alternative text of a file' => [SetFileAlternativeTextTool::class, ['uid' => 9], 'update|sys_file:9|||'];
        yield 'metadata of a file' => [UpdateFalAssetMetaTool::class, ['uid' => 9], 'update|sys_file:9|||'];
        yield 'categories of a file' => [SetFileCategoriesTool::class, ['uid' => 9], 'update|sys_file:9|||'];
        yield 'clear a cache' => [ClearCacheTool::class, ['group' => 'pages'], 'clear_cache||||'];
        yield 'create a redirect' => [CreateRedirectTool::class, ['source_path' => '/a'], 'create||sys_redirect@0||'];
        yield 'update a redirect' => [UpdateRedirectTool::class, ['uid' => 3], 'update|sys_redirect:3|||'];
        yield 'disable a redirect' => [DisableRedirectTool::class, ['uid' => 3], 'update|sys_redirect:3|||'];
    }

    /**
     * @param class-string<ScopedWriteInterface> $class
     * @param array<string, mixed>               $arguments
     */
    #[Test]
    #[DataProvider('claims')]
    public function aToolStatesWhatItsCallTouches(string $class, array $arguments, ?string $expected): void
    {
        $tool = $this->get($class);
        self::assertInstanceOf(ScopedWriteInterface::class, $tool);

        $claim = $tool->scopedWrite($arguments);

        self::assertSame($expected, $claim instanceof ScopedWrite ? $this->summary($claim) : null);
    }

    private function summary(ScopedWrite $claim): string
    {
        return implode('|', [
            $claim->operation,
            implode(',', array_map(static fn (RecordReference $record): string => (string) $record, $claim->records)),
            implode(',', array_map(static fn (ScopedDestination $destination): string => $destination->table . '@' . $destination->pid, $claim->destinations)),
            $claim->reachesSubpages ? 'subpages' : '',
            (string) $claim->language,
        ]);
    }
}
