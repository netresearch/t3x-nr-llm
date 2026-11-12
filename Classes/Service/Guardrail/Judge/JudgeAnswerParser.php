<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;

use function array_slice;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function range;
use function sprintf;
use function str_contains;
use function trim;

/**
 * Turns the judge's answer into violations an editor can check (ADR-288).
 *
 * The answer is data from a model, so nothing in it is taken on trust. It has
 * to match {@see self::schema()}. A violation counts only when its rule exists
 * and its passage occurs in the text, white space and case aside, so a hint
 * never quotes a rule or a passage the model made up. When the judge names
 * violations and none of them can be found, the answer is unusable: the model
 * saw something it could not point at, which is no pass either.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class JudgeAnswerParser
{
    /** An editor fixes a few things at a time, and a block message stays readable. */
    public const MAX_VIOLATIONS = 10;

    public function __construct(
        private JsonSchemaValidator $validator = new JsonSchemaValidator(),
    ) {}

    /**
     * The schema of an answer: per violation a copied passage, the reason it
     * breaks a rule, and the rule's number. The reason is asked for and not
     * shown: a hint quotes only what can be checked against the record and the
     * text. The rule numbers are an enumeration rather than a range: the
     * strict profile of OpenAI rejects `minimum` (ADR-129).
     *
     * @return array<string, mixed>
     */
    public function schema(RuleSet $rules): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'violations' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        // The order is the order a model writes in: the passage and why
                        // it breaks a rule come before the rule's number, so the number
                        // follows from a reason rather than the reason from a number.
                        'properties' => [
                            'passage' => ['type' => 'string'],
                            'reason'  => ['type' => 'string'],
                            'rule'    => ['type' => 'integer', 'enum' => count($rules->rules) > 0 ? range(1, count($rules->rules)) : [1]],
                        ],
                        'required' => ['passage', 'reason', 'rule'],
                    ],
                ],
            ],
            'required' => ['violations'],
        ];
    }

    /**
     * @param list<string> $textForms the judged text as delivered and with markup removed
     *
     * @return list<JudgeViolation>
     *
     * @throws JudgeUnavailableException when the answer is not usable
     */
    public function parse(string $answer, RuleSet $rules, array $textForms): array
    {
        $data = $this->decode($answer);
        if ($data === null || !$this->validator->validateStrict($data, $this->schema($rules))) {
            throw new JudgeUnavailableException('The judge answer does not match the schema.', 5737136788);
        }

        $claimed = is_array($data['violations'] ?? null) ? $data['violations'] : [];
        $found   = [];
        $seen    = [];
        foreach ($claimed as $entry) {
            if (!is_array($entry) || !is_int($entry['rule'] ?? null) || !is_string($entry['passage'] ?? null)) {
                continue;
            }

            $rule    = $rules->rule($entry['rule']);
            $passage = trim($entry['passage']);
            if (!$rule instanceof Rule || !$this->occursIn($passage, $textForms)) {
                continue;
            }

            $key = $rule->number . "\0" . $this->normalized($passage);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $found[]    = new JudgeViolation($rule, $passage);
        }

        if ($claimed !== [] && $found === []) {
            throw new JudgeUnavailableException(sprintf(
                'The judge named %d violation(s), and none has an existing rule and a passage found in the text.',
                count($claimed),
            ), 3346910584);
        }

        return array_slice($found, 0, self::MAX_VIOLATIONS);
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $answer): ?array
    {
        $decoded = json_decode(trim($answer), true);
        if (!is_array($decoded) && preg_match('/^\s*```(?:json)?\s*([\s\S]*?)\s*```\s*$/i', $answer, $matches) === 1) {
            $decoded = json_decode($matches[1], true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<string> $textForms
     */
    private function occursIn(string $passage, array $textForms): bool
    {
        $needle = $this->normalized($passage);
        if ($needle === '') {
            return false;
        }

        foreach ($textForms as $form) {
            if (str_contains($this->normalized($form), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalized(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
