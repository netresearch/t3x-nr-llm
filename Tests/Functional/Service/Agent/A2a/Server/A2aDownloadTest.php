<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileCapability;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileInputPreparer;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileSettings;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aFileStore;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aInboundFilePolicy;
use Netresearch\NrLlm\Service\Agent\A2a\File\A2aOutboundFileArtifacts;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aFileDownload;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClientRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aResumeGuard;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerMiddleware;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Agent\AgentRunExecutor;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\File\A2aFileValidatorTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Files the server role sends (spec 051 A2A-FILE5, ADR-284): inline up to
 * `a2a.maxInlineArtifactBytes`, larger as a URL to a protected download,
 * read with the client's bearer token or a short-lived capability URL
 * scoped to one file and one client; issuing a capability changes nothing
 * of the task.
 *
 * A file is registered against a task's run while it runs
 * ({@see A2aFileStore::storeOutboundForRun()}) and becomes a part of the
 * task's artifacts when the run completes. The inline limit is 1024 bytes
 * here.
 */
#[CoversClass(A2aOutboundFileArtifacts::class)]
#[CoversClass(A2aFileDownload::class)]
#[CoversClass(A2aFileCapability::class)]
#[CoversClass(A2aFileStore::class)]
#[CoversClass(A2aServerMiddleware::class)]
#[CoversClass(A2aTaskStore::class)]
final class A2aDownloadTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;

    private const INLINE = 1024;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServer();
        $this->fileSettings(true);
    }

    protected function tearDown(): void
    {
        $this->serverSettings(null);
        $this->fileSettings(null);
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
        parent::tearDown();
    }

    /**
     * A2A-FILE5: a file up to the inline limit goes out as `raw`, with its
     * type, name, and the file's id, digest and size in the part's metadata.
     */
    #[Test]
    public function aSmallFileGoesOutInline(): void
    {
        $bytes             = '{"order": 4711}';
        [$taskId, $fileId] = $this->completedWith([[$bytes, 'application/json', 'order.json']]);

        $part = $this->filePart($taskId, $fileId);
        self::assertSame([base64_encode($bytes), 'application/json', 'order.json'], [$part['raw'] ?? null, $part['mediaType'] ?? null, $part['filename'] ?? null]);
        self::assertArrayNotHasKey('url', $part);
        self::assertSame(['fileId' => $fileId, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)], self::objectOf($part['metadata'] ?? null)[A2aInboundFilePolicy::FILE_EXTENSION] ?? null);
    }

    /**
     * A2A-FILE5: a larger file goes out as a URL below the A2A endpoint the
     * task was accepted at, never one of the installation's own storage. The
     * client's bearer token reads it; another client's token and an unknown
     * file get the same answer, and no token gets 401.
     */
    #[Test]
    public function aLargerFileGoesOutAsAProtectedDownload(): void
    {
        $bytes             = str_repeat("A line of the report.\n", 100);
        [$taskId, $fileId] = $this->completedWith([[$bytes, 'text/plain', 'report.txt']]);

        $part = $this->filePart($taskId, $fileId);
        self::assertArrayNotHasKey('raw', $part);
        self::assertSame(self::BASE . '/files/' . $fileId, $part['url'] ?? null);
        foreach (['fileadmin', '/typo3/', '_assets', 'eID=', '/uploads/'] as $storage) {
            self::assertStringNotContainsString($storage, self::text($part['url'] ?? ''));
        }

        $download = $this->fetch(self::BASE . '/files/' . $fileId, 1);
        self::assertSame(200, $download->getStatusCode());
        self::assertSame($bytes, (string) $download->getBody());
        self::assertSame(
            ['text/plain', 'nosniff', 'no-store', 'attachment; filename="report.txt"', '"' . hash('sha256', $bytes) . '"', (string) strlen($bytes)],
            [$download->getHeaderLine('Content-Type'), $download->getHeaderLine('X-Content-Type-Options'), $download->getHeaderLine('Cache-Control'), $download->getHeaderLine('Content-Disposition'), $download->getHeaderLine('ETag'), $download->getHeaderLine('Content-Length')],
        );

        $head = $this->middleware()->process($this->request('HEAD', self::BASE . '/files/' . $fileId, ['Authorization' => 'Bearer ' . $this->tokens[1]]), $this->passThrough());
        self::assertSame([200, '', (string) strlen($bytes)], [$head->getStatusCode(), (string) $head->getBody(), $head->getHeaderLine('Content-Length')]);

        $foreign = $this->fetch(self::BASE . '/files/' . $fileId, 2);
        $unknown = $this->fetch(self::BASE . '/files/00000000-0000-4000-8000-000000000000', 1);
        self::assertSame([404, 404], [$foreign->getStatusCode(), $unknown->getStatusCode()]);
        self::assertSame((string) $unknown->getBody(), (string) $foreign->getBody(), "another client's file and an unknown one answer alike");
        self::assertSame($unknown->getHeaders(), $foreign->getHeaders());

        self::assertNull($this->getService(A2aFileStore::class)->outboundFile($fileId, 2, time()), "the store reads only the owner's file");
        self::assertNotNull($this->getService(A2aFileStore::class)->outboundFile($fileId, 1, time()));

        $anonymous = $this->fetch(self::BASE . '/files/' . $fileId, null);
        self::assertSame(401, $anonymous->getStatusCode());
        self::assertStringContainsString('Bearer', $anonymous->getHeaderLine('WWW-Authenticate'));
        self::assertStringNotContainsString('A line of the report', (string) $anonymous->getBody());
    }

    /**
     * A2A-FILE5: the inline limit holds for all files of a task together;
     * what does not fit goes out as a download.
     */
    #[Test]
    public function theInlineLimitHoldsForAllFilesOfATask(): void
    {
        $first                         = str_repeat('a', 700);
        $second                        = str_repeat('b', 700);
        [$taskId, $firstId, $secondId] = $this->completedWith([[$first, 'text/plain', 'a.txt'], [$second, 'text/plain', 'b.txt']]);

        self::assertSame(base64_encode($first), $this->filePart($taskId, $firstId)['raw'] ?? null);
        self::assertSame(self::BASE . '/files/' . $secondId, $this->filePart($taskId, $secondId)['url'] ?? null);
    }

    /**
     * A2A-FILE5: a capability URL reads one file of one client without the
     * bearer token, until it expires; on another file, after expiry, after
     * the client was disabled or its token replaced, it reads nothing.
     */
    #[Test]
    public function aCapabilityUrlReadsOneFileOfOneClientUntilItExpires(): void
    {
        $bytes                       = str_repeat("Capability.\n", 200);
        [$taskId, $fileId, $otherId] = $this->completedWith([[$bytes, 'text/plain', 'one.txt'], [str_repeat('x', 2000), 'text/plain', 'two.txt']]);
        self::assertNotSame('', $taskId);

        $url = $this->capabilityUrl($fileId, 1);
        self::assertStringStartsWith(self::BASE . '/files/' . $fileId . '?capability=', $url);
        $read = $this->fetch($url, null);
        self::assertSame([200, $bytes], [$read->getStatusCode(), (string) $read->getBody()]);

        $token = substr($url, (int) strpos($url, '?'));
        self::assertSame(401, $this->fetch(self::BASE . '/files/' . $otherId . $token, null)->getStatusCode(), 'scoped to one file');
        self::assertSame(401, $this->fetch(self::BASE . '/files/' . $fileId . $token . 'x', null)->getStatusCode(), 'a changed token');

        $expired = $this->getService(A2aFileCapability::class)->issue($fileId, 1, $this->tokenHashOf(1), time() - A2aFileCapability::LIFETIME_SECONDS - 1);
        self::assertSame(401, $this->fetch(self::BASE . '/files/' . $fileId . '?capability=' . $expired['token'], null)->getStatusCode(), 'expired');

        $this->tokens[1] = $this->getService(A2aInboundClientRepository::class)->issueToken(1);
        self::assertSame(401, $this->fetch($url, null)->getStatusCode(), "the client's token was replaced");

        $fresh = $this->capabilityUrl($fileId, 1);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['enabled' => 0], ['uid' => 1]);
        self::assertSame(401, $this->fetch($fresh, null)->getStatusCode(), 'the client was disabled');
    }

    /**
     * A2A-FILE5, A2A-SEC2: another client cannot have a capability issued for
     * a file it does not own; the answer is the unknown file's.
     */
    #[Test]
    public function aCapabilityIsIssuedOnlyToTheFilesClient(): void
    {
        [, $fileId] = $this->completedWith([[str_repeat('y', 2000), 'text/plain', 'y.txt']]);

        $foreign = $this->postTo(self::BASE . '/files/' . $fileId . '/capability', 2);
        $unknown = $this->postTo(self::BASE . '/files/00000000-0000-4000-8000-000000000000/capability', 1);
        self::assertSame([404, 404], [$foreign->getStatusCode(), $unknown->getStatusCode()]);
        self::assertSame((string) $unknown->getBody(), (string) $foreign->getBody());
    }

    /**
     * A2A-FILE5: issuing a download right again keeps the task's status,
     * the artifact id, the digest and the content; it writes no event.
     */
    #[Test]
    public function reIssuingADownloadRightChangesNothingOfTheTask(): void
    {
        $bytes             = str_repeat("Kept.\n", 400);
        [$taskId, $fileId] = $this->completedWith([[$bytes, 'text/plain', 'kept.txt']]);
        $before            = $this->taskRow($taskId);
        $events            = $this->rows('tx_nrllm_a2a_event');
        $task              = $this->taskOf($this->call('GetTask', ['id' => $taskId]));

        $first  = $this->capabilityUrl($fileId, 1);
        $second = $this->capabilityUrl($fileId, 1);

        $after = $this->taskRow($taskId);
        foreach (['revision', 'state', 'status_timestamp', 'artifacts', 'tstamp'] as $column) {
            self::assertSame($before[$column], $after[$column], $column);
        }

        self::assertSame($events, $this->rows('tx_nrllm_a2a_event'));
        self::assertSame($task, $this->taskOf($this->call('GetTask', ['id' => $taskId])));
        self::assertSame(hash('sha256', $bytes), hash('sha256', (string) $this->fetch($first, null)->getBody()));
        self::assertSame(hash('sha256', $bytes), hash('sha256', (string) $this->fetch($second, null)->getBody()));
    }

    /**
     * Privacy (ADR-270, ADR-282): a file is downloadable only while the
     * task's artifacts name it under the run's current answer level. A
     * tightening after completion makes it unreadable.
     */
    #[Test]
    public function aTighteningAfterCompletionWithdrawsTheDownload(): void
    {
        [$taskId, $fileId] = $this->completedWith([[str_repeat('z', 2000), 'text/plain', 'z.txt']]);
        self::assertSame(200, $this->fetch(self::BASE . '/files/' . $fileId, 1)->getStatusCode());
        $url = $this->capabilityUrl($fileId, 1);

        $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['privacy_level' => 'metadata'], ['uid' => 1]);

        self::assertSame(404, $this->fetch(self::BASE . '/files/' . $fileId, 1)->getStatusCode());
        self::assertSame(404, $this->fetch($url, null)->getStatusCode());
        self::assertArrayNotHasKey('artifacts', $this->taskOf($this->call('GetTask', ['id' => $taskId])));
    }

    /**
     * A tightening to `redacted` after completion removes the file parts
     * from the artifacts (A2aContentRedaction), and with them the download.
     */
    #[Test]
    public function aTighteningToRedactedAfterCompletionWithdrawsTheFile(): void
    {
        [$taskId, $small, $large] = $this->completedWith([['{"b": 2}', 'application/json', 'b.json'], [str_repeat('r', 2000), 'text/plain', 'r.txt']]);
        self::assertSame(200, $this->fetch(self::BASE . '/files/' . $large, 1)->getStatusCode());

        $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['privacy_level' => 'redacted'], ['uid' => 1]);

        $json = (string) json_encode($this->taskOf($this->call('GetTask', ['id' => $taskId])), JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(base64_encode('{"b": 2}'), $json);
        self::assertStringNotContainsString('/files/', $json);
        self::assertStringNotContainsString($small, $json);
        self::assertStringContainsString('A file was removed', $json);
        self::assertSame(404, $this->fetch(self::BASE . '/files/' . $large, 1)->getStatusCode());
    }

    /**
     * @return iterable<string, array{string|null, int}>
     */
    public static function inlineLimits(): iterable
    {
        yield 'unset' => [null, 262144];
        yield 'above the largest' => ['9999999', 524288];
        yield 'zero' => ['0', 0];
        yield 'not a number' => ['many', 262144];
    }

    /**
     * A2A-FILE5: the inline limit has a default, a largest value, and 0.
     */
    #[Test]
    #[DataProvider('inlineLimits')]
    public function theInlineLimitIsBounded(?string $configured, int $expected): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['a2a']['maxInlineArtifactBytes']);
        if ($configured !== null) {
            // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['a2a']['maxInlineArtifactBytes'] = $configured;
        }

        self::assertSame($expected, $this->getService(A2aFileSettings::class)->maxInlineArtifactBytes());
    }

    /**
     * A2A-FILE5: with an inline limit of 0, even a small file is a download.
     */
    #[Test]
    public function withNoInlineLimitEveryFileIsADownload(): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['a2a']['maxInlineArtifactBytes'] = '0';
        [$taskId, $fileId]                                                                   = $this->completedWith([['{"c": 3}', 'application/json', 'c.json']]);

        self::assertSame(self::BASE . '/files/' . $fileId, $this->filePart($taskId, $fileId)['url'] ?? null);
    }

    /**
     * A run kept at the `redacted` level sends no file: a file cannot be
     * redacted, so the artifact says that one was withheld.
     */
    #[Test]
    public function aRedactedRunSendsNoFile(): void
    {
        $this->db('tx_nrllm_provider')->update('tx_nrllm_provider', ['privacy_level' => 'redacted'], ['uid' => 1]);
        [$taskId, $small, $large] = $this->completedWith([['{"a": 1}', 'application/json', 'a.json'], [str_repeat('w', 2000), 'text/plain', 'w.txt']]);

        $json = (string) json_encode($this->taskOf($this->call('GetTask', ['id' => $taskId])), JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(base64_encode('{"a": 1}'), $json);
        self::assertStringNotContainsString('/files/', $json);
        self::assertStringContainsString('2 files were withheld', $json);
        self::assertSame(404, $this->fetch(self::BASE . '/files/' . $large, 1)->getStatusCode());
        self::assertNotSame('', $small);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedOutboundFiles(): iterable
    {
        yield 'html' => ['<p>x</p>', 'text/html'];
        yield 'svg' => ['<svg/>', 'image/svg+xml'];
        yield 'png declared as jpeg' => [A2aFileValidatorTest::png(), 'image/jpeg'];
        yield 'json that does not parse' => ['{"a":', 'application/json'];
    }

    /**
     * A2A-FILE1: a file is emitted only after its type's check passed.
     */
    #[Test]
    #[DataProvider('refusedOutboundFiles')]
    public function aFileThatFailsItsCheckIsNotSent(string $bytes, string $mediaType): void
    {
        $taskId = self::text($this->taskOf($this->call('SendMessage', ['message' => $this->message('Report.'), 'configuration' => ['returnImmediately' => true]]))['id'] ?? '');

        $this->expectException(A2aException::class);
        $this->getService(A2aFileStore::class)->storeOutboundForRun((int) $this->taskRow($taskId)['run_uid'], 'test', $bytes, $mediaType, 'out.bin', time());
    }

    /**
     * A file larger than `a2a.maxRawPartBytes` is not sent: its sealed row
     * must fit one database packet.
     */
    #[Test]
    public function aFileAboveTheFileLimitIsNotSent(): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['a2a']['maxRawPartBytes'] = '1024';
        $taskId                                                                       = self::text($this->taskOf($this->call('SendMessage', ['message' => $this->message('Report.'), 'configuration' => ['returnImmediately' => true]]))['id'] ?? '');

        $this->expectException(A2aException::class);
        $this->expectExceptionMessage('more than 1024 bytes');
        $this->getService(A2aFileStore::class)->storeOutboundForRun((int) $this->taskRow($taskId)['run_uid'], 'test', str_repeat('t', 1025), 'text/plain', 'big.txt', time());
    }

    /**
     * A stored file whose bytes no longer match its digest is not sent.
     */
    #[Test]
    public function aFileThatChangedIsNotDownloaded(): void
    {
        [, $fileId] = $this->completedWith([[str_repeat('c', 2000), 'text/plain', 'c.txt']]);
        $this->db(A2aFileStore::TABLE)->update(A2aFileStore::TABLE, ['content' => $this->getService(AgentStateCodec::class)->encode(str_repeat('d', 2000), AgentStateCodec::PURPOSE_A2A_FILE)], ['file_id' => $fileId]);

        $download = $this->fetch(self::BASE . '/files/' . $fileId, 1);
        self::assertSame(500, $download->getStatusCode());
        self::assertStringNotContainsString('dddd', (string) $download->getBody());
    }

    /**
     * A capability is issued only with the bearer token, never with a
     * capability.
     */
    #[Test]
    public function aCapabilityIsNotIssuedWithACapability(): void
    {
        [, $fileId] = $this->completedWith([[str_repeat('k', 2000), 'text/plain', 'k.txt']]);
        $url        = $this->capabilityUrl($fileId, 1);

        $issued = $this->middleware()->process($this->request('POST', self::BASE . '/files/' . $fileId . '/capability' . substr($url, (int) strpos($url, '?')), []), $this->passThrough());
        self::assertSame(401, $issued->getStatusCode());
    }

    /**
     * A file the run sends is no input: the worker's check of the input
     * files ignores it.
     */
    #[Test]
    public function aSentFileIsNotAnInputFile(): void
    {
        $message = ['messageId' => bin2hex(random_bytes(8)), 'role' => 'ROLE_USER', 'parts' => [['text' => 'Read it.'], ['raw' => base64_encode('Input.'), 'mediaType' => 'text/plain']]];
        $reply   = $this->call('SendMessage', ['message' => $message, 'configuration' => ['returnImmediately' => true]]);
        $taskId  = self::text($this->taskOf($reply)['id'] ?? '');
        $this->getService(A2aFileStore::class)->storeOutboundForRun((int) $this->taskRow($taskId)['run_uid'], 'test', 'Output.', 'text/plain', 'out.txt', time());

        $this->runTask($taskId);

        self::assertSame(AgentRunStatus::COMPLETED, $this->runOf($taskId)->statusEnum());
    }

    /**
     * A2A-OPS2: with files switched off nothing is downloaded; the path is
     * not the server's.
     */
    #[Test]
    public function withFilesSwitchedOffNothingIsDownloaded(): void
    {
        [, $fileId] = $this->completedWith([[str_repeat('v', 2000), 'text/plain', 'v.txt']]);
        $this->fileSettings(false);

        self::assertTrue(self::passedThrough($this->fetch(self::BASE . '/files/' . $fileId, 1)));
    }

    // ------------------------------------------------------------------

    /**
     * Accept a task, register $files against its run, and run it to
     * completion.
     *
     * @param list<array{string, string, string}> $files bytes, media type, filename
     *
     * @return list<string> the task id, then the file ids
     */
    private function completedWith(array $files): array
    {
        $taskId = self::text($this->taskOf($this->call('SendMessage', ['message' => $this->message('Write the report.'), 'configuration' => ['returnImmediately' => true]]))['id'] ?? '');
        $runUid = (int) $this->taskRow($taskId)['run_uid'];
        $ids    = [$taskId];
        foreach ($files as [$bytes, $mediaType, $filename]) {
            $ids[] = $this->getService(A2aFileStore::class)->storeOutboundForRun($runUid, 'test', $bytes, $mediaType, $filename, time());
        }

        $this->runTask($taskId);
        self::assertSame(AgentRunStatus::COMPLETED, $this->runOf($taskId)->statusEnum());

        return $ids;
    }

    /**
     * The part of the task's artifacts that carries a file.
     *
     * @return array<string, mixed>
     */
    private function filePart(string $taskId, string $fileId): array
    {
        $task = $this->taskOf($this->call('GetTask', ['id' => $taskId]));
        foreach (self::rowsOf($task['artifacts'] ?? null) as $artifact) {
            foreach (self::rowsOf($artifact['parts'] ?? null) as $part) {
                $metadata  = is_array($part['metadata'] ?? null) ? $part['metadata'] : [];
                $reference = is_array($metadata[A2aInboundFilePolicy::FILE_EXTENSION] ?? null) ? $metadata[A2aInboundFilePolicy::FILE_EXTENSION] : [];
                if (($reference['fileId'] ?? null) === $fileId) {
                    return $part;
                }
            }
        }

        self::fail('No artifact part carries the file ' . $fileId);
    }

    private function fetch(string $url, ?int $client): ResponseInterface
    {
        $headers = $client === null ? [] : ['Authorization' => 'Bearer ' . $this->tokens[$client]];

        return $this->middleware()->process($this->request('GET', $url, $headers), $this->passThrough());
    }

    private function postTo(string $url, int $client): ResponseInterface
    {
        return $this->middleware()->process($this->request('POST', $url, ['Authorization' => 'Bearer ' . $this->tokens[$client]]), $this->passThrough());
    }

    private function capabilityUrl(string $fileId, int $client): string
    {
        $response = $this->postTo(self::BASE . '/files/' . $fileId . '/capability', $client);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = self::objectOf(json_decode((string) $response->getBody(), true));
        self::assertIsString($body['expiresAt'] ?? null);

        return self::text($body['url'] ?? '');
    }

    private function tokenHashOf(int $client): string
    {
        return self::text($this->db('tx_nrllm_a2a_client')->select(['token_hash'], 'tx_nrllm_a2a_client', ['uid' => $client])->fetchOne());
    }

    private function fileSettings(?bool $enabled): void
    {
        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $a2a = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['a2a'] ?? [];
        $a2a = is_array($a2a) ? $a2a : [];
        if ($enabled === null) {
            unset($a2a['files'], $a2a['maxInlineArtifactBytes']);
        } else {
            $a2a['files']                  = ['enabled' => $enabled ? '1' : '0', 'retentionHours' => '1'];
            $a2a['maxInlineArtifactBytes'] = (string) self::INLINE;
        }

        // @phpstan-ignore-next-line $GLOBALS access returns mixed at each nesting level
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['a2a'] = $a2a;
    }

    /**
     * Run a task's queued run on a scripted model that answers at once, as
     * the worker runs it.
     */
    private function runTask(string $taskId): void
    {
        $registry = $this->getService(ToolRegistry::class);
        $policy   = $this->getService(ToolCallPolicyInterface::class);
        $manager  = self::createStub(LlmServiceManagerInterface::class);
        $answer   = static fn (): CompletionResponse => new CompletionResponse('Done.', 'fake', UsageStatistics::fromTokens(1, 1));
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback($answer);
        $manager->method('chatWithConfiguration')->willReturnCallback($answer);
        $loop      = new ToolLoopService($manager, $registry, $policy);
        $persister = $this->getService(AgentRunPersister::class);
        $runtime   = new AgentRuntime(
            $loop,
            $persister,
            $this->getService(LlmConfigurationRepository::class),
            new NullLogger(),
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($registry),
            runExecutor: new AgentRunExecutor(
                $loop,
                $persister,
                new NullLogger(),
                new ActingBackendUserResolver(),
                new ToolEffectResolver($registry),
                resumeGuards: [$this->getService(A2aResumeGuard::class)],
                startGuards: [$this->getService(A2aResumeGuard::class)],
                startPreparers: [$this->getService(A2aFileInputPreparer::class)],
            ),
            toolPolicy: $policy,
        );

        $runtime->runQueued($this->runOf($taskId)->uuid);
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(string $taskId): array
    {
        $row = $this->db(A2aTaskStore::TABLE)->select(['*'], A2aTaskStore::TABLE, ['task_id' => $taskId])->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function runOf(string $taskId): AgentRun
    {
        $uuid = $this->db('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $this->taskRow($taskId)['run_uid']])->fetchOne();
        self::assertIsString($uuid);
        $run = $this->getService(AgentRunPersister::class)->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }
}
