<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Vision;

use Netresearch\NrLlm\Service\Tool\Vision\FalImageDownscaler;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * TYPO3's own processing against a real file: the bytes that would be sent are
 * an image of the configured size (ADR-235).
 */
#[CoversClass(FalImageDownscaler::class)]
final class FalImageDownscalerFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function aLargeImageLeavesAtTheConfiguredSize(): void
    {
        $image = imagecreatetruecolor(320, 200);
        self::assertNotFalse($image);
        $path = Environment::getPublicPath() . '/fileadmin/vision-large.png';
        self::assertTrue(is_dir(dirname($path)) || mkdir(dirname($path), 0o775, true));
        self::assertTrue(imagepng($image, $path));

        $factory = $this->getService(ResourceFactory::class);
        $file    = $factory->getFileObjectFromCombinedIdentifier('1:/vision-large.png');
        self::assertInstanceOf(File::class, $file);

        $dataUri = (new FalImageDownscaler())->dataUri($file, 32);

        self::assertNotNull($dataUri);
        self::assertStringStartsWith('data:image/png;base64,', $dataUri);
        $bytes = base64_decode(substr($dataUri, strlen('data:image/png;base64,')), true);
        self::assertIsString($bytes);
        $size = getimagesizefromstring($bytes);
        self::assertNotFalse($size);
        self::assertSame(32, $size[0]);
        self::assertSame(20, $size[1]);
    }
}
