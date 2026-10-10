<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Error;
use Netresearch\NrLlm\Service\Skill\Exception\GitHubApiException;
use Netresearch\NrLlm\Service\Skill\Exception\HostNotAllowedException;
use Netresearch\NrLlm\Service\Skill\GitHubClient;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

#[CoversClass(GitHubClient::class)]
final class GitHubClientRequestContractTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    #[Test]
    public function commitRequestEncodesRefCharactersWithoutLosingItsSlash(): void
    {
        $client = $this->clientReturning(
            self::response('{"sha":"0123456789abcdef0123456789abcdef01234567"}'),
        );
        $actual = $client->resolveSha(
            'Owner-Org',
            'repository.name',
            'release/v2#notes%done',
            null,
        );

        self::assertSame('0123456789abcdef0123456789abcdef01234567', $actual);
        self::assertCount(1, $this->requests);
        self::assertSame('GET', $this->requests[0]->getMethod());
        self::assertSame(
            'https://api.github.com/repos/Owner-Org/repository.name/commits/release/v2%23notes%25done',
            (string)$this->requests[0]->getUri(),
        );
        self::assertSame(
            'application/vnd.github+json',
            $this->requests[0]->getHeaderLine('Accept'),
        );
        self::assertSame(
            'nr-llm-skills',
            $this->requests[0]->getHeaderLine('User-Agent'),
        );
    }

    #[Test]
    public function rawRequestPinsItsCommitAndEncodesEveryPathSegment(): void
    {
        $client = $this->clientReturning(self::response("Raw skill bytes.\n"));
        $body = $client->fetchRawBySha(
            'Owner-Org',
            'repository.name',
            '0123456789abcdef0123456789abcdef01234567',
            'skills/über #guide%/SKILL.md',
            null,
        );

        self::assertSame("Raw skill bytes.\n", $body);
        self::assertCount(1, $this->requests);
        self::assertSame(
            'https://raw.githubusercontent.com/Owner-Org/repository.name/0123456789abcdef0123456789abcdef01234567/skills/%C3%BCber%20%23guide%25/SKILL.md',
            (string)$this->requests[0]->getUri(),
        );
    }

    /**
     * @param list<string> $expectedReads
     */
    #[Test]
    #[DataProvider('authentication')]
    public function readsOnlyAConfiguredVaultReferenceAndUsesItsResolvedBearer(
        ?string $tokenUuid,
        ?string $storedToken,
        array $expectedReads,
        string $expectedAuthorization,
    ): void {
        $reads = [];
        $vault = self::createStub(VaultServiceInterface::class);
        $vault
            ->method('retrieve')
            ->willReturnCallback(
                static function (
                    string $reference,
                ) use (&$reads, $storedToken): ?string {
                    $reads[] = $reference;

                    return $storedToken;
                },
            );
        $body = $this
            ->clientReturning(self::response('catalogue'), $vault)
            ->fetchAllowedUrl(
                'https://raw.githubusercontent.com/owner/repository/main/index.json',
                $tokenUuid,
            );

        self::assertSame('catalogue', $body);
        self::assertSame($expectedReads, $reads);
        self::assertCount(1, $this->requests);
        self::assertSame(
            $expectedAuthorization,
            $this->requests[0]->getHeaderLine('Authorization'),
        );
    }

    /**
     * @return iterable<string, array{?string, ?string, list<string>, string}>
     */
    public static function authentication(): iterable
    {
        $reference = '01937b6e-4b6c-7abc-8def-0123456789ab';
        yield 'no reference' => [null, 'synthetic-token', [], ''];
        yield 'empty reference' => ['', 'synthetic-token', [], ''];
        yield 'missing stored value' => [$reference, null, [$reference], ''];
        yield 'empty stored value' => [$reference, '', [$reference], ''];
        yield 'resolved token' => [$reference, 'synthetic-token', [$reference], 'Bearer synthetic-token'];
    }

    #[Test]
    #[DataProvider('disallowedUrls')]
    public function deniesAnUnsupportedUrlBeforeVaultReadOrTransportContact(
        string $url,
    ): void {
        $reads = [];
        $vault = self::createStub(VaultServiceInterface::class);
        $vault
            ->method('retrieve')
            ->willReturnCallback(
                static function (string $reference) use (&$reads): string {
                    $reads[] = $reference;

                    return 'synthetic-token';
                },
            );
        $client = $this->clientReturning(self::response('unexpected'), $vault);
        $caught = null;
        try {
            $client->fetchAllowedUrl(
                $url,
                '01937b6e-4b6c-7abc-8def-0123456789ab',
            );
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(HostNotAllowedException::class, $caught);
        self::assertSame(1719500100, $caught->getCode());
        self::assertSame([], $reads);
        self::assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disallowedUrls(): iterable
    {
        yield 'HTTP' => ['http://api.github.com/repos/owner/repository'];
        yield 'FTP' => ['ftp://raw.githubusercontent.com/owner/repository/main/index.json'];
        yield 'external host' => ['https://example.com/index.json'];
        yield 'GitHub host suffix' => ['https://github.com.example/index.json'];
        yield 'GitHub username before external host' => ['https://github.com@example.com/index.json'];
        yield 'raw host suffix' => ['https://raw.githubusercontent.com.example/index.json'];
        yield 'invalid port' => ['https://github.com:99999/index.json'];
    }

    #[Test]
    public function defaultTransportUsesTheVaultClientWithItsSkillSyncReason(): void
    {
        $reasons = [];
        $requests = [];
        $httpCalls = 0;
        $transport = self::createStub(VaultHttpClientInterface::class);
        $transport
            ->method('withReason')
            ->willReturnCallback(
                static function (
                    string $reason,
                ) use (&$reasons, $transport): VaultHttpClientInterface {
                    $reasons[] = $reason;

                    return $transport;
                },
            );
        $transport
            ->method('sendRequest')
            ->willReturnCallback(
                static function (
                    RequestInterface $request,
                ) use (&$requests): ResponseInterface {
                    $requests[] = $request;

                    return self::response('vault-transport-body');
                },
            );
        $vault = self::createStub(VaultServiceInterface::class);
        $vault
            ->method('http')
            ->willReturnCallback(
                static function () use (&$httpCalls, $transport): VaultHttpClientInterface {
                    $httpCalls++;

                    return $transport;
                },
            );
        $client = new GitHubClient($vault, $this->requestFactory(), new NullLogger());
        $body = $client->fetchAllowedUrl(
            'https://api.github.com/repos/owner/repository',
            null,
        );

        self::assertSame('vault-transport-body', $body);
        self::assertSame(1, $httpCalls);
        self::assertSame(['nr-llm skill sync'], $reasons);
        self::assertCount(1, $requests);
        self::assertSame(
            'https://api.github.com/repos/owner/repository',
            (string)$requests[0]->getUri(),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('httpFailures')]
    public function rejectsHttpFailuresAndPreservesRateLimitMetadata(
        int $status,
        array $headers,
        bool $rateLimit,
        int $expectedStatus,
        int $expectedCode,
    ): void {
        $client = $this->clientReturning(
            self::response('failure-body', $status, $headers),
        );
        $caught = null;
        try {
            $client->fetchAllowedUrl(
                'https://api.github.com/repos/owner/repository',
                null,
            );
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(GitHubApiException::class, $caught);
        self::assertSame($rateLimit, $caught->isRateLimit);
        self::assertSame($expectedStatus, $caught->status);
        self::assertSame($expectedCode, $caught->getCode());
        self::assertCount(1, $this->requests);
        if ($rateLimit) {
            self::assertSame(
                'GitHub API rate limit exceeded; resets at 4711',
                $caught->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{int, array<string, string>, bool, int, int}>
     */
    public static function httpFailures(): iterable
    {
        foreach ([300, 301, 302, 307, 308, 400, 403, 500] as $status) {
            yield 'HTTP ' . $status => [$status, [], false, $status, 1719500101];
        }

        yield '429' => [429, ['X-RateLimit-Reset' => '4711'], true, 429, 1719500102];
        yield 'primary exhaustion' => [
            403,
            ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '4711'],
            true,
            429,
            1719500102,
        ];
        yield 'secondary Retry-After' => [
            403,
            ['Retry-After' => '1', 'X-RateLimit-Reset' => '4711'],
            true,
            429,
            1719500102,
        ];
        yield '500 Retry-After is not a rate limit' => [500, ['Retry-After' => '1'], false, 500, 1719500101];
    }

    #[Test]
    public function status299StillReturnsItsUnmodifiedBody(): void
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write("last success status\n");
        $stream->rewind();

        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(299);
        $response->method('getBody')->willReturn($stream);
        $body = $this
            ->clientReturning($response)
            ->fetchAllowedUrl('https://api.github.com/repos/owner/repository', null);

        self::assertSame("last success status\n", $body);
        self::assertCount(1, $this->requests);
    }

    #[Test]
    #[DataProvider('malformedJsonBodies')]
    public function malformedJsonProducesTheTypedErrorAndBoundedDiagnostic(
        string $body,
        string $expectedSample,
        string $expectedMessage,
    ): void {
        $warnings = [];
        $logger = self::createStub(LoggerInterface::class);
        $logger
            ->method('warning')
            ->willReturnCallback(
                static function (
                    string $message,
                    array $context,
                ) use (&$warnings): void {
                    $warnings[] = ['message' => $message, 'context' => $context];
                },
            );
        $client = $this->clientReturning(self::response($body), null, $logger);
        $caught = null;
        try {
            $client->resolveSha('owner', 'repository', 'main', null);
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(GitHubApiException::class, $caught);
        self::assertSame(1751280201, $caught->getCode());
        self::assertFalse($caught->isRateLimit);
        self::assertSame(0, $caught->status);
        self::assertCount(1, $warnings);
        self::assertSame($expectedMessage, $warnings[0]['message']);
        self::assertSame($expectedSample, $warnings[0]['context']['sample']);
        self::assertSame(
            'https://api.github.com/repos/owner/repository/commits/main',
            $warnings[0]['context']['url'],
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedJsonBodies(): iterable
    {
        yield 'overlong invalid JSON' => [
            '{' . str_repeat('x', 250),
            '{' . str_repeat('x', 199),
            'GitHub API response was not valid JSON',
        ];
        foreach (['null', 'false', '42', '"scalar"'] as $body) {
            yield 'scalar ' . $body => [$body, $body, 'GitHub API response was not a JSON object'];
        }
    }

    private function clientReturning(
        ResponseInterface $response,
        ?VaultServiceInterface $vault = null,
        ?LoggerInterface $logger = null,
    ): GitHubClient {
        $client = new GitHubClient(
            $vault ?? self::createStub(VaultServiceInterface::class),
            $this->requestFactory(),
            $logger ?? new NullLogger(),
        );
        $transport = self::createStub(ClientInterface::class);
        $transport
            ->method('sendRequest')
            ->willReturnCallback(
                function (
                    RequestInterface $request,
                ) use ($response): ResponseInterface {
                    $this->requests[] = $request;

                    return $response;
                },
            );
        $client->setHttpClient($transport);

        return $client;
    }

    private function requestFactory(): RequestFactory
    {
        return new RequestFactory(new GuzzleClientFactory());
    }

    /**
     * @param array<string, string> $headers
     */
    private static function response(
        string $body,
        int $status = 200,
        array $headers = [],
    ): ResponseInterface {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();

        $response = (new Response())->withStatus($status)->withBody($stream);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * @param class-string<Throwable> $loggerFailureClass
     */
    #[Test]
    #[DataProvider('malformedDiagnosticFailures')]
    public function loggerFailureDoesNotReplaceTheMalformedResponseError(
        string $body,
        string $loggerFailureClass,
    ): void {
        $failure = new $loggerFailureClass('synthetic diagnostic failure');
        $warnings = [];
        $logger = self::createStub(LoggerInterface::class);
        $logger
            ->method('warning')
            ->willReturnCallback(
                static function (
                    string $message,
                    array $context,
                ) use (&$warnings, $failure): never {
                    $warnings[] = ['message' => $message, 'context' => $context];
                    throw $failure;
                },
            );
        $client = $this->clientReturning(self::response($body), null, $logger);
        $caught = null;
        try {
            $client->resolveSha('owner', 'repository', 'main', null);
        } catch (Throwable $error) {
            $caught = $error;
        }

        self::assertInstanceOf(GitHubApiException::class, $caught);
        self::assertSame(1751280201, $caught->getCode());
        self::assertSame(
            'GitHub API response from "https://api.github.com/repos/owner/repository/commits/main" was not valid JSON',
            $caught->getMessage(),
        );
        self::assertFalse($caught->isRateLimit);
        self::assertSame(0, $caught->status);
        self::assertCount(1, $this->requests);
        self::assertCount(1, $warnings);
        self::assertSame($body, $warnings[0]['context']['sample']);
        self::assertSame(
            'https://api.github.com/repos/owner/repository/commits/main',
            $warnings[0]['context']['url'],
        );
    }

    /**
     * @return iterable<string, array{string, class-string<Throwable>}>
     */
    public static function malformedDiagnosticFailures(): iterable
    {
        yield 'malformed JSON / RuntimeException' => ['{', RuntimeException::class];
        yield 'malformed JSON / Error' => ['{', Error::class];
        yield 'non-object JSON / RuntimeException' => ['null', RuntimeException::class];
        yield 'non-object JSON / Error' => ['null', Error::class];
    }
}
