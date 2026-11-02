<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Term;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
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
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function preg_replace;
use function trim;

/**
 * Blocks an output that breaks a term list (ADR-238).
 *
 * In a run that offers tools the output is what a writing tool would store. The
 * answer there is conversation, it streams and it is not searched, and a search
 * for a forbidden term runs. Every other call is screened as a whole.
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

        $readTools = $this->readToolsOfRun($context);
        $arguments = $readTools !== null ? $this->writtenArguments($response, $readTools) : [];
        if ($readTools !== null && $arguments === []) {
            return GuardrailResult::allow();
        }

        try {
            $lists = $this->termLists->forConfiguration($configurationUid);

            return $readTools !== null
                ? $this->screenWrites($arguments, $context, $lists)
                : $this->screen($response, $context, $lists);
        } catch (Throwable $failure) {
            $this->logger->error('The term check could not run; the output was blocked.', ['exception' => $failure]);

            return GuardrailResult::deny($this->messagesFor($context)->checkFailed());
        }
    }

    public function holdsStream(ProviderCallContext $context): bool
    {
        $configurationUid = $context->configuration?->getUid();
        if ($configurationUid === null || $this->readToolsOfRun($context) !== null) {
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
     * What a run that offers tools would store: its forbidden terms only, as a
     * required term was never asked of a run.
     *
     * @param list<string>       $arguments
     * @param list<TermListRule> $lists
     */
    private function screenWrites(array $arguments, ProviderCallContext $context, array $lists): GuardrailResult
    {
        $site     = $this->metadataString($context, ProviderCallContext::METADATA_SITE);
        $language = GlossaryResolver::baseLanguage($this->metadataString($context, ProviderCallContext::METADATA_LANGUAGE));

        foreach ($lists as $list) {
            if (!$list->forbidsFor($site, $language)) {
                continue;
            }

            $found = array_values(array_filter(
                $list->forbiddenTerms,
                fn (string $term): bool => $this->matcher->occursIn($term, $arguments),
            ));
            if ($found !== []) {
                return GuardrailResult::deny($this->messagesFor($context)->forbiddenWrite($found, $list));
            }
        }

        return GuardrailResult::allow();
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

        $answer  = $this->answerForms($response->content);
        $written = [...$answer, ...$this->toolArgumentForms($response)];
        $isText  = $this->isFinishedText($response, $context);

        foreach ($lists as $list) {
            if ($list->forbidsFor($site, $language)) {
                $found = array_values(array_filter(
                    $list->forbiddenTerms,
                    fn (string $term): bool => $this->matcher->occursIn($term, $written),
                ));
                if ($found !== []) {
                    return GuardrailResult::deny($this->messagesFor($context)->forbidden($found, $list));
                }
            }

            if ($isText && $list->requiresFor($site, $language)) {
                $missing = array_values(array_filter(
                    $list->requiredTerms,
                    fn (string $term): bool => !$this->matcher->occursIn($term, $answer),
                ));
                if ($missing !== []) {
                    return GuardrailResult::deny($this->messagesFor($context)->required($missing, $list));
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
            || $this->isJson($response->content)
        ) {
            return false;
        }

        return $this->matcher->wordCount($response->content) >= self::MIN_WORDS_FOR_REQUIRED_TERMS;
    }

    /**
     * A model without a JSON mode wraps its answer in a code fence, as
     * {@see \Netresearch\NrLlm\Service\Feature\CompletionService} knows.
     */
    private function isJson(string $content): bool
    {
        return $this->decodedJson($this->withoutFence($content)) !== null;
    }

    private function withoutFence(string $content): string
    {
        return preg_replace('/(?:^\s*```[a-z]*\s*)|(?:\s*```\s*$)/i', '', $content) ?? $content;
    }

    /**
     * @return array<mixed>|null
     */
    private function decodedJson(string $body): ?array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The answer as sent and, for JSON, each decoded string: inside the raw
     * JSON the `n` of a `\n` escape touches the next word and hides it.
     *
     * @return list<string>
     */
    private function answerForms(string $content): array
    {
        $forms = $this->matcher->searchForms($content);
        $data  = $this->decodedJson($this->withoutFence($content));
        if ($data !== null) {
            $forms = [...$forms, ...$this->stringForms($data)];
        }

        return $forms;
    }

    /**
     * The string arguments of every tool call. A call outside a run is no chat:
     * nothing says which of its tools write, so all of them are searched.
     *
     * @return list<string>
     */
    private function toolArgumentForms(CompletionResponse $response): array
    {
        return $this->writtenArguments($response, []);
    }

    /**
     * The string arguments of the tool calls that do not declare a read. A
     * writing tool stores them, so a forbidden term there is an output.
     *
     * @param list<string> $readTools
     *
     * @return list<string>
     */
    private function writtenArguments(CompletionResponse $response, array $readTools): array
    {
        $forms = [];
        foreach ($response->toolCalls ?? [] as $toolCall) {
            if (!in_array($toolCall->name, $readTools, true)) {
                $forms = [...$forms, ...$this->stringForms($toolCall->arguments)];
            }
        }

        return $forms;
    }

    /**
     * The read tools of a run that offers tools, null for every other call.
     *
     * @return list<string>|null
     */
    private function readToolsOfRun(ProviderCallContext $context): ?array
    {
        $names = $context->metadata[GuardrailMiddleware::METADATA_RUN_READ_TOOLS] ?? null;

        return is_array($names) ? array_values(array_filter($names, is_string(...))) : null;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function stringForms(array $data): array
    {
        $forms = [];
        array_walk_recursive($data, function (mixed $value) use (&$forms): void {
            if (is_string($value)) {
                $forms = [...$forms, ...$this->matcher->searchForms($value)];
            }
        });

        return $forms;
    }

    /**
     * The sentence is stored, so it has to be in the language of the person the
     * call belongs to also where a worker runs it with no backend user around.
     */
    private function messagesFor(ProviderCallContext $context): TermListMessages
    {
        $userUid = $context->metadata[BudgetMiddleware::METADATA_BE_USER_UID] ?? 0;
        if (!is_int($userUid) || $userUid <= 0) {
            return $this->messages;
        }

        try {
            return $this->messages->inLanguage($this->termLists->languageOfUser($userUid));
        } catch (Throwable) {
            // The check-failed sentence itself gets here when the database is gone.
            return $this->messages;
        }
    }

    private function metadataString(ProviderCallContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
