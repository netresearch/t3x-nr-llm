<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Term;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolver;
use Netresearch\NrLlm\Service\Guardrail\ContextualGuardrailInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_filter;
use function array_values;
use function array_walk_recursive;
use function is_string;
use function trim;

/**
 * Blocks an output that breaks a term list (ADR-238).
 *
 * Mandatory in the sense of ADR-106: the scope of a list is on the list, and a
 * configuration's guardrail selection must not silently skip one that names it.
 * Without a list naming the configuration this guardrail does nothing.
 *
 * A failed check blocks. An output nobody could check has not passed.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TermListGuardrail implements ContextualGuardrailInterface
{
    public function __construct(
        private TermListResolverInterface $termLists,
        private TermMatcher $matcher,
        private TermListMessages $messages,
        private LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return 'term-list';
    }

    public function isMandatory(): bool
    {
        return true;
    }

    /**
     * A list applies per configuration, and a call without context has none.
     */
    public function checkOutput(CompletionResponse $response): GuardrailResult
    {
        return GuardrailResult::allow();
    }

    public function checkOutputInContext(CompletionResponse $response, ProviderCallContext $context): GuardrailResult
    {
        $configurationUid = $context->configuration?->getUid();
        if ($configurationUid === null) {
            return GuardrailResult::allow();
        }

        try {
            return $this->screen($response, $context, $this->termLists->forConfiguration($configurationUid));
        } catch (Throwable $failure) {
            $this->logger->error('The term check could not run; the output was blocked.', ['exception' => $failure]);

            return GuardrailResult::deny($this->messages->checkFailed());
        }
    }

    public function holdsStream(ProviderCallContext $context): bool
    {
        $configurationUid = $context->configuration?->getUid();
        if ($configurationUid === null) {
            return false;
        }

        try {
            return $this->termLists->forConfiguration($configurationUid) !== [];
        } catch (Throwable) {
            // Unknown means hold: checkOutputInContext() meets the same failure
            // at the end of the stream and blocks, with nothing delivered.
            return true;
        }
    }

    /**
     * @param list<TermListRule> $lists
     */
    private function screen(CompletionResponse $response, ProviderCallContext $context, array $lists): GuardrailResult
    {
        if ($lists === []) {
            return GuardrailResult::allow();
        }

        $site     = $this->metadataString($context, ProviderCallContext::METADATA_SITE);
        $language = GlossaryResolver::baseLanguage($this->metadataString($context, ProviderCallContext::METADATA_LANGUAGE));

        $answer  = $this->matcher->searchForms($response->content);
        $written = [...$answer, ...$this->toolArgumentForms($response)];
        // A step that only calls a tool has no text of its own, and an empty
        // answer is no output anyone would publish.
        $isText = !$response->hasToolCalls() && trim($response->content) !== '';

        foreach ($lists as $list) {
            if ($list->forbidsFor($site, $language)) {
                $found = array_values(array_filter(
                    $list->forbiddenTerms,
                    fn (string $term): bool => $this->matcher->occursIn($term, $written),
                ));
                if ($found !== []) {
                    return GuardrailResult::deny($this->messages->forbidden($found, $list));
                }
            }

            if ($isText && $list->requiresFor($site, $language)) {
                $missing = array_values(array_filter(
                    $list->requiredTerms,
                    fn (string $term): bool => !$this->matcher->occursIn($term, $answer),
                ));
                if ($missing !== []) {
                    return GuardrailResult::deny($this->messages->required($missing, $list));
                }
            }
        }

        return GuardrailResult::allow();
    }

    /**
     * The strings a tool call would write. A writing tool stores its arguments,
     * so a forbidden term there is as much an output as one in the answer.
     *
     * @return list<string>
     */
    private function toolArgumentForms(CompletionResponse $response): array
    {
        $forms = [];
        foreach ($response->toolCalls ?? [] as $toolCall) {
            $arguments = $toolCall->arguments;
            array_walk_recursive($arguments, function (mixed $value) use (&$forms): void {
                if (is_string($value)) {
                    $forms = [...$forms, ...$this->matcher->searchForms($value)];
                }
            });
        }

        return $forms;
    }

    private function metadataString(ProviderCallContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
