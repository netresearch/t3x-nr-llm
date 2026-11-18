<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Privacy;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\RunContentDigests;
use Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-323: a step at the `metadata` level keeps a keyed digest of each part of
 * the content it drops, when the operator opted in; no other level does.
 */
#[CoversClass(RunStepPrivacyFilter::class)]
#[CoversClass(RunContentDigests::class)]
final class RunStepPrivacyFilterDigestsTest extends TestCase
{
    private const DIGEST_KEYS = ['messagesSentDigests', 'contentDigest', 'toolArgumentsDigest', 'toolResultDigest', 'requestDigest'];

    private const IDENTITY = 'v1:0fde2089bb682218c3f0484a095e256d4a1a1766870593f31e4068702dc58de8';

    private mixed $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $this->setKey('test-encryption-key');
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = $this->configuration;
        parent::tearDown();
    }

    #[Test]
    public function withoutTheSettingAStepKeepsWhatItKeptBefore(): void
    {
        $filter = $this->filter(PrivacyLevel::METADATA, false);

        foreach ([$this->request('Which pages?', 'call_a'), $this->tool('Rows 1-3'), $this->answer('Three pages.')] as $step) {
            $kept = $filter->filter($step);
            unset($step['requestIdentity']);
            self::assertSame($filter->filter($step), $kept, 'the request identity changes nothing it keeps');
            foreach ([...self::DIGEST_KEYS, 'requestIdentity'] as $key) {
                self::assertArrayNotHasKey($key, $kept);
            }
        }
    }

    #[Test]
    public function atTheMetadataLevelEachDroppedPartKeepsAKeyedDigest(): void
    {
        $filter = $this->filter(PrivacyLevel::METADATA, true);

        $request = $filter->filter($this->request('Which pages?', 'call_a'));
        self::assertArrayNotHasKey('messagesSent', $request);
        self::assertSame(['system', 'user', 'assistant', 'tool'], array_column($this->messageDigests($request), 'role'));
        foreach (array_column($this->messageDigests($request), 'digest') as $digest) {
            self::assertMatchesRegularExpression('/^v1:[a-f0-9]{64}$/D', $digest);
        }

        $tool = $filter->filter($this->tool('Rows 1-3'));
        self::assertArrayNotHasKey('toolArguments', $tool);
        self::assertArrayNotHasKey('toolResult', $tool);
        self::assertMatchesRegularExpression('/^v1:[a-f0-9]{64}$/D', $this->text($tool, 'toolArgumentsDigest'));
        self::assertMatchesRegularExpression('/^v1:[a-f0-9]{64}$/D', $this->text($tool, 'toolResultDigest'));

        $answer = $filter->filter($this->answer('Three pages.'));
        self::assertArrayNotHasKey('content', $answer);
        self::assertArrayNotHasKey('requestIdentity', $answer);
        self::assertMatchesRegularExpression('/^v1:[a-f0-9]{64}$/D', $this->text($answer, 'contentDigest'));
        self::assertMatchesRegularExpression('/^v1:[a-f0-9]{64}$/D', $this->text($answer, 'requestDigest'));
        self::assertNotSame(self::IDENTITY, $this->text($answer, 'requestDigest'), 'the unkeyed identity is never stored as it is');
    }

    /**
     * Acceptance case 5: two runs that sent the same input show the same
     * digests, though the provider gave their tool calls other ids; a changed
     * input changes the digest of that part only.
     */
    #[Test]
    public function aChangedInputChangesTheDigestOfThatPartOnly(): void
    {
        $filter = $this->filter(PrivacyLevel::METADATA, true);

        $first  = $filter->filter($this->request('Which pages?', 'call_a'));
        $same   = $filter->filter($this->request('Which pages?', 'call_zz'));
        $change = $filter->filter($this->request('Which records?', 'call_a'));

        self::assertSame($this->messageDigests($first), $this->messageDigests($same));
        self::assertSame(
            $this->messageDigests($first),
            $this->messageDigests($filter->filter($this->request('Which pages?', 'call_a', true))),
            "the order of the keys in a tool call's encoded arguments does not count",
        );
        $before = array_column($this->messageDigests($first), 'digest');
        $after  = array_column($this->messageDigests($change), 'digest');
        self::assertSame([1], array_keys(array_diff_assoc($before, $after)), 'only the user message differs');

        $tool = $filter->filter($this->tool('Rows 1-3'));
        self::assertSame($this->text($tool, 'toolArgumentsDigest'), $this->text($filter->filter($this->tool('Rows 1-3', ['limit' => 3, 'pid' => 1])), 'toolArgumentsDigest'), 'the order of argument keys does not count');
        self::assertNotSame(
            $this->text($tool, 'toolResultDigest'),
            $this->text($filter->filter($this->tool('Rows 1-4')), 'toolResultDigest'),
        );
    }

    #[Test]
    public function noOtherLevelKeepsADigest(): void
    {
        foreach ([PrivacyLevel::NONE, PrivacyLevel::REDACTED] as $level) {
            $filter = $this->filter($level, true);
            foreach ([$this->request('Which pages?', 'call_a'), $this->tool('Rows'), $this->answer('Three.')] as $step) {
                $kept = $filter->filter($step);
                foreach ([...self::DIGEST_KEYS, 'requestIdentity'] as $key) {
                    self::assertArrayNotHasKey($key, $kept, $level->value);
                }
            }
        }

        $answer = $this->answer('Three.');
        self::assertSame($answer, $this->filter(PrivacyLevel::FULL, true)->filter($answer), 'full keeps the step as it is');
    }

    /**
     * A stored step filtered again (a run's level tightened, ADR-270) keeps
     * its digests only where they are still allowed.
     */
    #[Test]
    public function aStepFilteredAgainKeepsItsDigestsOnlyWhereTheyAreAllowed(): void
    {
        $stored = $this->filter(PrivacyLevel::METADATA, true)->filter($this->answer('Three pages.'));
        self::assertArrayHasKey('contentDigest', $stored);

        self::assertSame($stored, $this->filter(PrivacyLevel::METADATA, true)->filter($stored));
        foreach ([[PrivacyLevel::NONE, true], [PrivacyLevel::METADATA, false], [PrivacyLevel::REDACTED, true]] as [$level, $enabled]) {
            $again = $this->filter($level, $enabled)->filter($stored);
            foreach (self::DIGEST_KEYS as $key) {
                self::assertArrayNotHasKey($key, $again, $level->value);
            }
        }
    }

    #[Test]
    public function withoutAnEncryptionKeyThereIsNoDigest(): void
    {
        $this->setKey('');
        $kept = $this->filter(PrivacyLevel::METADATA, true)->filter($this->answer('Three pages.'));

        foreach ([...self::DIGEST_KEYS, 'requestIdentity', 'content'] as $key) {
            self::assertArrayNotHasKey($key, $kept);
        }
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return list<array{role: string, digest: string}>
     */
    private function messageDigests(array $step): array
    {
        $digests = $step['messagesSentDigests'] ?? null;
        self::assertIsArray($digests);
        $list = [];
        foreach ($digests as $digest) {
            self::assertIsArray($digest);
            self::assertIsString($digest['role'] ?? null);
            self::assertIsString($digest['digest'] ?? null);
            $list[] = ['role' => $digest['role'], 'digest' => $digest['digest']];
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function text(array $step, string $key): string
    {
        $value = $step[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function filter(PrivacyLevel $level, bool $digests): RunStepPrivacyFilter
    {
        $redactor = new ContentRedactor();

        return new RunStepPrivacyFilter(new FixedPrivacyPolicy($level, 30, $redactor, $digests), $redactor);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $question, string $callId, bool $reordered = false): array
    {
        return [
            'kind'         => 'request',
            'round'        => 2,
            'durationMs'   => 0.0,
            'messagesSent' => array_map(static fn (ChatMessage $m): array => $m->toArray(), [
                ChatMessage::system('You answer about pages.'),
                ChatMessage::user($question),
                ChatMessage::assistantToolCalls([ToolCall::function($callId, 'list_pages', $reordered ? ['pid' => 1, 'limit' => 3] : ['limit' => 3, 'pid' => 1])]),
                new ChatMessage('tool', 'Rows 1-3', toolCallId: $callId),
            ]),
            'toolSpecs' => ['list_pages'],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function tool(string $result, array $arguments = ['pid' => 1, 'limit' => 3]): array
    {
        return [
            'kind'          => 'tool',
            'round'         => 1,
            'durationMs'    => 0.0,
            'toolName'      => 'list_pages',
            'toolArguments' => $arguments,
            'toolResult'    => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $content): array
    {
        return [
            'kind'            => 'llm',
            'round'           => 2,
            'durationMs'      => 0.0,
            'content'         => $content,
            'requestIdentity' => self::IDENTITY,
        ];
    }

    private function setKey(string $key): void
    {
        $configuration              = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $system                     = is_array($configuration['SYS'] ?? null) ? $configuration['SYS'] : [];
        $system['encryptionKey']    = $key;
        $configuration['SYS']       = $system;
        $GLOBALS['TYPO3_CONF_VARS'] = $configuration;
    }
}
