<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Term;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolver;
use Netresearch\NrLlm\Service\Guardrail\ContextualGuardrailInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_filter;
use function array_values;
use function array_walk_recursive;
use function in_array;
use function is_string;
use function ltrim;
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
    /**
     * Below this many words an answer is no text that could hold a required
     * term: a language code, a score, a label, an alternative text. Those run
     * on the same configurations as the texts a list means.
     */
    public const MIN_WORDS_FOR_REQUIRED_TERMS = 30;

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
        $isText  = $this->isFinishedText($response, $context);

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
     * Whether required terms can be asked of this answer. Not of a step that
     * only calls a tool, not of the closing note of an agent run, which wrote
     * its text through a tool, not of an image description, and not of JSON,
     * which is settings for a wizard or a consumer rather than text.
     */
    private function isFinishedText(CompletionResponse $response, ProviderCallContext $context): bool
    {
        if ($response->hasToolCalls()
            || $context->operation === ProviderOperation::Vision
            || isset($context->metadata[GuardrailMiddleware::METADATA_AGENT_RUN_UID])
            || in_array(ltrim($response->content)[0] ?? '', ['{', '['], true)
        ) {
            return false;
        }

        return $this->matcher->wordCount($response->content) >= self::MIN_WORDS_FOR_REQUIRED_TERMS;
    }

    /**
     * The string arguments of every tool call. A writing tool stores them, so
     * a forbidden term there is as much an output as one in the answer. The
     * check does not ask which tools write: a search for the term stops too.
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
