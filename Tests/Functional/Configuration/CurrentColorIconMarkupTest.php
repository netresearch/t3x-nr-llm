<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Configuration;

use DOMDocument;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgSpriteIconProvider;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Every nr-llm icon drawn in currentColor renders as markup that inherits the
 * backend's text colour.
 *
 * `SvgIconProvider` renders `<img src="….svg">`. An image document resolves
 * currentColor against its own initial colour, which is black, so in the dark
 * backend scheme such an icon paints near-black on a dark surface — the module
 * cards, headings and editor-action lists did exactly that. The fix registers
 * them with `SvgSpriteIconProvider` (`<svg><use xlink:href="file.svg#id">`),
 * which is how core draws its own icons.
 *
 * The expected icons are pinned by identifier, so an icon whose file stops
 * drawing in currentColor fails here instead of dropping out of the check. The
 * test also walks the running registry: any other nr-llm icon drawn in
 * currentColor (including one core derives from TCA `ctrl.iconfile`) fails
 * until it is registered as a sprite and added to the list. Each TYPO3 line
 * exercises its own branch of Icons.php (v13 keeps the fixed-colour
 * `.legacy.svg` module tiles as `<img>`, which is correct for them).
 */
#[CoversNothing]
final class CurrentColorIconMarkupTest extends AbstractFunctionalTestCase
{
    private const EXT_PREFIX = 'EXT:nr_llm/';

    /** Drawn in currentColor on both TYPO3 lines: records, providers, editor actions. */
    private const CURRENT_COLOR_ICONS = [
        'nrllm-record-model', 'nrllm-record-mcp-server', 'nrllm-record-provider',
        'nrllm-record-skill', 'nrllm-record-snippet', 'nrllm-record-task',
        'nrllm-provider-openai', 'nrllm-provider-claude', 'nrllm-provider-gemini',
        'nrllm-provider-openrouter', 'nrllm-provider-mistral', 'nrllm-provider-groq',
        'nrllm-editor-action-page-metadata', 'nrllm-editor-action-file-alt-text',
        'nrllm-editor-action-file-meta', 'nrllm-editor-action-attach-file',
        'nrllm-editor-action-page-social-image', 'nrllm-editor-action-move-content',
        'nrllm-editor-action-create-content', 'nrllm-editor-action-create-page',
        'nrllm-editor-action-create-translation',
    ];

    /** Drawn in currentColor on v14 only; v13 uses the `.legacy.svg` tiles. */
    private const V14_MODULE_ICONS = [
        'module-nrllm', 'module-nrllm-provider', 'module-nrllm-model', 'module-nrllm-wizard',
        'module-nrllm-task', 'module-nrllm-snippet', 'module-nrllm-analytics', 'module-nrllm-runs',
        'module-nrllm-skill', 'module-nrllm-tool',
    ];

    #[Test]
    public function everyCurrentColorIconRendersMarkupThatInheritsTheTextColour(): void
    {
        $registry = $this->getService(IconRegistry::class);
        $factory  = $this->getService(IconFactory::class);
        $expected = (new Typo3Version())->getMajorVersion() >= 14
            ? [...self::CURRENT_COLOR_ICONS, ...self::V14_MODULE_ICONS]
            : self::CURRENT_COLOR_ICONS;

        foreach ($expected as $identifier) {
            self::assertTrue($registry->isRegistered($identifier), sprintf('Icon "%s" is not registered.', $identifier));
            $configuration = $registry->getIconConfigurationByIdentifier($identifier);
            self::assertIsArray($configuration);
            $options = $configuration['options'] ?? null;
            self::assertIsArray($options, sprintf('Icon "%s" has no options.', $identifier));
            $source = $options['source'] ?? null;
            self::assertIsString($source, sprintf('Icon "%s" has no source.', $identifier));
            $svg = file_get_contents($this->absolutePath($source));
            self::assertIsString($svg, sprintf('Icon "%s" points at an unreadable file %s.', $identifier, $source));
            self::assertStringContainsString('currentColor', $svg, sprintf('%s no longer draws in currentColor; icons follow the text colour (Resources/AGENTS.md).', $source));
            $this->assertRendersAsSprite($factory, $identifier, $configuration, $source, $svg);
        }

        foreach ($registry->getAllRegisteredIconIdentifiers() as $identifier) {
            self::assertIsString($identifier);
            $configuration = $registry->getIconConfigurationByIdentifier($identifier);
            self::assertIsArray($configuration);
            $options = $configuration['options'] ?? null;
            if (!is_array($options)) {
                continue;
            }

            $source = $options['source'] ?? null;
            if (!is_string($source) || !str_starts_with($source, self::EXT_PREFIX)) {
                continue;
            }

            $svg = file_get_contents($this->absolutePath($source));
            self::assertIsString($svg, sprintf('Icon "%s" points at an unreadable file %s.', $identifier, $source));
            if (!str_contains($svg, 'currentColor')) {
                continue;
            }

            self::assertContains(
                $identifier,
                $expected,
                sprintf('Icon "%s" draws in currentColor; register it with SvgSpriteIconProvider and add it to the list in this test.', $identifier),
            );
        }
    }

    /**
     * @param array<mixed> $configuration
     */
    private function assertRendersAsSprite(IconFactory $factory, string $identifier, array $configuration, string $source, string $svg): void
    {
        $options = $configuration['options'] ?? [];
        self::assertIsArray($options);
        self::assertSame(
            SvgSpriteIconProvider::class,
            $configuration['provider'] ?? null,
            sprintf('Icon "%s" draws in currentColor but is not registered with SvgSpriteIconProvider; it would render as <img> and paint black in the dark scheme.', $identifier),
        );

        $rootId = $this->rootId($svg);
        self::assertNotSame('', $rootId, sprintf('%s has no id on its root <svg>, so a sprite reference cannot address it.', $source));
        self::assertSame($source . '#' . $rootId, $options['sprite'] ?? null, sprintf('Icon "%s" must reference the root id of its own source file.', $identifier));

        $icon   = $factory->getIcon($identifier, IconSize::MEDIUM);
        $markup = $icon->getMarkup();
        self::assertStringNotContainsString('<img', $markup, sprintf('Icon "%s" renders as <img>.', $identifier));
        self::assertMatchesRegularExpression(
            '/<svg class="icon-color"><use xlink:href="[^"]+#' . preg_quote($rootId, '/') . '" ?\/><\/svg>/',
            $markup,
            sprintf('Icon "%s" does not render the sprite reference.', $identifier),
        );
        self::assertStringContainsString('currentColor', $icon->getAlternativeMarkup('inline'), sprintf('Inline markup of "%s" lost its currentColor paths.', $identifier));
    }

    private function absolutePath(string $extPath): string
    {
        return dirname(__DIR__, 3) . '/' . substr($extPath, strlen(self::EXT_PREFIX));
    }

    private function rootId(string $svg): string
    {
        $document = new DOMDocument();
        self::assertTrue($document->loadXML($svg));
        self::assertNotNull($document->documentElement);

        return $document->documentElement->getAttribute('id');
    }
}
