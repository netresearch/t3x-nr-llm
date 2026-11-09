<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Model;

use Netresearch\NrLlm\Service\SetupWizard\Discovery\OpenRouterReasoning;
use Netresearch\NrLlm\Service\SetupWizard\SecureHttpDispatchTrait;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Reads whether OpenRouter's public model list calls a direct OpenAI model a
 * reasoning model (ADR-275).
 *
 * OpenAI's own `/v1/models` states no capability at all, so this is the only
 * outside answer for a model the bundled table does not name. It is a third
 * party describing a model it does not serve, which is why the verifier
 * records it as {@see \Netresearch\NrLlm\Domain\Enum\CapabilitySource::ThirdPartyCatalog}
 * and takes only reasoning yes/no from it, never the effort scale.
 *
 * Off by default (`discovery.thirdPartyReasoningCatalog`). When on, it is one
 * anonymous `GET` of a public list per capability verification: no
 * credential, no body, no content of any request. It never runs while a
 * request is being sent. Every failure answers null, and null records
 * nothing.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ThirdPartyReasoningCatalog
{
    use ErrorMessageSanitizerTrait;
    use SecureHttpDispatchTrait;

    public const CATALOGUE_URL = 'https://openrouter.ai/api/v1/models';

    /** The prefix OpenRouter gives a model served by OpenAI. */
    private const OPENAI_PREFIX = 'openai/';

    private const DISPATCH_REASON = 'LLM model discovery: public reasoning catalogue';

    public function __construct(
        VaultServiceInterface $vault,
        SecureHttpClientFactory $httpClientFactory,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LoggerInterface $logger,
    ) {
        // Initialise the readonly collaborators declared in
        // SecureHttpDispatchTrait (no promotion — the trait owns them).
        $this->vault             = $vault;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * Whether the operator switched the lookup on.
     */
    public function isEnabled(): bool
    {
        try {
            $settings = $this->extensionConfiguration->get('nr_llm', 'discovery');
        } catch (Throwable) {
            return false;
        }

        return is_array($settings) && (bool) ($settings['thirdPartyReasoningCatalog'] ?? false);
    }

    /**
     * True or false when the catalogue states it for `openai/<model id>`
     * ({@see OpenRouterReasoning::statedBy()}); null when the lookup is off,
     * failed, or the catalogue has no such entry or states nothing for it.
     */
    public function reasoningFor(string $openAiModelId): ?bool
    {
        if ($openAiModelId === '' || !$this->isEnabled()) {
            return null;
        }

        try {
            $entries = $this->fetchEntries();
        } catch (Throwable $e) {
            $this->logger->warning('LLM reasoning catalogue lookup failed', [
                'exception' => $e::class,
                'message'   => $this->sanitizeErrorMessage($e->getMessage()),
            ]);

            return null;
        }

        $modelId = strtolower(trim($openAiModelId));
        // A dated snapshot (`gpt-4.1-mini-2025-04-14`) is listed under its
        // family name only; it reasons as its family does, as in the bundled
        // table. The catalogue's own `canonical_slug` dates (`-20250414`) are
        // OpenRouter's and need not match OpenAI's snapshot date.
        $family = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $modelId) ?? $modelId;

        return $this->statedFor($entries, self::OPENAI_PREFIX . $modelId)
            ?? ($family !== $modelId ? $this->statedFor($entries, self::OPENAI_PREFIX . $family) : null);
    }

    /**
     * @param array<int|string, mixed> $entries
     */
    private function statedFor(array $entries, string $catalogueId): ?bool
    {
        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $catalogueId) {
                return OpenRouterReasoning::statedBy($entry);
            }
        }

        return null;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function fetchEntries(): array
    {
        // Deliberately no Authorization header and no body: the list is
        // public, and nothing of this installation is sent to the third party.
        $request  = $this->requestFactory->createRequest('GET', self::CATALOGUE_URL);
        $response = $this->dispatch($request, self::DISPATCH_REASON);

        if ($response->getStatusCode() !== 200) {
            $this->logger->warning('LLM reasoning catalogue returned an unexpected HTTP status', [
                'status' => $response->getStatusCode(),
            ]);

            return [];
        }

        $decoded = json_decode($response->getBody()->getContents(), true);

        return is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])
            ? $decoded['data']
            : [];
    }
}
