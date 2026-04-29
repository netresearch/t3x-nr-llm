<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VisionContent::class)]
final class VisionContentTest extends TestCase
{
    #[Test]
    public function textFactoryProducesTextContent(): void
    {
        $vc = VisionContent::text('describe this image');

        self::assertSame(VisionContent::TYPE_TEXT, $vc->type);
        self::assertSame('describe this image', $vc->text);
        self::assertNull($vc->imageUrl);
        self::assertTrue($vc->isText());
        self::assertFalse($vc->isImage());
    }

    #[Test]
    public function imageUrlFactoryProducesImageContent(): void
    {
        $vc = VisionContent::imageUrl('https://example.com/cat.jpg');

        self::assertSame(VisionContent::TYPE_IMAGE_URL, $vc->type);
        self::assertNull($vc->text);
        self::assertSame('https://example.com/cat.jpg', $vc->imageUrl);
        self::assertTrue($vc->isImage());
        self::assertFalse($vc->isText());
    }

    #[Test]
    public function imageUrlAcceptsDataUri(): void
    {
        $dataUri = 'data:image/png;base64,iVBORw0KGgo=';

        $vc = VisionContent::imageUrl($dataUri);

        self::assertSame($dataUri, $vc->imageUrl);
    }

    #[Test]
    public function constructorRejectsUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745420001);

        new VisionContent(type: 'video', text: 'x');
    }

    #[Test]
    public function constructorRejectsEmptyTextPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745420002);

        new VisionContent(type: VisionContent::TYPE_TEXT, text: '');
    }

    #[Test]
    public function constructorRejectsMissingTextPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745420002);

        new VisionContent(type: VisionContent::TYPE_TEXT);
    }

    #[Test]
    public function constructorRejectsEmptyImageUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745420003);

        new VisionContent(type: VisionContent::TYPE_IMAGE_URL, imageUrl: '');
    }

    #[Test]
    public function constructorRejectsMissingImageUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745420003);

        new VisionContent(type: VisionContent::TYPE_IMAGE_URL);
    }

    #[Test]
    public function fromArrayReadsTextWireShape(): void
    {
        $vc = VisionContent::fromArray([
            'type' => 'text',
            'text' => 'caption this',
        ]);

        self::assertSame(VisionContent::TYPE_TEXT, $vc->type);
        self::assertSame('caption this', $vc->text);
    }

    #[Test]
    public function fromArrayReadsCanonicalImageUrlWireShape(): void
    {
        $vc = VisionContent::fromArray([
            'type'      => 'image_url',
            'image_url' => ['url' => 'https://example.com/x.png'],
        ]);

        self::assertSame(VisionContent::TYPE_IMAGE_URL, $vc->type);
        self::assertSame('https://example.com/x.png', $vc->imageUrl);
    }

    #[Test]
    public function fromArrayAcceptsLegacyFlatImageUrlShape(): void
    {
        // Some integrations emit `image_url: '<url>'` without the
        // `{url: ...}` envelope. The factory normalises it.
        $vc = VisionContent::fromArray([
            'type'      => 'image_url',
            'image_url' => 'https://example.com/y.png',
        ]);

        self::assertSame('https://example.com/y.png', $vc->imageUrl);
    }

    #[Test]
    public function fromArrayPropagatesEmptyTextThroughInvariant(): void
    {
        // Defensive: if a misbehaving provider sends `{type:'text', text:''}`,
        // the factory must not silently produce an empty-string item — the
        // constructor invariant catches it.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1745420002);

        VisionContent::fromArray(['type' => 'text', 'text' => '']);
    }

    #[Test]
    public function toArrayProducesIdempotentTextShape(): void
    {
        $vc = VisionContent::text('hello');

        self::assertSame(['type' => 'text', 'text' => 'hello'], $vc->toArray());
    }

    #[Test]
    public function toArrayProducesIdempotentImageShape(): void
    {
        $vc = VisionContent::imageUrl('https://example.com/z.png');

        self::assertSame(
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/z.png']],
            $vc->toArray(),
        );
    }

    #[Test]
    public function fromArrayAndToArrayRoundTripText(): void
    {
        $shape = ['type' => 'text', 'text' => 'roundtrip'];

        self::assertSame($shape, VisionContent::fromArray($shape)->toArray());
    }

    #[Test]
    public function fromArrayAndToArrayRoundTripImage(): void
    {
        $shape = ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/r.png']];

        self::assertSame($shape, VisionContent::fromArray($shape)->toArray());
    }

    #[Test]
    public function jsonSerializeMatchesToArray(): void
    {
        $vc = VisionContent::text('hi');

        self::assertSame($vc->toArray(), $vc->jsonSerialize());
    }
}
