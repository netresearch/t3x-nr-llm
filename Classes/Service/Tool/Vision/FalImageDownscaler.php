<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Vision;

use finfo;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\FileProcessingAspect;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Downscales through TYPO3's file processing, so the result is cached in the
 * storage's processed folder and an image is scaled once however many runs ask.
 *
 * Processing that fails hands back the original file without an error. The
 * result is therefore measured rather than trusted, and an image that is still
 * too large is not sent.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class FalImageDownscaler implements ImageDownscalerInterface
{
    /** The formats every vision provider of this extension accepts. */
    private const SUPPORTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private ?LoggerInterface $logger = null,
        private ?Context $context = null,
    ) {}

    public function dataUri(File $file, int $maxEdge): ?string
    {
        // In a backend request core only queues the processing and scales the
        // image when a browser asks for it. Nobody will, so it has to happen
        // here, as ImageProcessingService does it, and the request gets its
        // deferred processing back afterwards.
        $context  = $this->context ?? GeneralUtility::makeInstance(Context::class);
        $previous = $context->hasAspect('fileProcessing') ? $context->getAspect('fileProcessing') : new FileProcessingAspect();
        $context->setAspect('fileProcessing', new FileProcessingAspect(false));

        try {
            $processed = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, $this->configuration($file, $maxEdge));
            // Within the bound core keeps the original, and a processed file
            // cannot read it when the processing folder is on another storage.
            $bytes = $processed->usesOriginalFile() ? $file->getContents() : $processed->getContents();
        } catch (Throwable $exception) {
            $this->logger?->warning('An image could not be downscaled for a vision action', ['file' => $file->getUid(), 'exception' => $exception]);

            return null;
        } finally {
            $context->setAspect('fileProcessing', $previous);
        }

        return $this->encode($bytes, $maxEdge);
    }

    /**
     * A GIF becomes a PNG of its first frame: Gemini takes no GIF, and an
     * animation within the bound could otherwise go out at any size.
     *
     * @return array<string, int|string>
     */
    private function configuration(File $file, int $maxEdge): array
    {
        $configuration = ['maxWidth' => $maxEdge, 'maxHeight' => $maxEdge];
        if (strtolower($file->getExtension()) === 'gif') {
            $configuration['fileExtension'] = 'png';
        }

        return $configuration;
    }

    /**
     * Public for the check that matters: what leaves the installation is
     * measured here, whatever the processing reported.
     */
    public function encode(string $bytes, int $maxEdge): ?string
    {
        $size = $bytes !== '' ? getimagesizefromstring($bytes) : false;
        if ($size === false || max($size[0], $size[1]) > $maxEdge) {
            $this->logger?->warning('An image is not sent to a model: it could not be brought to the configured size');

            return null;
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            return null;
        }

        return 'data:' . $type . ';base64,' . base64_encode($bytes);
    }
}
