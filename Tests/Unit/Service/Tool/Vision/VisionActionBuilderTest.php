<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Vision;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\Vision\ImageDownscalerInterface;
use Netresearch\NrLlm\Service\Tool\Vision\VisionActionBuilder;
use Netresearch\NrLlm\Service\Tool\Vision\VisionSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;

#[CoversClass(VisionActionBuilder::class)]
final class VisionActionBuilderTest extends TestCase
{
    private int $askedEdge = 0;

    #[Test]
    public function anImageTravelsAsAMessageOfItsOwnAtTheConfiguredSize(): void
    {
        $message = $this->builder($this->file(), ['maxImageEdge' => '640'])->imageMessage(7, $this->admin());

        self::assertSame(
            ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']]]],
            $message,
        );
        self::assertSame(640, $this->askedEdge);
    }

    #[Test]
    public function aFileThatDoesNotExistIsNull(): void
    {
        $factory = self::createStub(ResourceFactory::class);
        $factory->method('getFileObject')->willThrowException(new RuntimeException('no such file', 1798000011));

        self::assertNull($this->builder($this->file(), factory: $factory)->imageMessage(7, $this->admin()));
    }

    #[Test]
    public function aFileMissingFromTheStorageIsNull(): void
    {
        self::assertNull($this->builder($this->file(missing: true))->imageMessage(7, $this->admin()));
    }

    #[Test]
    public function aFormatNoProviderTakesIsNull(): void
    {
        self::assertNull($this->builder($this->file(extension: 'svg'))->imageMessage(7, $this->admin()));
        self::assertSame(0, $this->askedEdge);
    }

    #[Test]
    public function theExtensionIsComparedWithoutCase(): void
    {
        self::assertNotNull($this->builder($this->file(extension: 'JPG'))->imageMessage(7, $this->admin()));
    }

    #[Test]
    public function aFileOutsideTheStoragesTheUserReachesIsNull(): void
    {
        self::assertNull($this->builder($this->file(storage: 2))->imageMessage(7, $this->admin()));
        self::assertSame(0, $this->askedEdge);
    }

    #[Test]
    public function aFileInAnExcludedFolderIsNull(): void
    {
        self::assertNull($this->builder($this->file(), ['excludedFolders' => '1:/private/'])->imageMessage(7, $this->admin()));
        self::assertSame(0, $this->askedEdge);
    }

    #[Test]
    public function anImageThatCannotBeScaledIsNull(): void
    {
        self::assertNull($this->builder($this->file(), dataUri: null)->imageMessage(7, $this->admin()));
    }

    #[Test]
    public function anActionIsAvailableWhileOneOfItsFieldsIsOn(): void
    {
        $builder = $this->builder($this->file(), ['title' => ['enabled' => '0']]);

        self::assertTrue($builder->isAvailable($this->action(['title', 'description']), new LlmConfiguration()));
        self::assertFalse($builder->isAvailable($this->action(['title']), new LlmConfiguration()));
    }

    #[Test]
    public function theModelDecidesByWhatItDeclares(): void
    {
        $builder = $this->builder($this->file());

        self::assertFalse($builder->isAvailable($this->action(['title']), $this->configurationOn('chat,tools')));
        self::assertTrue($builder->isAvailable($this->action(['title']), $this->configurationOn('chat,tools,vision')));
        // An empty capability field is undeclared, as in model selection (ADR-138).
        self::assertTrue($builder->isAvailable($this->action(['title']), $this->configurationOn('')));
    }

    #[Test]
    public function thePromptNamesEachFieldThatIsOnWithItsLimit(): void
    {
        $lines = implode("\n", $this->builder($this->file(), [
            'title'       => ['enabled' => '0'],
            'description' => ['prompt' => 'Tell a story.', 'maxLength' => '100'],
        ])->promptLines($this->action(['title', 'description']), null));

        self::assertStringNotContainsString('"title"', $lines);
        self::assertStringContainsString('Field "description": Tell a story. Use at most 90 characters.', $lines);
    }

    #[Test]
    public function aFieldWithoutALimitNamesNone(): void
    {
        $lines = implode("\n", $this->builder($this->file())->promptLines($this->action(['title']), null));

        self::assertStringNotContainsString('at most', $lines);
    }

    #[Test]
    public function thePromptAsksForTheLanguageOfTheUser(): void
    {
        $user       = $this->admin();
        $user->user = ['lang' => 'de'];

        self::assertStringContainsString('"de"', implode("\n", $this->builder($this->file())->promptLines($this->action(['title']), $user)));

        $user->user = ['lang' => 'default'];

        self::assertStringNotContainsString('language', implode("\n", $this->builder($this->file())->promptLines($this->action(['title']), $user)));
    }

    /**
     * @param array<string, mixed> $vision
     */
    private function builder(File $file, array $vision = [], ?ResourceFactory $factory = null, ?string $dataUri = 'data:image/png;base64,AAAA'): VisionActionBuilder
    {
        if (!$factory instanceof ResourceFactory) {
            $factory = self::createStub(ResourceFactory::class);
            $factory->method('getFileObject')->willReturn($file);
        }

        $downscaler = self::createStub(ImageDownscalerInterface::class);
        $downscaler->method('dataUri')->willReturnCallback(function (File $file, int $edge) use ($dataUri): ?string {
            $this->askedEdge = $edge;

            return $dataUri;
        });

        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['vision' => $vision]);

        return new VisionActionBuilder(new FalStorageGate(), $factory, $downscaler, new VisionSettings($configuration));
    }

    private function file(bool $missing = false, string $extension = 'png', int $storage = 1): File
    {
        $resourceStorage = self::createStub(ResourceStorage::class);
        $resourceStorage->method('getUid')->willReturn($storage);

        $file = self::createStub(File::class);
        $file->method('isMissing')->willReturn($missing);
        $file->method('getExtension')->willReturn($extension);
        $file->method('getIdentifier')->willReturn('/private/a.' . $extension);
        $file->method('getCombinedIdentifier')->willReturn($storage . ':/private/a.' . $extension);
        $file->method('getStorage')->willReturn($resourceStorage);

        return $file;
    }

    private function admin(): BackendUserAuthentication
    {
        $user = self::createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);

        return $user;
    }

    /**
     * @param list<string> $fields
     */
    private function action(array $fields): EditorAction
    {
        return new EditorAction('LLL:a', 'LLL:b', 'icon', ['sys_file'], $fields);
    }

    private function configurationOn(string $capabilities): LlmConfiguration
    {
        $model = new Model();
        $model->setCapabilities($capabilities);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }
}
