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
 * The test walks the running registry rather than reading Configuration/Icons.php:
 * that covers icons core derives from TCA `ctrl.iconfile` as well, and each
 * TYPO3 line exercises its own branch of Icons.php (v13 keeps the fixed-colour
 * `.legacy.svg` module tiles as `<img>`, which is correct for them).
 */
#[CoversNothing]
final class CurrentColorIconMarkupTest extends AbstractFunctionalTestCase
{
    private const EXT_PREFIX = 'EXT:nr_llm/';

    /**
     * Provider, editor-action and record icons are currentColor on both lines
     * (6 + 9 + 6); v14 adds the ten module icons. A lower count means the walk
     * stopped seeing icons, and a green run would prove nothing.
     */
    private const MIN_CURRENT_COLOR_ICONS = 21;

    #[Test]
    public function everyCurrentColorIconRendersMarkupThatInheritsTheTextColour(): void
    {
        $registry = $this->getService(IconRegistry::class);
        $factory  = $this->getService(IconFactory::class);
        $checked  = [];

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

            $checked[] = $identifier;

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

        self::assertGreaterThanOrEqual(
            self::MIN_CURRENT_COLOR_ICONS,
            count($checked),
            'Only ' . count($checked) . ' currentColor icons were found: ' . implode(', ', $checked),
        );
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
