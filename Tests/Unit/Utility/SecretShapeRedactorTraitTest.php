<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Utility;

use Netresearch\NrLlm\Tests\Unit\Utility\Fixtures\SecretShapeRedactorFixture;
use Netresearch\NrLlm\Utility\SecretShapeRedactorTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversTrait(SecretShapeRedactorTrait::class)]
final class SecretShapeRedactorTraitTest extends TestCase
{
    private SecretShapeRedactorFixture $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new SecretShapeRedactorFixture();
    }

    /**
     * Every secret shape the extension claims to know, with the material that
     * must not survive.
     *
     * The rows marked GAP are the ones that used to reach the database in
     * cleartext: the response guardrail masked them, the privacy redactor that
     * decides what gets PERSISTED did not.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function secretShapeProvider(): iterable
    {
        // The OpenAI mask keeps its 'sk-' prefix on purpose, so these assert on
        // the key body rather than the prefix.
        yield 'OpenAI legacy key' => ['sk-abcdefghijklmnopqrstuvwxyz012345', 'abcdefghijklmnop'];
        yield 'OpenAI project key (GAP)' => ['sk-proj-AbCdEf01234567890abcdefGHIJKL', 'AbCdEf01234567890'];
        yield 'GitHub PAT (GAP)' => ['ghp_' . self::fill('a', 36), 'ghp_'];
        yield 'GitHub fine-grained PAT (GAP)' => ['github_pat_' . self::fill('b', 30), 'github_pat_'];
        yield 'GitHub server token' => ['ghs_' . self::fill('c', 36), 'ghs_'];
        yield 'GitHub OAuth token' => ['gho_' . self::fill('d', 36), 'gho_'];
        yield 'AWS access key (GAP)' => ['AKIAIOSFODNN7EXAMPLE', 'AKIA'];
        yield 'Google API key (GAP)' => ['AIza' . self::fill('e', 35), 'AIza'];
        yield 'Slack bot token (GAP)' => ['xoxb-1234567890-abcdefghij', 'xoxb-'];
        yield 'JWT (GAP)' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcDEF123', 'eyJ'];
        yield 'Stripe secret key' => ['sk_live_' . self::fill('9', 24), 'sk_live_'];
        yield 'Stripe publishable key' => ['pk_test_' . self::fill('8', 24), 'pk_test_'];
        yield 'SendGrid key' => ['SG.' . self::fill('a', 22) . '.' . self::fill('b', 43), 'SG.'];
        yield 'Bearer header' => ['Authorization: Bearer abc.def-ghi_jkl+mno/pqr=', 'abc.def'];
        yield 'URL credential parameter' => ['https://api.example.com/v1?key=SUPERSECRET123&x=1', 'SUPERSECRET123'];
        yield 'connection string password' => ['postgres://user:hunter2@db.internal:5432/app', 'hunter2'];
        yield 'passwordless userinfo' => ['redis://:s3cr3tpw@cache:6379/0', 's3cr3tpw'];
    }

    #[Test]
    #[DataProvider('secretShapeProvider')]
    public function everyKnownShapeIsMasked(string $input, string $mustNotRemain): void
    {
        $redacted = $this->subject->redact($input);

        self::assertStringNotContainsString($mustNotRemain, $redacted);
        self::assertNotSame($input, $redacted);
    }

    #[Test]
    #[DataProvider('secretShapeProvider')]
    public function maskingIsIdempotent(string $input, string $mustNotRemain): void
    {
        $once = $this->subject->redact($input);

        self::assertSame($once, $this->subject->redact($once));
        self::assertStringNotContainsString($mustNotRemain, $once);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function innocuousProvider(): iterable
    {
        yield 'prose' => ['The quick brown fox jumps over the lazy dog.'];
        yield 'german prose' => ['Der Schlüssel liegt unter der Fußmatte.'];
        yield 'plain url' => ['https://example.com/page?id=42&sort=name'];
        yield 'md5 digest' => ['d41d8cd98f00b204e9800998ecf8427e'];
        yield 'uuid' => ['01937b6e-4b6c-7abc-8def-0123456789ab'];
        yield 'code' => ['$config = [\'timeout\' => 30];'];
        yield 'short sk- word' => ['sk-short'];
        yield 'model id' => ['claude-opus-4-5-20251101'];
        yield 'file path' => ['/var/www/html/typo3conf/ext/nr_llm/Classes'];
    }

    /**
     * A redactor that mangles ordinary text is worse than useless on a path that
     * rewrites prompts and model answers.
     */
    #[Test]
    #[DataProvider('innocuousProvider')]
    public function ordinaryTextIsUntouched(string $input): void
    {
        self::assertSame($input, $this->subject->redact($input));
    }

    #[Test]
    public function emailAddressesAreNotMasked(): void
    {
        // Deliberate: the guardrails rewrite prompts and responses, where removing
        // an address changes what the text says. ContentRedactor masks them for
        // storage as its own concern.
        $text = 'Write to person@example.com about it.';

        self::assertSame($text, $this->subject->redact($text));
    }

    #[Test]
    public function surroundingTextSurvivesInPlaceMasking(): void
    {
        $redacted = $this->subject->redact('key ghp_' . self::fill('a', 36) . ' then more text');

        self::assertStringStartsWith('key ', $redacted);
        self::assertStringEndsWith(' then more text', $redacted);
        self::assertStringNotContainsString('ghp_', $redacted);
    }

    /**
     * The fail-OPEN contract, for the guardrail path: `preg_replace()` returns null
     * when the engine gives up, and casting that to string yields '' — wiping the
     * model's whole response, which on a redaction path looks like a very thorough
     * redaction. The content must survive instead.
     */
    #[Test]
    public function aFailingRegexEngineKeepsTheContent(): void
    {
        $original = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            $text = 'Bearer ' . self::fill('a', 5000);

            // Precondition: the crushed limit really does make the engine give up
            // on this pattern. preg_replace() signals that by returning null and
            // setting preg_last_error(), silently — no diagnostic to suppress.
            self::assertNull(
                preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/\-]+=*/i', '$1***', $text),
                'Precondition: the crushed limit must make this pattern fail.',
            );
            self::assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());

            $result = $this->subject->redact($text);

            self::assertNotSame('', $result, 'Content was wiped when the engine failed.');
            self::assertSame($text, $result);
        } finally {
            ini_set('pcre.backtrack_limit', $original === false ? '1000000' : $original);
        }
    }

    /**
     * The fail-CLOSED contract, for the egress path: a value the redactor could
     * not fully inspect must be reported as such, so the caller can withhold it
     * rather than forward a possibly secret-bearing string to a provider.
     */
    #[Test]
    public function theStrictVariantReportsAFailedPattern(): void
    {
        $original = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            self::assertNull($this->subject->redactStrict('Bearer ' . self::fill('a', 5000)));
        } finally {
            ini_set('pcre.backtrack_limit', $original === false ? '1000000' : $original);
        }
    }

    #[Test]
    public function theStrictVariantBehavesLikeTheOpenOneWhenNothingFails(): void
    {
        $input = 'token ghp_' . self::fill('a', 36);

        self::assertSame($this->subject->redact($input), $this->subject->redactStrict($input));
    }

    #[Test]
    public function emptyContentStaysEmpty(): void
    {
        self::assertSame('', $this->subject->redact(''));
        self::assertSame('', $this->subject->redactStrict(''));
    }

    private static function fill(string $char, int $times): string
    {
        return str_repeat($char, $times);
    }

    /**
     * Credential query parameters that used to pass through untouched because the
     * name alternation had no prefix allowance. ``client_secret`` is the name
     * RFC 6749 §2.3.1 defines, so an OAuth client secret in a URL — which reaches
     * these paths through provider error messages — was written out verbatim.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function credentialParameterProvider(): iterable
    {
        yield 'client_secret' => ['https://idp.example/token?client_secret=s3cr3tvalue&x=1', 's3cr3tvalue'];
        yield 'password' => ['https://api.example/login?password=hunter2', 'hunter2'];
        yield 'api-key hyphenated' => ['https://api.example/v1?api-key=SUPERSECRET123', 'SUPERSECRET123'];
        yield 'refresh_token' => ['https://api.example/?refresh_token=rt_abcdef123456', 'rt_abcdef123456'];
        yield 'x_api_key vendor prefix' => ['https://api.example/?x_api_key=abc123def', 'abc123def'];
    }

    #[Test]
    #[DataProvider('credentialParameterProvider')]
    public function credentialQueryParametersAreMasked(string $input, string $secret): void
    {
        self::assertStringNotContainsString($secret, $this->subject->redact($input));
    }

    /**
     * The URL patterns must stop at structural characters. Unbounded, they ran
     * past the end of the URL and consumed the rest of the line.
     */
    #[Test]
    public function maskingAQueryParameterKeepsTheSurroundingPayload(): void
    {
        $redacted = $this->subject->redact('{"url":"https://x.example/?token=abc","next":"keepme"}');

        self::assertStringContainsString('"next":"keepme"', $redacted);
        self::assertStringNotContainsString('=abc', $redacted);
    }

    #[Test]
    public function maskingAQueryParameterKeepsTheFragment(): void
    {
        $redacted = $this->subject->redact('https://x.example/?token=abc#section-two');

        self::assertStringContainsString('#section-two', $redacted);
        self::assertStringNotContainsString('=abc', $redacted);
    }

    /**
     * A URL with a port, followed later by an unrelated address, is not a userinfo
     * component. Treating it as one deleted the port and everything up to the
     * address, and fabricated a credentialled URL to a host that was never
     * contacted — actively misleading whoever reads the redacted message.
     *
     * @return iterable<string, array{string}>
     */
    public static function notUserinfoProvider(): iterable
    {
        yield 'JSON url and contact' => ['{"url":"https://example.com:8080","contact":"support@example.org"}'];
        yield 'port comma address' => ['https://host.example:1234,mail@x.com'];
        yield 'port space address' => ['https://host.example:8080 mail@x.com'];
    }

    #[Test]
    #[DataProvider('notUserinfoProvider')]
    public function aPortIsNotMistakenForACredential(string $input): void
    {
        self::assertSame($input, $this->subject->redact($input));
    }

    /**
     * Bearer runs before the prefix-specific shapes, so a bearer-carried vendor
     * key collapses to ONE mask instead of 'Bearer ******'.
     */
    #[Test]
    public function aBearerCarriedVendorKeyCollapsesToASingleMask(): void
    {
        self::assertSame('Bearer ***', $this->subject->redact('Bearer sk-abcdefghijklmnopqrst'));
    }
}
