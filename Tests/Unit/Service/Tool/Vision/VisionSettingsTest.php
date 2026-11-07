<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Vision;

use Netresearch\NrLlm\Service\Tool\Vision\VisionField;
use Netresearch\NrLlm\Service\Tool\Vision\VisionSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(VisionSettings::class)]
#[CoversClass(VisionField::class)]
final class VisionSettingsTest extends TestCase
{
    #[Test]
    public function aFreshInstallationHasEveryFieldOnWithAPromptAndNoLimitOfItsOwn(): void
    {
        $settings = new VisionSettings();

        foreach (['alternative', 'title', 'description'] as $name) {
            $field = $settings->field($name);
            self::assertTrue($field->enabled, $name);
            self::assertSame(0, $field->maxLength, $name);
            self::assertNotSame('', $field->prompt, $name);
        }

        self::assertSame(1024, $settings->maxImageEdge());
        self::assertFalse($settings->isExcluded('1:/user_upload/a.jpg'));
    }

    /**
     * Categories are chosen from a tree an operator names (ADR-268): without
     * its root the field is off, however it is switched.
     */
    #[Test]
    public function theCategoriesFieldIsOnOnlyWithARoot(): void
    {
        self::assertFalse((new VisionSettings())->field('categories')->enabled);
        self::assertSame(0, (new VisionSettings())->categoryRoot());
        self::assertFalse($this->settings(['categories' => ['rootUid' => '0', 'enabled' => '1']])->field('categories')->enabled);
        self::assertFalse($this->settings(['categories' => ['rootUid' => 'abc']])->field('categories')->enabled);

        $configured = $this->settings(['categories' => ['rootUid' => '12']]);
        self::assertSame(12, $configured->categoryRoot());
        self::assertTrue($configured->field('categories')->enabled);
        self::assertNotSame('', $configured->field('categories')->prompt);
        self::assertFalse($this->settings(['categories' => ['rootUid' => '12', 'enabled' => '0']])->field('categories')->enabled);
        // The root decides about this field only.
        self::assertTrue($this->settings(['categories' => ['rootUid' => '0']])->field('title')->enabled);
    }

    #[Test]
    public function aFieldCanBeSwitchedOffShortenedAndGivenItsOwnPrompt(): void
    {
        $field = $this->settings(['alternative' => ['enabled' => '0', 'maxLength' => '80', 'prompt' => ' Say what you see. ']])->field('alternative');

        self::assertFalse($field->enabled);
        self::assertSame(80, $field->maxLength);
        self::assertSame('Say what you see.', $field->prompt);
    }

    /**
     * The form stores a boolean as a string, and the file default as an int.
     *
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function enabledValues(): array
    {
        return [
            'string on'  => ['1', true],
            'string off' => ['0', false],
            'int on'     => [1, true],
            'int off'    => [0, false],
            'bool on'    => [true, true],
            'bool off'   => [false, false],
        ];
    }

    #[Test]
    #[DataProvider('enabledValues')]
    public function theSwitchReadsEveryFormOfABoolean(mixed $value, bool $expected): void
    {
        self::assertSame($expected, $this->settings(['title' => ['enabled' => $value]])->field('title')->enabled);
    }

    #[Test]
    public function aLengthThatIsNotPositiveSetsNoLimit(): void
    {
        self::assertSame(0, $this->settings(['title' => ['maxLength' => '0']])->field('title')->maxLength);
        self::assertSame(0, $this->settings(['title' => ['maxLength' => 'many']])->field('title')->maxLength);
        self::assertSame(0, $this->settings(['title' => ['maxLength' => '-1']])->field('title')->maxLength);
    }

    #[Test]
    public function anEmptyPromptKeepsTheShippedOne(): void
    {
        self::assertSame((new VisionSettings())->field('title')->prompt, $this->settings(['title' => ['prompt' => '  ']])->field('title')->prompt);
    }

    #[Test]
    public function theModelIsAskedForLessThanTheLimit(): void
    {
        self::assertSame(135, (new VisionField('alternative', true, 150, ''))->targetLength());
    }

    #[Test]
    public function anUnusableImageEdgeKeepsTheDefault(): void
    {
        self::assertSame(640, $this->settings(['maxImageEdge' => '640'])->maxImageEdge());
        self::assertSame(1024, $this->settings(['maxImageEdge' => '0'])->maxImageEdge());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function exclusions(): array
    {
        return [
            'in the folder'            => ['1:/private/', '1:/private/a.jpg', true],
            'in a subfolder'           => ['1:/private/', '1:/private/2026/a.jpg', true],
            'no trailing slash'        => ['1:/private', '1:/private/a.jpg', true],
            'a name that begins alike' => ['1:/priv', '1:/private/a.jpg', false],
            'another storage'          => ['1:/private/', '2:/private/a.jpg', false],
            'second entry'             => ['1:/a/, 1:/private/', '1:/private/a.jpg', true],
            'empty list'               => ['', '1:/private/a.jpg', false],
        ];
    }

    #[Test]
    #[DataProvider('exclusions')]
    public function aFolderExcludesWhatLiesInIt(string $excluded, string $file, bool $expected): void
    {
        self::assertSame($expected, $this->settings(['excludedFolders' => $excluded])->isExcluded($file));
    }

    #[Test]
    public function aConfigurationThatCannotBeReadCountsAsNotConfigured(): void
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new ExtensionConfigurationExtensionNotConfiguredException('none', 1798000010));

        self::assertSame(1024, (new VisionSettings($configuration))->maxImageEdge());
    }

    /**
     * @param array<string, mixed> $vision
     */
    private function settings(array $vision): VisionSettings
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['vision' => $vision]);

        return new VisionSettings($configuration);
    }
}
