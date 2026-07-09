<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\GetSiteConfigTool;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for GetSiteConfigTool (ADR-048): the site listing, the
 * flattened per-site configuration and the credential redaction (site
 * settings routinely carry API keys, camelCase included).
 */
#[CoversClass(GetSiteConfigTool::class)]
final class GetSiteConfigToolTest extends AbstractFunctionalTestCase
{
    private ToolInterface $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $siteDir = $this->instancePath . '/typo3conf/sites/diag';
        GeneralUtility::mkdir_deep($siteDir);
        file_put_contents($siteDir . '/config.yaml', <<<YAML
            rootPageId: 1
            base: 'http://localhost:59999/'
            settings:
              maps:
                apiKey: 'super-secret-value-123'
                zoom: 12
            languages:
              - languageId: 0
                title: English
                locale: en_US.UTF-8
                base: /
            YAML);

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('get_site_config');
        self::assertInstanceOf(ToolInterface::class, $tool);
        $this->tool = $tool;
    }

    #[Test]
    public function listsAllSitesWithoutIdentifier(): void
    {
        $output = $this->tool->execute([]);

        self::assertStringContainsString('Configured sites (', $output);
        self::assertStringContainsString('- diag (base http://localhost:59999/, root page 1)', $output);
    }

    #[Test]
    public function flattensConfigurationAndRedactsCredentialKeys(): void
    {
        $output = $this->tool->execute(['identifier' => 'diag']);

        self::assertStringContainsString('rootPageId: 1', $output);
        self::assertStringContainsString('languages.0.locale: en_US.UTF-8', $output);
        // The credential KEY is visible, its camelCase VALUE is not.
        self::assertStringContainsString('settings.maps.apiKey: [redacted]', $output);
        self::assertStringNotContainsString('super-secret-value-123', $output);
        self::assertStringContainsString('settings.maps.zoom: 12', $output);
    }

    #[Test]
    public function unknownIdentifierIsReported(): void
    {
        self::assertStringContainsString(
            'No site "nope"',
            $this->tool->execute(['identifier' => 'nope']),
        );
    }
}
