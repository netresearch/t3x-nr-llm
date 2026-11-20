<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The approved page metadata write of {@see A2aPageMetadataTranslatedPageTest}
 * with AI Label installed, as on the demo (#1432). AI Label stores its metadata
 * on the page's draft with a DataHandler run of its own, which runs under the
 * scope of the call (#1438). TYPO3 14 adds an entry for the page's German
 * translation to that run, as it does for any write of the page in a
 * workspace; the scope admits the default language only, so the entry is left
 * out as it is for the skill's own write, and the translation gets no version.
 * The `writes` artifact names every record the client's workspace holds after
 * the run.
 */
#[CoversClass(ToolLoopService::class)]
#[CoversClass(A2aTaskStore::class)]
final class A2aPageMetadataAiLabelTranslatedPageTest extends AbstractFunctionalTestCase
{
    use A2aPageMetadataApprovedWrite;
    use A2aPageMetadataSkillFixture;
    use RegistersTheFailingHookTrait;

    protected array $testExtensionsToLoad = ['netresearch/nr-vault', 'b13/ai-label', 'netresearch/nr-llm'];

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSkill();
    }

    protected function tearDown(): void
    {
        $this->tearDownSkill();
        parent::tearDown();
    }

    #[Test]
    public function everyRecordTheRunVersionedIsNamedInTheWritesArtifact(): void
    {
        [, $taskId] = $this->approvedWrite(fields: 'pages:description,pages:tx_ailabel_metadata');

        self::assertSame('Eine kurze Beschreibung.', $this->draftOf(self::PAGE));
        $label = $this->db('pages')->select(['tx_ailabel_metadata'], 'pages', ['t3ver_oid' => self::PAGE, 't3ver_wsid' => self::WORKSPACE])->fetchOne();
        self::assertSame(AiOrigin::Manipulated, AiMetadata::fromJsonString(is_string($label) ? $label : null)->getOrigin(), 'AI Label stored its metadata on the draft');

        $versioned = $this->versionedPages();
        // TYPO3 13.4 adds no entry for the translations to a write that names
        // the page's version (measured on 13.4.40); 14.3 adds one, and the
        // scope leaves it out.
        self::assertSame([self::PAGE], $versioned, "the translation got no version in AI Label's write");

        $writes = $this->writesOf($taskId);
        foreach ($versioned as $uid) {
            self::assertMatchesRegularExpression('/^- pages:' . $uid . ' in workspace ' . self::WORKSPACE . ' \((created|updated)\)$/m', $writes, 'pages:' . $uid . ' is named');
        }

        self::assertStringNotContainsString('this list may be incomplete', $writes);
        self::assertStringContainsString("Writes executed (1), each in the workspace named (0 is live):\n- pages:" . self::PAGE . ' in workspace ' . self::WORKSPACE . ' (updated)', $writes);
        self::assertSame(1, substr_count($writes, '- pages:' . self::PAGE . ' '), 'the page is named once');
        self::assertStringNotContainsString('Further records', $writes);
    }
}
