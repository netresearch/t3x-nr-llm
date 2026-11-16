<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\ValueObject\ObservedState;
use Netresearch\NrLlm\Service\Tool\InvocationDigests;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The canonical form, version 1, and the keying of the history digests
 * (ADR-298). The record read behind a state digest is covered functionally.
 */
#[CoversClass(InvocationDigests::class)]
#[CoversClass(ObservedState::class)]
final class InvocationDigestsTest extends TestCase
{
    private const KEY = 'test-encryption-key';

    private mixed $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $this->setKey(self::KEY);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = $this->configuration;
        parent::tearDown();
    }

    /**
     * The digest is the HMAC-SHA256 of the canonical JSON, keyed with the
     * encryption key joined with the purpose, as TYPO3's HashService joins
     * them. Pinned literally, so a change of the form is seen.
     */
    #[Test]
    public function theDigestIsTheKeyedHmacOfTheCanonicalJson(): void
    {
        self::assertSame(
            'v1:' . hash_hmac('sha256', '{"a":"x/ü","b":[2,1],"c":{"d":1,"e":null}}', self::KEY . 'nr_llm.history.arguments'),
            $this->digests()->arguments(['c' => ['e' => null, 'd' => 1.0], 'b' => [2, 1], 'a' => 'x/ü']),
        );
        self::assertMatchesRegularExpression(ObservedState::DIGEST_PATTERN, (string) $this->digests()->arguments([]));
    }

    /**
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     */
    #[Test]
    #[DataProvider('equalArguments')]
    public function argumentsThatDifferOnlyInFormHaveOneDigest(array $left, array $right): void
    {
        self::assertSame($this->digests()->arguments($left), $this->digests()->arguments($right));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function equalArguments(): iterable
    {
        yield 'key order' => [['uid' => 1, 'description' => 'x'], ['description' => 'x', 'uid' => 1]];
        yield 'nested key order' => [['a' => ['y' => 1, 'x' => 2]], ['a' => ['x' => 2, 'y' => 1]]];
        yield 'a float without a fraction is the integer' => [['n' => 1.0], ['n' => 1]];
        yield 'minus zero is zero' => [['n' => -0.0], ['n' => 0]];
        yield 'below 2^53' => [['n' => 9007199254740991.0], ['n' => 9007199254740991]];
        // From 2^53 on a float keeps its JSON float form, and PHP writes that
        // form without a fraction up to 1e17: the integer a resume makes of it
        // has the same digest, which is what a resume needs.
        yield '2^53 in its JSON float form' => [['n' => 9007199254740992.0], ['n' => 9007199254740992]];
    }

    /**
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     */
    #[Test]
    #[DataProvider('differentArguments')]
    public function argumentsThatDifferInValueHaveDifferentDigests(array $left, array $right): void
    {
        self::assertNotSame($this->digests()->arguments($left), $this->digests()->arguments($right));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>}>
     */
    public static function differentArguments(): iterable
    {
        yield 'list order' => [['a' => [1, 2]], ['a' => [2, 1]]];
        yield 'a list and an object with the same keys' => [['a' => ['x', 'y']], ['a' => [1 => 'y', 0 => 'x']]];
        yield 'an integer and a numeric string' => [['uid' => 1], ['uid' => '1']];
        yield 'a fraction' => [['n' => 1.5], ['n' => 1]];
        yield 'a float with an exponent stays a float' => [['n' => 1.0e20], ['n' => '1.0e+20']];
        yield 'one value' => [['description' => 'Gut.'], ['description' => 'Besser.']];
    }

    /**
     * A list is decided before the keys are sorted: an object whose keys are
     * 0 and 1 out of order stays an object, and every float that is not
     * written as an integer keeps its JSON float form.
     */
    #[Test]
    public function anObjectWithIntegerKeysStaysAnObjectAndFloatsKeepTheirForm(): void
    {
        self::assertSame(
            'v1:' . hash_hmac('sha256', '{"a":{"0":"x","1":"y"},"big":1.0e+20,"half":0.5,"tenth":0.1}', self::KEY . 'nr_llm.history.arguments'),
            $this->digests()->arguments(['a' => [1 => 'y', 0 => 'x'], 'half' => 0.5, 'tenth' => 0.1, 'big' => 1.0e20]),
        );
    }

    /**
     * The float form does not depend on the process's precision setting, and
     * the setting is restored.
     */
    #[Test]
    public function theFloatFormIgnoresTheConfiguredPrecision(): void
    {
        $expected = $this->digests()->arguments(['n' => 0.1]);
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '17');

        try {
            self::assertSame($expected, $this->digests()->arguments(['n' => 0.1]));
            self::assertSame('17', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
        }
    }

    #[Test]
    public function slashesAndUnicodeAreNotEscaped(): void
    {
        self::assertSame(
            'v1:' . hash_hmac('sha256', '{"t":"a/b ü €"}', self::KEY . 'nr_llm.history.arguments'),
            $this->digests()->arguments(['t' => 'a/b ü €']),
        );
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    #[Test]
    #[DataProvider('unencodableArguments')]
    public function aValueThatCannotBeEncodedHasNoDigest(array $arguments): void
    {
        self::assertNull($this->digests()->arguments($arguments));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function unencodableArguments(): iterable
    {
        yield 'infinity' => [['n' => INF]];
        yield 'not a number' => [['n' => NAN]];
        yield 'invalid UTF-8' => [['t' => "\xB1\x31"]];
        yield 'an object' => [['o' => new stdClass()]];
        yield 'a key starting with NUL' => [["\0x" => 1]];
    }

    /**
     * A cyclic array and one nested deeper than JSON allows have no canonical
     * form; the digest ends with null instead of recursing without end.
     */
    #[Test]
    public function aCyclicOrTooDeepArrayHasNoDigest(): void
    {
        $cyclic         = [];
        $cyclic['self'] = &$cyclic;
        self::assertNull($this->digests()->arguments($cyclic));

        $deep = ['n' => 'leaf'];
        for ($i = 0; $i < 600; ++$i) {
            $deep = ['n' => $deep];
        }

        self::assertNull($this->digests()->arguments($deep));
    }

    #[Test]
    public function anEmptyEncryptionKeyGivesNoDigest(): void
    {
        $this->setKey('');
        self::assertNull($this->digests()->arguments(['uid' => 1]));

        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
        self::assertNull($this->digests()->arguments(['uid' => 1]));
    }

    /**
     * Each digest has its own purpose, so an arguments digest never equals a
     * state digest of the same value; and another key gives another digest.
     */
    #[Test]
    public function thePurposeAndTheKeySeparateDigests(): void
    {
        $digest = $this->digests()->arguments(['uid' => 1]);

        self::assertSame('v1:' . hash_hmac('sha256', '{"uid":1}', self::KEY . InvocationDigests::PURPOSE_ARGUMENTS), $digest);
        self::assertNotSame('v1:' . hash_hmac('sha256', '{"uid":1}', self::KEY . InvocationDigests::PURPOSE_STATE), $digest);
        self::assertNotSame(InvocationDigests::PURPOSE_ARGUMENTS, InvocationDigests::PURPOSE_STATE);

        $this->setKey('another-key');
        self::assertNotSame($digest, $this->digests()->arguments(['uid' => 1]));
    }

    #[Test]
    public function anObservedStateTakesOnlyAVersionOneDigest(): void
    {
        $digest = 'v1:' . str_repeat('0a', 32);
        $state  = new ObservedState($digest, ['description' => null]);

        self::assertSame($digest, $state->digest);
        self::assertSame(['description' => null], $state->values);

        foreach (['', 'v1:' . str_repeat('A', 64), 'v2:' . str_repeat('a', 64), 'v1:' . str_repeat('a', 63), 'v1:' . str_repeat('a', 64) . "\n"] as $invalid) {
            try {
                $accepted = new ObservedState($invalid, []);
                self::fail('Accepted ' . var_export($accepted->digest, true));
            } catch (InvalidArgumentException) {
                // refused, as it must be
            }
        }
    }

    /**
     * observe() refuses what it can tell without a database: an empty field
     * list, a uid below 1 and a table the TCA does not know.
     */
    #[Test]
    public function anObservationWithoutARecordToReadIsNull(): void
    {
        $tca            = $GLOBALS['TCA'] ?? null;
        $GLOBALS['TCA'] = ['pages' => ['ctrl' => []]];

        try {
            $execution = ToolExecutionContext::none();
            self::assertNull($this->digests()->observe('pages', 42, [], $execution));
            self::assertNull($this->digests()->observe('pages', 0, ['title'], $execution));
            self::assertNull($this->digests()->observe('tx_unknown', 42, ['title'], $execution));
        } finally {
            $GLOBALS['TCA'] = $tca;
        }
    }

    private function digests(): InvocationDigests
    {
        return new InvocationDigests(self::createStub(ConnectionPool::class));
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
