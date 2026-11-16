<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Editorial;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Guardrail\Judge\Rule;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSetResolver;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListRule;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;

use function array_map;
use function hash;
use function in_array;
use function json_encode;

/**
 * A digest of the rules a configuration applies to a text (ADR-300).
 *
 * Two digests are equal when the same configurations would compose the same
 * snippets, judge by the same rule lines with the same judging configuration,
 * and search the same term lists. A change to any of them changes the digest:
 * an edited snippet, a new forbidden term, another judge mode, another model
 * or provider of the judging configuration. A rule line beyond the cap of
 * {@see \Netresearch\NrLlm\Service\Guardrail\Judge\RuleSet::MAX_RULES} is
 * neither judged nor part of the digest. It is a fingerprint, not a copy: the
 * texts it was built from are read live and are not kept.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class RuleStateDigest
{
    public function __construct(
        private LlmConfigurationRepository $configurations,
        private ConfigurationSnippetResolver $snippets,
        private RuleSetResolver $ruleSets,
        private TermListResolverInterface $termLists,
    ) {}

    /**
     * @param list<int> $furtherConfigurations as named for the check (ADR-290); a missing one is recorded as missing
     */
    public function of(LlmConfiguration $configuration, array $furtherConfigurations = []): string
    {
        $state = [$this->stateOf($configuration)];
        $seen  = [$configuration->getUid()];
        foreach ($furtherConfigurations as $uid) {
            if ($uid <= 0 || in_array($uid, $seen, true)) {
                continue;
            }

            $seen[]  = $uid;
            $further = $this->configurations->findByUid($uid);
            // A named configuration that cannot be loaded still brings the
            // term lists that name it, as in the check.
            $state[] = $further instanceof LlmConfiguration
                ? $this->stateOf($further)
                : ['uid' => $uid, 'missing' => true, 'termLists' => $this->termListsOf($uid)];
        }

        return 'sha256:' . hash('sha256', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function stateOf(LlmConfiguration $configuration): array
    {
        $uid     = $configuration->getUid() ?? 0;
        $ruleSet = $this->ruleSets->forTags($configuration->getRuleJudgeTagList());

        return [
            'uid'         => $uid,
            'judgeMode'   => $configuration->getRuleJudgeModeEnum()->value,
            'judgeTags'   => $configuration->getRuleJudgeTagList(),
            'judging'     => $this->judgingOf($configuration->getRuleJudgeConfiguration()),
            'snippetTags' => $configuration->getSnippetTagList(),
            'snippets'    => array_map(
                static fn (PromptSnippet $snippet): array => [$snippet->getIdentifier(), $snippet->getName(), $snippet->getSnippet(), $snippet->getDataClass()],
                $this->snippets->selectedSnippets($configuration),
            ),
            'rules' => array_map(
                static fn (Rule $rule): array => [$rule->snippetName, $rule->text],
                $ruleSet->rules,
            ),
            'ruleClasses' => array_map(
                static fn (ToolDataClass $class): string => $class->value,
                $ruleSet->dataClasses,
            ),
            'termLists' => $uid > 0 ? $this->termListsOf($uid) : [],
        ];
    }

    /**
     * The judging configuration as far as it decides whether a judge answers
     * and where the text goes: its uid, active flag, model and provider.
     *
     * @return array<string, mixed>
     */
    private function judgingOf(int $uid): array
    {
        $judging = $uid > 0 ? $this->configurations->findByUid($uid) : null;
        if (!$judging instanceof LlmConfiguration) {
            return ['uid' => $uid, 'missing' => true];
        }

        return [
            'uid'      => $uid,
            'active'   => $judging->isActive(),
            'model'    => [$judging->getLlmModel()?->getUid(), $judging->getLlmModel()?->getModelId()],
            'provider' => $judging->getProvider()?->getIdentifier(),
            'zone'     => $judging->getProvider()?->getTrustZone(),
        ];
    }

    /**
     * @return list<array{string, string, string, list<string>, list<string>, string}>
     */
    private function termListsOf(int $uid): array
    {
        return array_map(
            static fn (TermListRule $list): array => [$list->name, $list->siteIdentifier, $list->language, $list->forbiddenTerms, $list->requiredTerms, $list->message],
            $this->termLists->forConfiguration($uid),
        );
    }
}
