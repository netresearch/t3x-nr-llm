<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Testing;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Throwable;

/**
 * Consumer-facing test double for {@see VisionServiceInterface}.
 *
 * Ships in the runtime-autoloaded `Netresearch\NrLlm\Testing\` namespace so
 * downstream extensions can fake the vision surface in their unit tests instead
 * of hand-rolling a double that breaks whenever the interface grows.
 * Implementing the real interface means PHPStan keeps this double in sync with
 * the production contract.
 *
 * The four string-or-array methods return the matching canned string, echoing
 * the caller's arity: a single image yields the string, a batch yields one
 * canned string per input. {@see self::analyzeImageFull()} returns the canned
 * {@see $analyzeImageFullResult} (a default is built when left null). Every call
 * is captured in the matching `*Calls` array; set {@see $throwable} to make the
 * next call throw.
 *
 * Not a DI service: excluded from container autoconfiguration in
 * `Configuration/Services.yaml`. It is a fixture for consumer test suites,
 * never wire it into production.
 */
final class FakeVisionService implements VisionServiceInterface
{
    public string $altTextResult = 'fake alt text';

    public string $titleResult = 'fake title';

    public string $descriptionResult = 'fake description';

    public string $analyzeImageResult = 'fake analysis';

    /** Canned response for {@see self::analyzeImageFull()}; a default is built when left null. */
    public ?VisionResponse $analyzeImageFullResult = null;

    /**
     * When set, the next call throws this instead of returning. Cleared before
     * throwing, so subsequent calls return canned values again.
     */
    public ?Throwable $throwable = null;

    /** @var list<array{imageUrl: string|array<int, string>, options: ?VisionOptions}> */
    public array $generateAltTextCalls = [];

    /** @var list<array{imageUrl: string|array<int, string>, options: ?VisionOptions}> */
    public array $generateTitleCalls = [];

    /** @var list<array{imageUrl: string|array<int, string>, options: ?VisionOptions}> */
    public array $generateDescriptionCalls = [];

    /** @var list<array{imageUrl: string|array<int, string>, customPrompt: string, options: ?VisionOptions}> */
    public array $analyzeImageCalls = [];

    /** @var list<array{imageUrl: string, prompt: string, options: ?VisionOptions}> */
    public array $analyzeImageFullCalls = [];

    /**
     * @param string|array<int, string> $imageUrl
     *
     * @return string|array<int, string>
     */
    public function generateAltText(string|array $imageUrl, ?VisionOptions $options = null): string|array
    {
        $this->generateAltTextCalls[] = ['imageUrl' => $imageUrl, 'options' => $options];
        $this->guardThrow();

        return $this->echoArity($imageUrl, $this->altTextResult);
    }

    /**
     * @param string|array<int, string> $imageUrl
     *
     * @return string|array<int, string>
     */
    public function generateTitle(string|array $imageUrl, ?VisionOptions $options = null): string|array
    {
        $this->generateTitleCalls[] = ['imageUrl' => $imageUrl, 'options' => $options];
        $this->guardThrow();

        return $this->echoArity($imageUrl, $this->titleResult);
    }

    /**
     * @param string|array<int, string> $imageUrl
     *
     * @return string|array<int, string>
     */
    public function generateDescription(string|array $imageUrl, ?VisionOptions $options = null): string|array
    {
        $this->generateDescriptionCalls[] = ['imageUrl' => $imageUrl, 'options' => $options];
        $this->guardThrow();

        return $this->echoArity($imageUrl, $this->descriptionResult);
    }

    /**
     * @param string|array<int, string> $imageUrl
     *
     * @return string|array<int, string>
     */
    public function analyzeImage(string|array $imageUrl, string $customPrompt, ?VisionOptions $options = null): string|array
    {
        $this->analyzeImageCalls[] = ['imageUrl' => $imageUrl, 'customPrompt' => $customPrompt, 'options' => $options];
        $this->guardThrow();

        return $this->echoArity($imageUrl, $this->analyzeImageResult);
    }

    public function analyzeImageFull(string $imageUrl, string $prompt, ?VisionOptions $options = null): VisionResponse
    {
        $this->analyzeImageFullCalls[] = ['imageUrl' => $imageUrl, 'prompt' => $prompt, 'options' => $options];
        $this->guardThrow();

        return $this->analyzeImageFullResult ?? new VisionResponse(
            description: $this->descriptionResult,
            model: 'fake-vision-model',
            usage: new UsageStatistics(0, 0, 0),
        );
    }

    /**
     * Echo the caller's arity: a single image returns the canned string, a batch
     * returns one canned string per input (matching the string|array contract).
     *
     * @param string|array<int, string> $imageUrl
     *
     * @return string|array<int, string>
     */
    private function echoArity(string|array $imageUrl, string $canned): string|array
    {
        if (is_array($imageUrl)) {
            return array_map(static fn(): string => $canned, $imageUrl);
        }

        return $canned;
    }

    private function guardThrow(): void
    {
        if ($this->throwable instanceof Throwable) {
            $throwable = $this->throwable;
            $this->throwable = null;

            throw $throwable;
        }
    }
}
