<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Document;

use Netresearch\NrLlm\Provider\Contract\DocumentCapableInterface;
use Netresearch\NrLlm\Service\Budget\AutoPopulatesBeUserUidTrait;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrLlm\Specialized\Exception\PdfRasterizationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Exception\UnsupportedFormatException;

/**
 * Stateless "understand this document" primitive (ADR-076).
 *
 * Analyzes a PDF against a caller-supplied prompt. When the resolved
 * provider implements {@see DocumentCapableInterface} the PDF is ingested
 * natively as a Base64 document block in ONE chat call — whole-document
 * reasoning. Otherwise the document is rasterized page-by-page (poppler via
 * {@see PdfRasterizerInterface}) and each page is read by the vision model;
 * the per-page answers are concatenated with `[Page N]` markers.
 *
 * Ingestion orchestration stays with the consumer: chunking, per-page
 * degradation policy (a failed page on the fallback path fails the call —
 * catch and retry on the consumer side), enable/disable flags, and cost
 * capping. Budget attribution mirrors the feature services: `beUserUid`
 * is auto-populated from the backend user context when the caller did not
 * set it (REC #4).
 */
final readonly class DocumentAnalysisService
{
    use AutoPopulatesBeUserUidTrait;

    public function __construct(
        private LlmServiceManagerInterface $llmManager,
        private VisionServiceInterface $visionService,
        private PdfRasterizerInterface $rasterizer,
        private ?BackendUserContextResolverInterface $beUserContextResolver = null,
    ) {}

    /**
     * Analyze a PDF with a custom prompt.
     *
     * @param string $pdf    Raw PDF bytes (must start with the %PDF- header)
     * @param string $prompt Analysis prompt, applied to the whole document on
     *                       the native path and to each page on the fallback
     *
     * @throws UnsupportedFormatException  when the bytes are not a PDF
     * @throws ServiceUnavailableException when neither the native path nor the
     *                                     rasterization fallback is possible
     *                                     (provider lacks document support and
     *                                     poppler is not installed)
     * @throws PdfRasterizationException   when rasterization itself fails
     */
    public function analyzeDocument(string $pdf, string $prompt, ?ChatOptions $options = null): DocumentAnalysisResult
    {
        if (!str_starts_with($pdf, '%PDF-')) {
            throw UnsupportedFormatException::documentFormat();
        }

        $options = $this->autoPopulateBeUserUid($options ?? new ChatOptions());

        $provider = $this->llmManager->getProvider($this->resolveProviderKey($options));

        if (
            $provider instanceof DocumentCapableInterface
            && $provider->supportsDocuments()
            && in_array('pdf', $provider->getSupportedDocumentFormats(), true)
        ) {
            return $this->analyzeNatively($pdf, $prompt, $options->withProvider($provider->getIdentifier()));
        }

        return $this->analyzeViaRasterization($pdf, $prompt, $options, $provider->getIdentifier());
    }

    /**
     * Provider key to probe for document capability. An explicit per-call
     * provider wins; otherwise the backend-managed default configuration's
     * provider type — the same record `LlmServiceManager::chat()` would
     * route to — so the capability probe and the dispatch cannot diverge.
     * Null falls through to the registry default provider.
     */
    private function resolveProviderKey(ChatOptions $options): ?string
    {
        $providerKey = $options->getProvider();
        if ($providerKey !== null && $providerKey !== '') {
            return $providerKey;
        }

        $configuration = $this->llmManager->resolveEffectiveConfiguration();
        if ($configuration !== null && $configuration->getProviderType() !== '') {
            return $configuration->getProviderType();
        }

        return null;
    }

    /**
     * Native path: one chat call carrying the whole PDF as a Base64 document
     * block. The document-capable providers (Gemini, Claude) convert the
     * block to their inline-document wire format.
     */
    private function analyzeNatively(string $pdf, string $prompt, ChatOptions $options): DocumentAnalysisResult
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => base64_encode($pdf),
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->llmManager->chat($messages, $options);

        return new DocumentAnalysisResult(
            text: $response->getText(),
            model: $response->model,
            provider: $response->provider,
            usedNativeDocumentPath: true,
        );
    }

    /**
     * Fallback path: rasterize every page and put each one through the
     * vision model with the caller's prompt. Requires the poppler binaries;
     * a per-page vision failure fails the call (no silent partial result —
     * degradation policy is the consumer's).
     */
    private function analyzeViaRasterization(
        string $pdf,
        string $prompt,
        ChatOptions $options,
        string $providerIdentifier,
    ): DocumentAnalysisResult {
        if (!$this->rasterizer->isAvailable()) {
            throw ServiceUnavailableException::rasterizerUnavailable($providerIdentifier);
        }

        $pages = $this->rasterize($pdf);
        $visionOptions = $this->buildVisionOptions($options);

        $sections = [];
        $model = '';
        $provider = '';
        foreach ($pages as $pageNumber => $png) {
            $dataUri = 'data:image/png;base64,' . base64_encode($png);
            $response = $this->visionService->analyzeImageFull($dataUri, $prompt, $visionOptions);
            $model = $response->model;
            $provider = $response->provider;
            $text = trim($response->getText());
            if ($text !== '') {
                $sections[] = sprintf("[Page %d]\n%s", $pageNumber, $text);
            }
        }

        return new DocumentAnalysisResult(
            text: implode("\n\n", $sections),
            model: $model,
            provider: $provider,
            usedNativeDocumentPath: false,
            rasterizedPageCount: count($pages),
        );
    }

    /**
     * Materialise the PDF bytes to a temporary file for the rasterizer
     * (poppler reads files, not stdin) and clean it up afterwards.
     *
     * @throws PdfRasterizationException
     *
     * @return array<int, string> PNG bytes per 1-based page number
     */
    private function rasterize(string $pdf): array
    {
        $path = tempnam(sys_get_temp_dir(), 'nrllm_doc_');
        if ($path === false) {
            throw new PdfRasterizationException('Unable to allocate a temporary file for document analysis.', 1784211011);
        }

        try {
            if (file_put_contents($path, $pdf) === false) {
                throw new PdfRasterizationException('Unable to write the document to a temporary file.', 1784211012);
            }

            return $this->rasterizer->renderDocument($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Carry the chat options' passthrough fields over to the vision fallback.
     * Every typed field must be copied across — a missing field looks like
     * "use the default" (same class of bug as VisionService's options
     * rebuild, PR #177).
     */
    private function buildVisionOptions(ChatOptions $options): VisionOptions
    {
        return new VisionOptions(
            maxTokens: $options->getMaxTokens(),
            temperature: $options->getTemperature(),
            provider: $options->getProvider(),
            model: $options->getModel(),
            beUserUid: $options->getBeUserUid(),
            plannedCost: $options->getPlannedCost(),
        );
    }
}
